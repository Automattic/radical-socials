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
		$items = array_merge(
			Radical_Socials_WPCOM_Reader::fetch( 40 ),
			self::fetch_all_rss(),
			Radical_Socials_ActivityPub_Fetcher::fetch( 40 ),
		);

		self::upsert_batch( $items );

		self::prune_orphaned_items();
		self::enforce_cap();

		update_option( 'rs_last_feed_fetch', time(), false );
	}

	private static function fetch_all_rss(): array {
		$subs    = (array) get_option( 'rs_rss_subscriptions', [] );
		$items   = [];
		$updated = false;

		foreach ( $subs as &$sub ) {
			if ( ! wp_http_validate_url( $sub['url'] ) ) {
				continue;
			}
			$feed_items = Radical_Socials_RSS_Fetcher::fetch( $sub['url'], 20 );
			$items      = array_merge( $items, $feed_items );

			// Backfill empty title/source_url from the fetched channel data.
			if ( ! empty( $feed_items[0]['source_name'] ) ) {
				if ( empty( $sub['title'] ) ) {
					$sub['title'] = $feed_items[0]['source_name'];
					$updated      = true;
				}
				if ( empty( $sub['source_url'] ) && ! empty( $feed_items[0]['source_url'] ) ) {
					$sub['source_url'] = $feed_items[0]['source_url'];
					$updated           = true;
				}
			}
		}
		unset( $sub );

		if ( $updated ) {
			update_option( 'rs_rss_subscriptions', $subs, false );
		}

		return $items;
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
			'post_title'   => $item['title'] ?: __( '(untitled)', 'radical-socials' ),
			'post_content' => wp_kses( $item['content'] ?? $item['excerpt'] ?? '', self::kses_allowlist() ),
			'post_excerpt' => wp_strip_all_tags( $item['excerpt'] ?? '' ),
			'post_date'    => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $item['date'] ?? 'now' ) ) ),
			'meta_input'   => [
				'_rs_item_url'          => $item['url'] ?? '',
				'_rs_item_source_url'   => $item['source_url'] ?? '',
				'_rs_item_thumbnail'    => $item['thumbnail_url'] ?? '',
				'_rs_item_feed_type'    => $item['feed_type'] ?? 'rss',
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
			$source_name = $item['source_name'] ?: parse_url( $item['source_url'] ?? '', PHP_URL_HOST );
			if ( $source_name ) {
				wp_set_post_terms( $post_id, [ $source_name ], 'rs_source' );
			}
			if ( ! empty( $item['feed_type'] ) ) {
				wp_set_post_terms( $post_id, [ $item['feed_type'] ], 'rs_feed_type' );
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
			'video'  => array_merge( $common, [ 'src' => true, 'poster' => true, 'width' => true, 'height' => true ] ),
			'audio'  => array_merge( $common, [ 'src' => true ] ),
			'source' => [ 'src' => true, 'type' => true, 'srcset' => true, 'media' => true ],
		];
		return $list;
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
		$subs  = (array) get_option( 'rs_rss_subscriptions', [] );
		$known = array_values( array_filter( array_column( $subs, 'source_url' ) ) );

		if ( empty( $subs ) ) {
			// No subscriptions at all — every RSS item is an orphan.
			foreach ( self::get_item_ids_by_type( 'rss' ) as $id ) {
				wp_delete_post( (int) $id, true );
			}
			return;
		}

		if ( empty( $known ) ) {
			// Subscriptions exist but none have a source_url yet (e.g. first fetch
			// hasn't completed) — skip to avoid false positives.
			return;
		}

		$orphans = get_posts( [
			'post_type'      => 'rs_feed_item',
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => 9999,
			'meta_query'     => [
				'relation' => 'AND',
				[ 'key' => '_rs_item_feed_type', 'value' => 'rss' ],
				[ 'key' => '_rs_item_source_url', 'value' => $known, 'compare' => 'NOT IN' ],
				[ 'key' => '_rs_item_source_url', 'value' => '', 'compare' => '!=' ],
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

		$follows = \Activitypub\Collection\Following::get_many( 0 );
		// WP_Post objects — guid holds the actor URL.
		$known = array_column( (array) $follows, 'guid' );

		if ( empty( $known ) ) {
			foreach ( self::get_item_ids_by_type( 'activitypub' ) as $id ) {
				wp_delete_post( (int) $id, true );
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
