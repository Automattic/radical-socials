<?php
/**
 * Feed Fetcher
 *
 * Orchestrates all feed sources (WP.com Reader, RSS, ActivityPub), upserts
 * the results into the rs_feed_item CPT, and enforces the rolling item cap.
 *
 * Upsert key: post_name = md5(item URL) — prevents duplicates across runs.
 *
 * @package RadicalSocials
 */

defined( 'ABSPATH' ) || exit;

class Radical_Socials_Feed_Fetcher {

	public static function run(): void {
		if ( class_exists( 'Radical_Socials_Following' ) ) {
			if ( Radical_Socials_Following::REFRESH_LOCK_RUNNING === get_transient( Radical_Socials_Following::REFRESH_LOCK ) ) {
				return;
			}
			set_transient(
				Radical_Socials_Following::REFRESH_LOCK,
				Radical_Socials_Following::REFRESH_LOCK_RUNNING,
				Radical_Socials_Following::REFRESH_LOCK_TTL
			);
		}

		try {
			$items = array_merge(
				Radical_Socials_WPCOM_Reader::fetch( 40 ),
				self::fetch_all_rss(),
				Radical_Socials_ActivityPub_Fetcher::fetch( 40 ),
				Radical_Socials_ActivityPub_Fetcher::fetch_outboxes( 10 ),
			);

			self::upsert_batch( $items );

			self::prune_orphaned_items();
			self::enforce_cap();

			update_option( 'rs_last_feed_fetch', time(), false );
		} finally {
			if ( class_exists( 'Radical_Socials_Following' ) ) {
				delete_transient( Radical_Socials_Following::REFRESH_LOCK );
			}
		}
	}

	/**
	 * Per-run cap on the number of RSS feeds we hit. Chunking matters because
	 * each fetch is an outbound HTTP request and shared hosts (e.g. Hostinger)
	 * routinely kill PHP at 300s — processing 100+ feeds serially in one tick
	 * runs out of time, the process gets killed mid-fetch, and the refresh
	 * lock can end up stranded. AP outbox polling already chunks to 10/run
	 * via `rs_ap_outbox_offset`; we mirror that shape here.
	 */
	const RSS_FETCH_PER_RUN = 10;

	private static function fetch_all_rss(): array {
		$subs    = (array) get_option( 'rs_rss_subscriptions', [] );
		$items   = [];
		$updated = false;

		$total = count( $subs );
		if ( ! $total ) {
			return [];
		}

		// Rotate through the subscriptions over successive cron ticks. With
		// FETCH_INTERVAL = 15 min and RSS_FETCH_PER_RUN = 10, an account with
		// ~200 feeds cycles fully every ~5 hours, which matches the cadence
		// most social/news feeds publish at without saturating shared hosts.
		$offset = (int) get_option( 'rs_rss_fetch_offset', 0 );
		$offset = $total ? ( $offset % $total ) : 0;
		$slice  = array_slice( $subs, $offset, self::RSS_FETCH_PER_RUN );

		// Advance the offset *before* doing the work, so a PHP timeout
		// mid-batch still rotates us forward next run (avoids the same flaky
		// feed permanently blocking everything behind it).
		update_option( 'rs_rss_fetch_offset', ( $offset + count( $slice ) ) % $total, false );

		// Map slice indices back to the original $subs offsets so we can
		// write back title/source_url backfills correctly.
		$slice_keys = array_keys( $slice );
		foreach ( $slice_keys as $local_idx ) {
			$absolute_idx = $offset + $local_idx;
			$sub          = &$subs[ $absolute_idx ];

			if ( ! Radical_Socials_RSS_Fetcher::is_safe_remote_url( $sub['url'] ) ) {
				self::record_rss_health( $sub, [
					'status'    => 'failed',
					'error'     => __( 'URL is not a safe public address (private IP, localhost, or invalid format).', 'radical-socials' ),
					'elapsed_ms' => 0,
				] );
				$updated = true;
				unset( $sub );
				continue;
			}

			$start      = microtime( true );
			$feed_items = Radical_Socials_RSS_Fetcher::fetch( $sub['url'], 20 );
			$elapsed_ms = (int) round( ( microtime( true ) - $start ) * 1000 );
			$error      = Radical_Socials_RSS_Fetcher::last_error();

			// Decide health bucket. Failed if the fetcher reported an error;
			// slow if the fetch took longer than 3s (Hostinger-friendly
			// threshold — anything above eats too much of the 300s budget);
			// ok if items came back; "no items" is treated as ok with a note
			// because feeds can simply be quiet.
			if ( $error ) {
				$health = [ 'status' => 'failed', 'error' => $error, 'elapsed_ms' => $elapsed_ms ];
			} elseif ( $elapsed_ms > 3000 ) {
				$health = [ 'status' => 'slow', 'error' => '', 'elapsed_ms' => $elapsed_ms ];
			} else {
				$health = [ 'status' => 'ok', 'error' => '', 'elapsed_ms' => $elapsed_ms ];
			}
			self::record_rss_health( $sub, $health );
			$updated = true;

			// Stamp each item with the canonical subscription feed URL so the
			// prune step can match items back to their subscription reliably.
			// (source_url comes from SimplePie's get_permalink() — the feed's
			// channel <link> — and rarely matches what the OPML stored.)
			foreach ( $feed_items as &$fi ) {
				$fi['feed_url'] = $sub['url'];
				if ( ! empty( $sub['categories'] ) ) {
					$fi['feed_categories'] = $sub['categories'];
				}
			}
			unset( $fi );

			$items = array_merge( $items, $feed_items );

			// Backfill empty title/source_url from the fetched channel data.
			if ( ! empty( $feed_items[0]['source_name'] ) ) {
				if ( empty( $sub['title'] ) ) {
					$sub['title'] = $feed_items[0]['source_name'];
				}
				if ( empty( $sub['source_url'] ) && ! empty( $feed_items[0]['source_url'] ) ) {
					$sub['source_url'] = $feed_items[0]['source_url'];
				}
			}
			unset( $sub );
		}

		if ( $updated ) {
			update_option( 'rs_rss_subscriptions', $subs, false );
		}

		return $items;
	}

	/**
	 * Stamp an RSS subscription with health info derived from the most recent
	 * fetch. Mutates the subscription record in place so the caller's bulk
	 * write to rs_rss_subscriptions persists it.
	 *
	 * @param array<string, mixed> $sub  Subscription array (by-ref).
	 * @param array{status:string,error:string,elapsed_ms:int} $health
	 */
	private static function record_rss_health( array &$sub, array $health ): void {
		$prev = is_array( $sub['health'] ?? null ) ? $sub['health'] : [];

		$consecutive = (int) ( $prev['consecutive_failures'] ?? 0 );
		if ( 'failed' === $health['status'] ) {
			$consecutive++;
		} else {
			$consecutive = 0;
		}

		$last_success = (int) ( $prev['last_success'] ?? 0 );
		if ( in_array( $health['status'], [ 'ok', 'slow' ], true ) ) {
			$last_success = time();
		}

		$sub['health'] = [
			'status'                => $health['status'],
			'last_checked'          => time(),
			'last_success'          => $last_success,
			'last_error'            => $health['error'],
			'response_ms'           => $health['elapsed_ms'],
			'consecutive_failures'  => $consecutive,
		];
	}

	/**
	 * Insert or update a single feed item.
	 * Uses post_name = guid (md5 of URL) as the uniqueness key.
	 */
	public static function upsert_item( array $item ): void {
		self::upsert( $item );
	}

	/**
	 * Upsert many items with a single existence-check query instead of one per item.
	 */
	private static function upsert_batch( array $items ): void {
		if ( empty( $items ) ) {
			return;
		}

		// Build guid → item map (last writer wins for duplicates in the batch).
		$by_guid = [];
		foreach ( $items as $item ) {
			$guid            = $item['guid'] ?? md5( $item['url'] ?? uniqid() );
			$by_guid[ $guid ] = $item;
		}

		// Single query to find all existing post IDs by slug.
		global $wpdb;
		$guids        = array_keys( $by_guid );
		$placeholders = implode( ',', array_fill( 0, count( $guids ), '%s' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_name FROM {$wpdb->posts} WHERE post_type = 'rs_feed_item' AND post_name IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				...$guids
			)
		);
		$existing_map = array_column( $rows, 'ID', 'post_name' );

		foreach ( $by_guid as $guid => $item ) {
			self::upsert( $item, $existing_map[ $guid ] ?? null );
		}
	}

	private static function upsert( array $item, ?int $existing_id = null ): void {
		$guid = $item['guid'] ?? md5( $item['url'] ?? uniqid() );

		if ( null === $existing_id ) {
			$rows        = get_posts( [ 'post_type' => 'rs_feed_item', 'post_status' => 'any', 'name' => $guid, 'fields' => 'ids', 'numberposts' => 1 ] );
			$existing_id = $rows[0] ?? null;
		}

		$post_data = [
			'post_type'    => 'rs_feed_item',
			'post_status'  => 'publish',
			'post_name'    => $guid,
			// Leave the title empty when the source has no real title rather
			// than faking a localised "(untitled)" placeholder. The render
			// filter in Following::hide_empty_or_activitypub_titles() hides
			// the post-title block in that case.
			'post_title'   => (string) ( $item['title'] ?? '' ),
			'post_content' => wp_kses( $item['content'] ?? $item['excerpt'] ?? '', self::kses_allowlist() ),
			'post_excerpt' => wp_strip_all_tags( $item['excerpt'] ?? '' ),
			// Cap parsed date at "tomorrow" so a hostile feed publishing
			// far-future timestamps can't pin itself permanently at the
			// top of the timeline (or sort below items that arrive later
			// but are tagged a year ago, etc.). 24h slack accommodates
			// normal time-zone skew without letting abuse through.
			'post_date'    => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', min(
				strtotime( $item['date'] ?? 'now' ) ?: time(),
				time() + DAY_IN_SECONDS
			) ) ),
			'meta_input'   => [
				'_rs_item_url'          => $item['url'] ?? '',
				'_rs_item_source_url'   => $item['source_url'] ?? '',
				'_rs_item_feed_url'     => $item['feed_url'] ?? '',
				'_rs_item_thumbnail'    => $item['thumbnail_url'] ?? '',
				'_rs_item_feed_type'    => $item['feed_type'] ?? 'rss',
				'_rs_author_name'       => $item['author_name'] ?? '',
				'_rs_author_icon_url'   => $item['author_icon_url'] ?? '',
				'_rs_author_url'        => $item['author_url'] ?? '',
			],
		];

		if ( $existing_id ) {
			$post_data['ID'] = $existing_id;
			wp_update_post( $post_data );
			$post_id = $existing_id;
		} else {
			$post_id = wp_insert_post( $post_data );
		}

		if ( $post_id && ! is_wp_error( $post_id ) ) {
			// Assign source and type taxonomy terms.
			$source_name = $item['source_name'] ?: wp_parse_url( $item['source_url'] ?? '', PHP_URL_HOST );
			if ( $source_name ) {
				wp_set_post_terms( $post_id, [ $source_name ], 'rs_source' );
			}
			if ( ! empty( $item['feed_type'] ) ) {
				$term_id = self::resolve_feed_type_term( (string) $item['feed_type'] );
				if ( $term_id ) {
					wp_set_post_terms( $post_id, [ $term_id ], 'rs_feed_type' );
				}
			}
			if ( ! empty( $item['feed_categories'] ) ) {
				wp_set_post_terms( $post_id, (array) $item['feed_categories'], 'rs_feed_category' );
			}

			// Store thumbnail URL as featured image if we have one and no image yet.
			if ( ! empty( $item['thumbnail_url'] ) && ! get_post_thumbnail_id( $post_id ) ) {
				// Store URL only — avoids sideloading media on every cron run.
				update_post_meta( $post_id, '_rs_item_thumbnail', $item['thumbnail_url'] );
			}
		}
	}

	private static function kses_allowlist(): array {
		static $list = null;
		if ( null !== $list ) {
			return $list;
		}
		$common = [ 'class' => true, 'id' => true, 'dir' => true, 'lang' => true ];
		$list = [
			'p'          => $common, 'br' => [], 'hr' => $common,
			'span'       => $common, 'div' => $common,
			'h1' => $common, 'h2' => $common, 'h3' => $common,
			'h4' => $common, 'h5' => $common, 'h6' => $common,
			'strong' => $common, 'b'   => $common, 'em'  => $common, 'i' => $common,
			's'      => $common, 'del' => $common, 'ins' => $common, 'u' => $common,
			'sup'    => $common, 'sub' => $common,
			'abbr'   => array_merge( $common, [ 'title' => true ] ),
			'ul' => $common, 'ol' => $common, 'li' => $common,
			'dl' => $common, 'dt' => $common, 'dd' => $common,
			'a'  => array_merge( $common, [ 'href' => true, 'title' => true, 'target' => true, 'rel' => true ] ),
			'img' => array_merge( $common, [ 'src' => true, 'alt' => true, 'width' => true, 'height' => true, 'srcset' => true, 'sizes' => true ] ),
			'figure' => $common, 'figcaption' => $common,
			'blockquote' => array_merge( $common, [ 'cite' => true ] ),
			'pre' => $common, 'code' => $common, 'kbd' => $common,
			'table' => $common, 'thead' => $common, 'tbody' => $common, 'tfoot' => $common,
			'tr'   => $common,
			'th'   => array_merge( $common, [ 'scope' => true, 'colspan' => true, 'rowspan' => true ] ),
			'td'   => array_merge( $common, [ 'colspan' => true, 'rowspan' => true ] ),
			'video'  => array_merge( $common, [ 'src' => true, 'poster' => true, 'width' => true, 'height' => true, 'controls' => true, 'playsinline' => true, 'preload' => true, 'autoplay' => true, 'loop' => true, 'muted' => true ] ),
			'audio'  => array_merge( $common, [ 'src' => true, 'controls' => true, 'preload' => true, 'autoplay' => true, 'loop' => true, 'muted' => true ] ),
			'source' => [ 'src' => true, 'type' => true, 'srcset' => true, 'media' => true ],
		];
		return $list;
	}

	/**
	 * Display names for the three known rs_feed_type slugs. Brand names —
	 * intentionally not translated. The slug stays the machine identifier
	 * used everywhere else (URL filters, meta `_rs_item_feed_type`, CSS
	 * classes); only the human-readable label lives here.
	 */
	private const FEED_TYPE_NAMES = [
		'rss'         => 'RSS',
		'activitypub' => 'ActivityPub',
		'wpcom'       => 'WordPress.com',
	];

	/**
	 * Return the term_id for a given feed_type slug, creating the term with
	 * the proper display name if it doesn't exist. Heals terms created by
	 * an older version of this fetcher that left name = slug (lowercase).
	 */
	private static function resolve_feed_type_term( string $slug ): ?int {
		if ( '' === $slug ) {
			return null;
		}
		$desired_name = self::FEED_TYPE_NAMES[ $slug ] ?? $slug;
		$term         = get_term_by( 'slug', $slug, 'rs_feed_type' );

		if ( $term ) {
			if ( isset( self::FEED_TYPE_NAMES[ $slug ] ) && $term->name !== $desired_name ) {
				wp_update_term( $term->term_id, 'rs_feed_type', [ 'name' => $desired_name ] );
			}
			return (int) $term->term_id;
		}

		$inserted = wp_insert_term( $desired_name, 'rs_feed_type', [ 'slug' => $slug ] );
		if ( is_wp_error( $inserted ) ) {
			return null;
		}
		return (int) $inserted['term_id'];
	}

	/**
	 * Delete rs_feed_item posts whose source feed is no longer in the
	 * current subscription lists (RSS and ActivityPub).
	 * WP.com is skipped — pruning it would require an extra API call.
	 */
	private static function prune_orphaned_items(): void {
		self::prune_orphaned_rss();
		self::prune_orphaned_activitypub();
	}

	private static function prune_orphaned_rss(): void {
		$subs = (array) get_option( 'rs_rss_subscriptions', [] );

		// Safety: refuse to mass-delete stored items just because the
		// subscriptions option is empty. The branch was originally
		// reasoning "no subs → every item is an orphan", but it can't
		// tell that legitimate state apart from a transient bug (the
		// integration-test harness wiped this option twice and the
		// next cron tick deleted every cached RSS item before anyone
		// noticed). Bail and log; if the user genuinely cleared their
		// feed list and wants the items gone, that's a deliberate UI
		// action, not something cron should do for them.
		if ( empty( $subs ) ) {
			$existing = count( self::get_item_ids_by_type( 'rss' ) );
			if ( $existing > 0 ) {
				error_log( sprintf(
					'[Radical Socials] prune_orphaned_rss: refusing to delete %d RSS feed item(s) because rs_rss_subscriptions is empty. If this is intentional, clear them manually.',
					$existing
				) );
			}
			return;
		}

		// Match items against the subscription's xmlUrl (the canonical feed
		// URL we used to fetch them), not the channel <link>. SimplePie's
		// permalink is frequently different from the OPML's htmlUrl by
		// trailing slash / protocol / www, and a mismatch here causes every
		// freshly upserted item to be pruned as "orphaned" — that's what was
		// silently emptying the feed list on import.
		$known = array_values( array_filter( array_column( $subs, 'url' ) ) );
		if ( empty( $known ) ) {
			return;
		}

		// Items without the new _rs_item_feed_url meta (i.e. older items
		// upserted before this change) are excluded from orphan deletion so
		// the migration is non-destructive — they'll naturally rotate out via
		// enforce_cap as new items come in.
		$orphans = get_posts( [
			'post_type'      => 'rs_feed_item',
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => 9999,
			'meta_query'     => [
				'relation' => 'AND',
				[ 'key' => '_rs_item_feed_type', 'value' => 'rss' ],
				[ 'key' => '_rs_item_feed_url',  'value' => $known, 'compare' => 'NOT IN' ],
				[ 'key' => '_rs_item_feed_url',  'value' => '', 'compare' => '!=' ],
			],
		] );

		foreach ( $orphans as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}

	private static function prune_orphaned_activitypub(): void {
		if ( ! class_exists( 'Activitypub\Collection\Following' ) ) {
			return;
		}

		$uid     = (int) get_option( 'rs_ap_follow_user_id', 0 );
		if ( ! $uid ) {
			$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
			$uid    = $admins ? (int) $admins[0] : 0;
		}

		$follows = $uid ? \Activitypub\Collection\Following::query_all( $uid )['following'] : [];
		// WP_Post objects — guid holds the actor URL.
		$known = array_column( (array) $follows, 'guid' );

		// Same safety as the RSS branch: never auto-delete the cached
		// items just because the follow list is empty. That state can
		// be transient (e.g. AP plugin reloading, or the actor mode
		// being toggled mid-request) and a cron tick should not be
		// allowed to wipe content based on it.
		if ( empty( $known ) ) {
			$existing = count( self::get_item_ids_by_type( 'activitypub' ) );
			if ( $existing > 0 ) {
				error_log( sprintf(
					'[Radical Socials] prune_orphaned_activitypub: refusing to delete %d ActivityPub feed item(s) because the follow list is empty. If this is intentional, clear them manually.',
					$existing
				) );
			}
			return;
		}

		$orphans = get_posts( [
			'post_type'      => 'rs_feed_item',
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => 9999,
			'meta_query'     => [
				'relation' => 'AND',
				[ 'key' => '_rs_item_feed_type', 'value' => 'activitypub' ],
				[ 'key' => '_rs_item_source_url', 'value' => $known, 'compare' => 'NOT IN' ],
				[ 'key' => '_rs_item_source_url', 'value' => '', 'compare' => '!=' ],
			],
		] );

		foreach ( $orphans as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}

	private static function get_item_ids_by_type( string $type ): array {
		return get_posts( [
			'post_type'      => 'rs_feed_item',
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => 9999,
			'meta_query'     => [ [ 'key' => '_rs_item_feed_type', 'value' => $type ] ],
		] );
	}

	/**
	 * Delete the oldest items that exceed the MAX_ITEMS cap.
	 */
	public static function enforce_cap(): void {
		// posts_per_page => -1 ignores 'offset' in WordPress; use a large finite number.
		$excess = get_posts(
			[
				'post_type'      => 'rs_feed_item',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 9999,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'offset'         => Radical_Socials_Following::MAX_ITEMS,
			]
		);

		foreach ( $excess as $id ) {
			wp_delete_post( (int) $id, true ); // force-delete, no trash
		}
	}
}
