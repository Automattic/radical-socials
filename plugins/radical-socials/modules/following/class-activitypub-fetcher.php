<?php
/**
 * ActivityPub Fetcher
 *
 * Reads inbox posts stored by the ActivityPub plugin (by @pfefferle).
 * The plugin stores incoming activities as 'ap_inbox' CPT posts.
 * The full activity JSON lives in post_content; actor URL is in
 * _activitypub_activity_remote_actor meta.
 *
 * Two entry points:
 *  - fetch()              — bulk read recent Create activities (used on first load).
 *  - normalize_activity() — normalize a single ap_inbox post (used on push).
 *
 * @package RadicalSocials
 */

defined( 'ABSPATH' ) || exit;

class Radical_Socials_ActivityPub_Fetcher {

	const POST_TYPE = 'ap_inbox';
	const ACCEPT_HEADER = 'application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"';

	/**
	 * In-request cache for dereferenced AP objects (boost targets).
	 * Keyed by absolute URL. A `null` entry means the fetch was attempted
	 * and failed — we don't retry within the same request, since a boosted
	 * post is read-only and won't suddenly come back to life mid-run.
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	private static array $object_cache = [];

	/**
	 * Fetch up to $count recent Create or Announce activities from the
	 * ActivityPub inbox.
	 *
	 * @return array<int, array>
	 */
	public static function fetch( int $count = 40 ): array {
		if ( ! self::is_available() ) {
			return [];
		}

		$activities = get_posts(
			[
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => $count,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => [
					[
						'key'     => '_activitypub_activity_type',
						'value'   => [ 'Create', 'Announce' ],
						'compare' => 'IN',
					],
				],
			]
		);

		return array_values( array_filter( array_map(
			fn( WP_Post $a ) => self::normalize_activity( $a->ID ),
			$activities
		) ) );
	}

	/**
	 * Normalize a single ap_inbox post into a feed item array.
	 * Returns null if the activity lacks a usable object URL.
	 *
	 * @return array|null
	 */
	public static function normalize_activity( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}

		$actor_url     = (string) get_post_meta( $post_id, '_activitypub_activity_remote_actor', true );
		$object_id     = (string) get_post_meta( $post_id, '_activitypub_object_id', true );
		$activity_type = (string) get_post_meta( $post_id, '_activitypub_activity_type', true );
		$activity      = json_decode( $post->post_content, true );

		if ( 'Announce' === $activity_type ) {
			return self::normalize_announce(
				is_array( $activity ) ? ( $activity['object'] ?? null ) : null,
				$object_id,
				$actor_url,
				get_the_date( 'c', $post_id )
			);
		}

		// Create activity — the object is the new note itself.
		$object = is_array( $activity ) ? ( $activity['object'] ?? [] ) : [];

		if ( is_string( $object ) ) {
			$object_url = esc_url_raw( $object );
			$content    = '';
			$name       = '';
		} else {
			$object_url = esc_url_raw( $object['url'] ?? $object['id'] ?? $object_id ?? '' );
			$content    = $object['content'] ?? $object['summary'] ?? '';
			$name       = $object['name'] ?? '';
		}

		if ( ! $object_url ) {
			return null;
		}

		$image     = is_array( $object ) ? ( $object['image'] ?? '' ) : '';
		$thumbnail = is_array( $image ) ? esc_url_raw( $image['url'] ?? '' ) : esc_url_raw( (string) $image );

		$reply_context    = '';
		$attachments_html = '';
		if ( is_array( $object ) ) {
			$reply_context    = self::render_reply_context( $object );
			$attachments_html = self::render_attachments( $object['attachment'] ?? [] );
		}

		[ $author_name, $author_icon_url ] = self::lookup_author_by_actor_url( $actor_url );

		$published_iso = is_array( $object ) ? (string) ( $object['published'] ?? '' ) : '';
		$item_date     = $published_iso ?: get_the_date( 'c', $post_id );

		// Wrap the body + reply context + attachments inside a self-
		// contained card. Reply context lives at the top of the body
		// column (above the post text); attachments go at the bottom.
		$body_html = $reply_context . $content . $attachments_html;
		$wrapped   = self::render_card_wrapper(
			$author_name,
			$author_icon_url,
			esc_url_raw( $actor_url ),
			$body_html,
			'',
			$item_date
		);

		return [
			// AP notes don't have a meaningful title — only use object.name if
			// the remote actually set one (e.g. a syndicated blog post).
			'title'           => wp_strip_all_tags( (string) $name ),
			'url'             => $object_url,
			'content'         => wp_kses_post( $wrapped ),
			'excerpt'         => wp_trim_words( wp_strip_all_tags( $content ), 30 ),
			'date'            => $item_date,
			'source_name'     => self::actor_handle_from_url( $actor_url ),
			'source_url'      => esc_url_raw( $actor_url ),
			'thumbnail_url'   => $thumbnail,
			'guid'            => md5( $object_url ),
			'feed_type'       => 'activitypub',
			'author_name'     => $author_name,
			'author_icon_url' => $author_icon_url,
			'author_url'      => esc_url_raw( $actor_url ),
		];
	}

	/**
	 * Normalize an Announce (boost) into a feed item.
	 *
	 * The boosted note may be embedded as a full object inside the activity,
	 * or referenced by URL only. In the URL-only case we dereference it once
	 * per request via $object_cache so the same post boosted by several
	 * followed actors costs one HTTP call, not N.
	 *
	 * Feed-item attribution: source is the booster (so the existing prune
	 * logic — which matches _rs_item_source_url against the following list —
	 * doesn't sweep boosted items as orphans). The original author surfaces
	 * via the author_* fields and a "Boosted by" badge prepended to content.
	 *
	 * guid = md5(target object URL) so multiple boosters of the same post
	 * collapse to one upserted item.
	 *
	 * @param mixed  $activity_object Inline object dict, URL string, or null.
	 * @param string $object_id_meta  Fallback target URL from _activitypub_object_id.
	 * @param string $booster_url     Actor URL of the booster (we follow them).
	 * @param string $fallback_date   ISO-8601 date if the target lacks `published`.
	 */
	private static function normalize_announce( $activity_object, string $object_id_meta, string $booster_url, string $fallback_date ): ?array {
		if ( is_array( $activity_object ) ) {
			$target = $activity_object;
		} else {
			$target_url = is_string( $activity_object ) ? esc_url_raw( $activity_object ) : '';
			if ( ! $target_url && $object_id_meta ) {
				$target_url = esc_url_raw( $object_id_meta );
			}
			if ( ! $target_url ) {
				return null;
			}
			$target = self::dereference_object( $target_url );
			if ( ! $target ) {
				return null;
			}
		}

		$object_url = esc_url_raw( $target['url'] ?? $target['id'] ?? '' );
		if ( ! $object_url ) {
			return null;
		}

		$author_url      = is_string( $target['attributedTo'] ?? null )
			? esc_url_raw( $target['attributedTo'] )
			: '';
		$booster_display = self::actor_handle_from_url( $booster_url );

		$body_html        = (string) ( $target['content'] ?? $target['summary'] ?? '' );
		$boost_html       = self::render_boost_context( $booster_url, $booster_display );
		$reply_context    = self::render_reply_context( $target );
		$attachments_html = self::render_attachments( $target['attachment'] ?? [] );

		// `resolve_author_profile` falls back to fetching the actor JSON
		// over HTTP when the original author isn't in our local ap_actor
		// cache (which is the common case for boosts, since the booster
		// is what we follow, not the original author).
		[ $author_name, $author_icon_url ] = self::resolve_author_profile( $author_url );

		$published = (string) ( $target['published'] ?? '' );
		$item_date = $published ?: $fallback_date;

		// Body column: reply context (above the post text) + original
		// content + attachments. Boost line goes at the top of the card,
		// spanning both columns.
		$body_inner = $reply_context . $body_html . $attachments_html;
		$wrapped    = self::render_card_wrapper(
			$author_name ?: self::actor_handle_from_url( $author_url ),
			$author_icon_url,
			$author_url,
			$body_inner,
			$boost_html,
			$item_date
		);

		return [
			'title'           => wp_strip_all_tags( (string) ( $target['name'] ?? '' ) ),
			'url'             => $object_url,
			'content'         => wp_kses_post( $wrapped ),
			'excerpt'         => wp_trim_words( wp_strip_all_tags( $body_html ), 30 ),
			'date'            => $item_date,
			// source = booster so prune keeps the item alive while we follow them.
			'source_name'     => $booster_display,
			'source_url'      => esc_url_raw( $booster_url ),
			'thumbnail_url'   => '',
			'guid'            => md5( $object_url ),
			'feed_type'       => 'activitypub',
			// author = original poster.
			'author_name'     => $author_name ?: self::actor_handle_from_url( $author_url ),
			'author_icon_url' => $author_icon_url,
			'author_url'      => $author_url,
		];
	}

	/**
	 * Actively poll the outbox of every followed ActivityPub actor and return
	 * normalized feed items. Used as a fallback since push delivery requires a
	 * publicly accessible inbox.
	 *
	 * @return array<int, array>
	 */
	public static function fetch_outboxes( int $per_actor = 10 ): array {
		if ( ! class_exists( 'Activitypub\Collection\Following' ) ) {
			return [];
		}

		// In Blog Mode the option may legitimately be 0 (= BLOG_USER_ID),
		// so distinguish "unset" from "set to zero" before falling back.
		// get_option() returns the second arg's value when the option
		// doesn't exist, so a sentinel `false` is the safe "unset" check
		// here (a stored value is always an int from update_option's
		// integer cast on lines we control). The fallback resolves to
		// whichever actor the current site uses for AP — same source of
		// truth as add_activitypub / unfollow / the REST list path, so
		// the four code paths can't drift.
		$stored  = get_option( 'rs_ap_follow_user_id', false );
		$user_id = false === $stored
			? self::ap_actor_id()
			: (int) $stored;

		$all_actors = \Activitypub\Collection\Following::query_all( $user_id )['following'];
		$total      = count( $all_actors );
		$items      = [];

		if ( ! $total ) {
			return [];
		}

		// Poll up to 10 actors per run, rotating through all of them so every
		// actor gets polled eventually without making the run too slow.
		$offset = (int) get_option( 'rs_ap_outbox_offset', 0 );
		$offset = $offset % $total;
		$actors = array_slice( $all_actors, $offset, 10 );
		update_option( 'rs_ap_outbox_offset', ( $offset + count( $actors ) ) % $total, false );

		foreach ( $actors as $post ) {
			$actor_url     = $post->guid;
			$outbox_url    = get_post_meta( $post->ID, '_rs_outbox_url', true );
			$icon_url      = get_post_meta( $post->ID, '_rs_actor_icon_url', true );
			$display       = get_post_meta( $post->ID, '_rs_actor_display_name', true );
			$poll_started  = microtime( true );
			$poll_error    = '';

			// Fetch actor JSON if we're missing the outbox URL, or to backfill
			// the icon/display-name fields when we have an old cached actor.
			if ( ! $outbox_url || ! $icon_url || '' === $display ) {
				$actor_json = self::fetch_json( $actor_url );
				if ( $actor_json ) {
					if ( ! $outbox_url && ! empty( $actor_json['outbox'] ) ) {
						$candidate_outbox_url = esc_url_raw( (string) $actor_json['outbox'] );
						if ( self::is_safe_remote_url( $candidate_outbox_url ) ) {
							$outbox_url = $candidate_outbox_url;
							update_post_meta( $post->ID, '_rs_outbox_url', $outbox_url );
						}
					}
					$new_icon = isset( $actor_json['icon']['url'] )
						? esc_url_raw( $actor_json['icon']['url'] )
						: '';
					if ( $new_icon && $new_icon !== $icon_url ) {
						$icon_url = $new_icon;
						update_post_meta( $post->ID, '_rs_actor_icon_url', $icon_url );
					}
					$new_display = isset( $actor_json['name'] )
						? wp_strip_all_tags( (string) $actor_json['name'] )
						: '';
					if ( $new_display && $new_display !== $display ) {
						$display = $new_display;
						update_post_meta( $post->ID, '_rs_actor_display_name', $display );
					}
				}
			}

			if ( ! $outbox_url ) {
				self::record_actor_health( $post->ID, [
					'status'     => 'failed',
					'error'      => __( 'Could not discover the actor\'s outbox URL (account may not exist on this server).', 'radical-socials' ),
					'elapsed_ms' => (int) round( ( microtime( true ) - $poll_started ) * 1000 ),
				] );
				continue;
			}

			$page = self::fetch_outbox_page( $outbox_url );
			if ( ! $page ) {
				self::record_actor_health( $post->ID, [
					'status'     => 'failed',
					'error'      => __( 'The remote server returned an empty or malformed outbox page.', 'radical-socials' ),
					'elapsed_ms' => (int) round( ( microtime( true ) - $poll_started ) * 1000 ),
				] );
				continue;
			}

			$author = [
				'name'     => $display,
				'icon_url' => $icon_url,
			];

			$activities = $page['orderedItems'] ?? [];
			foreach ( array_slice( $activities, 0, $per_actor ) as $activity ) {
				$type = $activity['type'] ?? '';
				if ( 'Create' === $type ) {
					$item = self::normalize_outbox_activity( $activity, $actor_url, $author );
				} elseif ( 'Announce' === $type ) {
					$item = self::normalize_outbox_announce( $activity, $actor_url );
				} else {
					continue;
				}
				if ( $item ) {
					$items[] = $item;
				}
			}

			// Successful poll. Bucket "slow" if it took >3s.
			$elapsed_ms = (int) round( ( microtime( true ) - $poll_started ) * 1000 );
			self::record_actor_health( $post->ID, [
				'status'     => $elapsed_ms > 3000 ? 'slow' : 'ok',
				'error'      => '',
				'elapsed_ms' => $elapsed_ms,
			] );
		}

		return $items;
	}

	/**
	 * Persist outbox-poll health onto the ap_actor post. Stored as a single
	 * serialized meta key (_rs_health) instead of one key per field so each
	 * actor's health update is exactly one DB write, not six. Mirrors the
	 * shape Feed_Fetcher::record_rss_health() writes for RSS subscriptions
	 * so the REST list endpoint can expose both via the same JSON envelope.
	 *
	 * @param array{status:string,error:string,elapsed_ms:int} $health
	 */
	private static function record_actor_health( int $post_id, array $health ): void {
		$prev = get_post_meta( $post_id, '_rs_health', true );
		$prev = is_array( $prev ) ? $prev : [];

		$consecutive = 'failed' === $health['status']
			? (int) ( $prev['consecutive_failures'] ?? 0 ) + 1
			: 0;
		$last_success = in_array( $health['status'], [ 'ok', 'slow' ], true )
			? time()
			: (int) ( $prev['last_success'] ?? 0 );

		update_post_meta( $post_id, '_rs_health', [
			'status'                => $health['status'],
			'last_checked'          => time(),
			'last_success'          => $last_success,
			'last_error'            => $health['error'],
			'response_ms'           => (int) $health['elapsed_ms'],
			'consecutive_failures'  => $consecutive,
		] );
	}

	private static function fetch_outbox_page( string $outbox_url ): ?array {
		// Mastodon and most implementations return a Collection with a `first` URL.
		$collection = self::fetch_json( $outbox_url );
		if ( ! $collection ) {
			return null;
		}

		if ( ! empty( $collection['orderedItems'] ) ) {
			return $collection;
		}

		// Follow `first` link to get the actual page with items.
		$first = $collection['first'] ?? null;
		if ( ! $first ) {
			return null;
		}

		$first_url = is_array( $first ) ? ( $first['id'] ?? '' ) : $first;
		if ( ! $first_url ) {
			return null;
		}

		return self::fetch_json( $first_url );
	}

	private static function fetch_json( string $url ): ?array {
		if ( ! self::is_safe_remote_url( $url ) ) {
			return null;
		}

		$response = wp_safe_remote_get( $url, self::http_args() );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : null;
	}

	public static function is_safe_remote_url( string $url ): bool {
		return Radical_Socials_RSS_Fetcher::is_safe_remote_url( $url );
	}

	public static function http_args( int $timeout = 10 ): array {
		return [
			'timeout'            => $timeout,
			'redirection'        => Radical_Socials_RSS_Fetcher::HTTP_REDIRECTION,
			'reject_unsafe_urls' => true,
			'headers'           => [
				'Accept' => self::ACCEPT_HEADER,
			],
		];
	}

	private static function normalize_outbox_activity( array $activity, string $actor_url, array $author = [] ): ?array {
		$object = $activity['object'] ?? [];
		if ( is_string( $object ) ) {
			return null;
		}

		$object_url = esc_url_raw( $object['url'] ?? $object['id'] ?? '' );
		if ( ! $object_url ) {
			return null;
		}

		$content = $object['content'] ?? $object['summary'] ?? '';
		$name    = $object['name'] ?? '';

		$actor_name = wp_parse_url( $actor_url, PHP_URL_HOST ) ?? '';
		$actor_path = ltrim( wp_parse_url( $actor_url, PHP_URL_PATH ) ?? '', '/' );
		if ( $actor_path ) {
			$actor_name = $actor_path . '@' . $actor_name;
		}

		$image     = $object['image'] ?? '';
		$thumbnail = is_array( $image ) ? esc_url_raw( $image['url'] ?? '' ) : esc_url_raw( (string) $image );

		$published = $object['published'] ?? $activity['published'] ?? '';
		$item_date = $published ?: current_time( 'c' );

		$reply_context    = self::render_reply_context( $object );
		$attachments_html = self::render_attachments( $object['attachment'] ?? [] );

		// Wrap body + reply context + attachments inside the self-
		// contained card. Same shape as every other AP path so the
		// markup stays uniform across inbox/outbox + Create/Announce.
		$body_inner = $reply_context . $content . $attachments_html;
		$wrapped    = self::render_card_wrapper(
			(string) ( $author['name'] ?? '' ) ?: $actor_name,
			(string) ( $author['icon_url'] ?? '' ),
			esc_url_raw( $actor_url ),
			$body_inner,
			'',
			$item_date
		);

		return [
			// AP notes don't have a meaningful title — only use object.name if
			// the remote actually set one (e.g. a syndicated blog post).
			'title'           => wp_strip_all_tags( (string) $name ),
			'url'             => $object_url,
			'content'         => wp_kses_post( $wrapped ),
			'excerpt'         => wp_trim_words( wp_strip_all_tags( $content ), 30 ),
			'date'            => $item_date,
			'source_name'     => $actor_name,
			'source_url'      => esc_url_raw( $actor_url ),
			'thumbnail_url'   => $thumbnail,
			'guid'            => md5( $object_url ),
			'feed_type'       => 'activitypub',
			'author_name'     => $author['name'] ?? '',
			'author_icon_url' => $author['icon_url'] ?? '',
			'author_url'      => esc_url_raw( $actor_url ),
		];
	}

	/**
	 * Normalize an outbox Announce (boost). Dereferences the target object
	 * once per request via $object_cache so multiple followed actors boosting
	 * the same note share a single HTTP call.
	 *
	 * @param array  $activity   The Announce activity from the outbox.
	 * @param string $actor_url  Actor URL of the booster (we follow them).
	 */
	private static function normalize_outbox_announce( array $activity, string $actor_url ): ?array {
		$object = $activity['object'] ?? null;

		if ( is_array( $object ) ) {
			$target = $object;
		} else {
			$target_url = is_string( $object ) ? esc_url_raw( $object ) : '';
			if ( ! $target_url ) {
				return null;
			}
			$target = self::dereference_object( $target_url );
			if ( ! $target ) {
				return null;
			}
		}

		$object_url = esc_url_raw( $target['url'] ?? $target['id'] ?? '' );
		if ( ! $object_url ) {
			return null;
		}

		$author_url      = is_string( $target['attributedTo'] ?? null )
			? esc_url_raw( $target['attributedTo'] )
			: '';
		$booster_display = self::actor_handle_from_url( $actor_url );

		$body_html        = (string) ( $target['content'] ?? $target['summary'] ?? '' );
		$boost_html       = self::render_boost_context( $actor_url, $booster_display );
		$reply_context    = self::render_reply_context( $target );
		$attachments_html = self::render_attachments( $target['attachment'] ?? [] );

		$published = (string) ( $target['published'] ?? $activity['published'] ?? '' );
		$item_date = $published ?: current_time( 'c' );

		// Same fetch-fallback as the inbox path — we typically don't
		// follow the original author of a boosted note, so the local
		// ap_actor cache will miss and we need the actor JSON to get a
		// display name + avatar URL.
		[ $author_name, $author_icon_url ] = self::resolve_author_profile( $author_url );

		$body_inner = $reply_context . $body_html . $attachments_html;
		$wrapped    = self::render_card_wrapper(
			$author_name ?: self::actor_handle_from_url( $author_url ),
			$author_icon_url,
			$author_url,
			$body_inner,
			$boost_html,
			$item_date
		);

		return [
			'title'           => wp_strip_all_tags( (string) ( $target['name'] ?? '' ) ),
			'url'             => $object_url,
			'content'         => wp_kses_post( $wrapped ),
			'excerpt'         => wp_trim_words( wp_strip_all_tags( $body_html ), 30 ),
			'date'            => $item_date,
			'source_name'     => $booster_display,
			'source_url'      => esc_url_raw( $actor_url ),
			'thumbnail_url'   => '',
			'guid'            => md5( $object_url ),
			'feed_type'       => 'activitypub',
			'author_name'     => $author_name ?: self::actor_handle_from_url( $author_url ),
			'author_icon_url' => $author_icon_url,
			'author_url'      => $author_url,
		];
	}

	/**
	 * Fetch an AP object by URL, caching the result (success or failure)
	 * for the lifetime of the request. A boosted note is read-only, so the
	 * same target URL is never refetched within a single fetcher run.
	 */
	private static function dereference_object( string $url ): ?array {
		if ( array_key_exists( $url, self::$object_cache ) ) {
			return self::$object_cache[ $url ];
		}
		$data = self::fetch_json( $url );
		self::$object_cache[ $url ] = $data;
		return $data;
	}

	/**
	 * Wrap an item's body HTML in a self-contained social-card layout.
	 *
	 * Everything that describes the item — avatar, display name, the
	 * post body, the optional "Boosted by …" line, reply context and
	 * media attachments — gets baked into one `<div class="rs-ap-card">`
	 * structure inside the item's content. This lets the entire visual
	 * card live inside `post_content`, so a theme template that reorders
	 * or removes outer blocks (post title, post date, our author blocks)
	 * doesn't affect the card. The companion CSS (in the plugin's
	 * following.css) hides those outer blocks for AP items as siblings.
	 *
	 * Structure:
	 *   <div class="rs-ap-card">
	 *     <p class="rs-boost-context">…</p>     (only for boosts)
	 *     <img class="rs-ap-avatar" …/>
	 *     <div class="rs-ap-name"><a>…</a></div>
	 *     <div class="rs-ap-body">
	 *       <p class="rs-reply-context">…</p>   (when it's a reply)
	 *       [post body paragraphs]
	 *       [attachments]
	 *     </div>
	 *   </div>
	 *
	 * CSS lays out boost (full-width row 1), avatar (col 1 rows 2-3),
	 * name (col 2 row 2), body (col 2 row 3) via grid-template-areas.
	 */
	public static function render_card_wrapper(
		string $author_name,
		string $author_icon_url,
		string $author_url,
		string $body_html,
		string $boost_html = '',
		string $published_iso = ''
	): string {
		$avatar_html = '';
		if ( '' !== $author_icon_url ) {
			// The avatar is wrapped in a <div> rather than emitted as a
			// bare <img>, because `wpautop` (which runs on `the_content`)
			// wraps loose <img> tags in <p>. That extra <p> becomes a
			// direct grid child of `.rs-ap-card`, has no grid-area, and
			// auto-places itself into the first free cell — pushing the
			// real layout around and leaving the avatar cell empty. A
			// <div> is a block element, so wpautop leaves it alone.
			$avatar_html = sprintf(
				'<div class="rs-ap-avatar"><img src="%s" alt="" loading="lazy" decoding="async" /></div>',
				esc_url( $author_icon_url )
			);
		}

		$handle = self::actor_handle_from_url( $author_url );

		// Build the name row: bold display name, muted "@user@host" handle,
		// muted relative time. If we have no display name, the handle takes
		// its place and the standalone handle span is suppressed so we don't
		// print the same string twice.
		$display_text = $author_name !== '' ? $author_name : $handle;
		$parts        = [];

		if ( '' !== $display_text ) {
			$inner = '' !== $author_url
				? sprintf(
					'<a href="%s" target="_blank" rel="noopener noreferrer nofollow">%s</a>',
					esc_url( $author_url ),
					esc_html( $display_text )
				)
				: esc_html( $display_text );
			$parts[] = sprintf( '<span class="rs-ap-displayname">%s</span>', $inner );
		}

		if ( '' !== $author_name && '' !== $handle ) {
			$parts[] = sprintf( '<span class="rs-ap-handle">@%s</span>', esc_html( $handle ) );
		}

		$time_html = self::render_relative_time( $published_iso );
		if ( '' !== $time_html ) {
			$parts[] = $time_html;
		}

		$name_html = $parts
			? sprintf( '<div class="rs-ap-name">%s</div>', implode( '', $parts ) )
			: '';

		return sprintf(
			'<div class="rs-ap-card">%s%s%s<div class="rs-ap-body">%s</div></div>',
			$boost_html,
			$avatar_html,
			$name_html,
			self::clean_body_html( $body_html )
		);
	}

	/**
	 * Format a published ISO-8601 timestamp as "5 mins ago" (uses WP's
	 * i18n-aware human_time_diff). Returns an empty string for missing or
	 * unparseable input — the card layout still works without a time.
	 */
	private static function render_relative_time( string $iso ): string {
		if ( '' === $iso ) {
			return '';
		}
		$ts = strtotime( $iso );
		if ( ! $ts ) {
			return '';
		}
		$label = sprintf(
			/* translators: %s: human-readable time difference (e.g. "5 mins") */
			__( '%s ago', 'radical-socials' ),
			human_time_diff( $ts, time() )
		);
		return sprintf(
			'<time class="rs-ap-time" datetime="%s">%s</time>',
			esc_attr( gmdate( 'c', $ts ) ),
			esc_html( $label )
		);
	}

	/**
	 * Tidy the AP body HTML before it lands in the card. Remote AP servers
	 * frequently emit `<p></p>` placeholders and `<p><figure>…</figure></p>`
	 * patterns; the former leaves visible vertical gaps, the latter is
	 * invalid (block-level inside <p>) and renders unevenly across browsers
	 * because the parser auto-closes the <p> before the <figure>.
	 *
	 * Two passes:
	 *   1. Unwrap any `<p[…]><figure[…]>…</figure></p>` → `<figure[…]>…</figure>`
	 *   2. Drop any `<p[…]>\s*</p>` left behind (or already present).
	 *
	 * Operates on the composed body — reply context, post HTML, attachments —
	 * so attachment figures are always emitted as top-level block children
	 * of `.rs-ap-body`.
	 */
	private static function clean_body_html( string $html ): string {
		if ( '' === $html ) {
			return $html;
		}
		$html = preg_replace(
			'~<p\b[^>]*>\s*(<figure\b[^>]*>.*?</figure>)\s*</p>~is',
			'$1',
			$html
		);
		$html = preg_replace(
			'~<p\b[^>]*>(?:\s|&nbsp;|<br\s*/?>)*</p>~i',
			'',
			$html
		);
		return $html;
	}

	/**
	 * Build a "user@host" handle from an actor URL. Strips the two common
	 * Mastodon path shapes so we end up with a clean handle on either:
	 *   - https://mastodon.social/@alice  → alice@mastodon.social
	 *   - https://mastodon.social/users/alice → alice@mastodon.social
	 *
	 * Mirrors the legacy inline logic that was duplicated in both inbox
	 * and outbox normalizers.
	 */
	private static function actor_handle_from_url( string $actor_url ): string {
		$host = wp_parse_url( $actor_url, PHP_URL_HOST ) ?? '';
		$path = ltrim( wp_parse_url( $actor_url, PHP_URL_PATH ) ?? '', '/' );
		if ( '' === $path ) {
			return $host;
		}
		// Mastodon: /@user form.
		$path = ltrim( $path, '@' );
		// Mastodon: /users/user form.
		$path = preg_replace( '~^users/~', '', $path );
		// Take only the first path segment — anything past the actor segment
		// (statuses/123, replies, …) doesn't belong in a handle.
		$path = strtok( $path, '/' );
		return ( $path && $host ) ? $path . '@' . $host : ( $path ?: $host );
	}

	/**
	 * Render a "Boosted by @handle" badge prepended to a boosted note's
	 * content. Plain text only — no unicode arrow glyph, because WP's
	 * client-side emoji JS rewrites a number of arrow characters as <img>
	 * tags pointing at s.w.org. The visual icon, if any, should come from
	 * CSS (::before on .rs-boost-context) so it can't be substituted.
	 */
	private static function render_boost_context( string $booster_actor_url, string $booster_display ): string {
		if ( '' === $booster_actor_url ) {
			return '';
		}
		$label = '' !== $booster_display
			? sprintf(
				/* translators: %s: actor handle that boosted the post (e.g. @bob@mastodon.social) */
				__( 'Boosted by %s', 'radical-socials' ),
				'<code>@' . esc_html( $booster_display ) . '</code>'
			)
			: esc_html__( 'Boosted', 'radical-socials' );

		return sprintf(
			'<p class="rs-boost-context"><a href="%s" target="_blank" rel="noopener noreferrer nofollow">%s</a></p>',
			esc_url( $booster_actor_url ),
			$label
		);
	}

	/**
	 * Find the ap_actor post for a given actor URL (matched against the
	 * post's guid) and return [display_name, icon_url].
	 *
	 * @return array{0:string,1:string}
	 */
	private static function lookup_author_by_actor_url( string $actor_url ): array {
		if ( ! $actor_url ) {
			return [ '', '' ];
		}
		global $wpdb;
		$actor_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'ap_actor' AND guid = %s LIMIT 1",
			$actor_url
		) );
		if ( ! $actor_id ) {
			return [ '', '' ];
		}
		return [
			(string) get_post_meta( $actor_id, '_rs_actor_display_name', true ),
			(string) get_post_meta( $actor_id, '_rs_actor_icon_url', true ),
		];
	}

	/**
	 * Resolve an actor's display name + avatar URL, falling back to a
	 * one-shot HTTP fetch of the actor JSON when the local ap_actor
	 * cache has no record (a boost's original author is almost never
	 * one of our followed actors, so the local cache misses by default).
	 *
	 * Uses `dereference_object()` which is request-scope-cached, so the
	 * same actor across N boosts in one fetcher run only costs one HTTP.
	 * Result is also cached locally as a transient (6 h) so subsequent
	 * runs don't re-fetch — boosts of the same author by different
	 * boosters across hours don't pile up extra requests.
	 *
	 * @param string $actor_url Actor URL (typically `attributedTo` of a boost target).
	 * @return array{0:string,1:string} [ display_name, icon_url ]
	 */
	private static function resolve_author_profile( string $actor_url ): array {
		if ( '' === $actor_url ) {
			return [ '', '' ];
		}

		// 1. Local ap_actor cache (free).
		[ $name, $icon ] = self::lookup_author_by_actor_url( $actor_url );
		if ( $name && $icon ) {
			return [ $name, $icon ];
		}

		// 2. Cross-run transient — boosts dereference the same authors
		// repeatedly, so a few hours of caching saves real bandwidth.
		$transient_key = 'rs_ap_author_' . md5( $actor_url );
		$cached        = get_transient( $transient_key );
		if ( is_array( $cached ) && isset( $cached[0], $cached[1] ) ) {
			return [ (string) $cached[0], (string) $cached[1] ];
		}

		// 3. Last resort: fetch the actor JSON once and remember the result.
		$actor_json = self::dereference_object( $actor_url );
		if ( ! is_array( $actor_json ) ) {
			set_transient( $transient_key, [ '', '' ], 30 * MINUTE_IN_SECONDS );
			return [ '', '' ];
		}

		$fetched_name = isset( $actor_json['name'] )
			? wp_strip_all_tags( (string) $actor_json['name'] )
			: '';
		$fetched_icon = '';
		if ( isset( $actor_json['icon']['url'] ) ) {
			$fetched_icon = esc_url_raw( (string) $actor_json['icon']['url'] );
		} elseif ( isset( $actor_json['icon'] ) && is_string( $actor_json['icon'] ) ) {
			$fetched_icon = esc_url_raw( $actor_json['icon'] );
		}

		// Prefer cached name/icon over locally-known empty values.
		$name = $name ?: $fetched_name;
		$icon = $icon ?: $fetched_icon;

		set_transient( $transient_key, [ $name, $icon ], 6 * HOUR_IN_SECONDS );
		return [ $name, $icon ];
	}

	/**
	 * Render an "↩ In reply to @handle" line for posts that have an
	 * inReplyTo. We don't fetch the parent (that'd be one extra HTTP request
	 * per reply, sometimes many — see threading discussion); we just label
	 * the post and link to the original on the remote server so readers
	 * have somewhere to go for context.
	 *
	 * The display handle comes from the first Mention in `tag[]`, which is
	 * the AP convention for "this is who I'm replying to". Falls back to
	 * parsing the parent URL's host/path, and finally to a generic label.
	 */
	private static function render_reply_context( array $object ): string {
		$in_reply_to = $object['inReplyTo'] ?? '';
		if ( ! is_string( $in_reply_to ) || '' === $in_reply_to ) {
			return '';
		}
		$parent_url = esc_url_raw( $in_reply_to );
		if ( ! $parent_url ) {
			return '';
		}

		// Look for the first Mention tag — usually the author of the parent.
		$display = '';
		foreach ( (array) ( $object['tag'] ?? [] ) as $tag ) {
			if ( ! is_array( $tag ) ) {
				continue;
			}
			if ( ( $tag['type'] ?? '' ) === 'Mention' && ! empty( $tag['name'] ) ) {
				$display = wp_strip_all_tags( (string) $tag['name'] );
				break;
			}
		}

		// Fallback: parse "user@host" from the parent URL (Mastodon-style).
		if ( '' === $display ) {
			$host = wp_parse_url( $parent_url, PHP_URL_HOST ) ?: '';
			$path = ltrim( wp_parse_url( $parent_url, PHP_URL_PATH ) ?: '', '/' );
			if ( $host && preg_match( '~^(?:@|users/)([^/]+)~', $path, $m ) ) {
				$display = '@' . $m[1] . '@' . $host;
			}
		}

		$label = '' !== $display
			? sprintf(
				/* translators: %s: actor handle the post replies to (e.g. @bob@mastodon.social) */
				__( '↪️ In reply to %s', 'radical-socials' ),
				'<code>' . esc_html( $display ) . '</code>'
			)
			: esc_html__( '↪️ In reply to a post', 'radical-socials' );

		return sprintf(
			'<p class="rs-reply-context"><a href="%s" target="_blank" rel="noopener noreferrer nofollow">%s</a></p>',
			esc_url( $parent_url ),
			$label // already escaped above; sprintf placeholder is %s
		);
	}

	/**
	 * Render an AP attachment array as core/image, core/video, or core/audio
	 * block-equivalent HTML. Block comments are skipped — these posts are
	 * display-only, so we only need the rendered markup.
	 */
	private static function render_attachments( $attachments ): string {
		if ( ! is_array( $attachments ) || empty( $attachments ) ) {
			return '';
		}

		$html = '';
		foreach ( $attachments as $att ) {
			if ( ! is_array( $att ) ) {
				continue;
			}
			$url = esc_url_raw( $att['url'] ?? '' );
			if ( ! $url ) {
				continue;
			}
			$mime = strtolower( (string) ( $att['mediaType'] ?? '' ) );
			$alt  = wp_strip_all_tags( (string) ( $att['name'] ?? '' ) );

			if ( str_starts_with( $mime, 'image/' ) ) {
				$html .= sprintf(
					'<figure class="wp-block-image size-large"><img src="%s" alt="%s"/></figure>',
					esc_url( $url ),
					esc_attr( $alt )
				);
			} elseif ( str_starts_with( $mime, 'video/' ) ) {
				$html .= sprintf(
					'<figure class="wp-block-video"><video controls playsinline src="%s"></video></figure>',
					esc_url( $url )
				);
			} elseif ( str_starts_with( $mime, 'audio/' ) ) {
				$html .= sprintf(
					'<figure class="wp-block-audio"><audio controls src="%s"></audio></figure>',
					esc_url( $url )
				);
			}
		}

		return $html;
	}

	public static function is_available(): bool {
		return post_type_exists( self::POST_TYPE );
	}

	/**
	 * The ActivityPub actor we act as for follow / unfollow / list / poll
	 * operations.
	 *
	 * The AP plugin supports two relevant modes:
	 *   - Blog Mode: a single site-wide actor; follows are tracked under
	 *     `Actors::BLOG_USER_ID` (= 0), and individual WP users have no
	 *     actor of their own (`user_can_activitypub( $real_uid )` is
	 *     false). Passing a real user id while in Blog Mode is what
	 *     produces `activitypub_user_not_found` — the AP plugin can't
	 *     find a local actor for that uid.
	 *   - User Mode (and Blog+User combined): per-user actors; the
	 *     logged-in user_id is the right thing to pass.
	 *
	 * This helper is the single source of truth so the four callers
	 * (`add_activitypub`, `list_activitypub`, the unfollow branch of
	 * `remove_following`, and the outbox poller) can't drift.
	 */
	public static function ap_actor_id(): int {
		if ( ! defined( 'ACTIVITYPUB_BLOG_MODE' ) ) {
			return get_current_user_id();
		}
		$mode = (string) get_option( 'activitypub_actor_mode', '' );
		if ( ACTIVITYPUB_BLOG_MODE === $mode ) {
			// BLOG_USER_ID is defined as 0 by the AP plugin; falling
			// back to the literal int keeps the helper safe if the
			// constant moves between AP versions.
			return class_exists( '\\Activitypub\\Collection\\Actors' )
				? \Activitypub\Collection\Actors::BLOG_USER_ID
				: 0;
		}
		return get_current_user_id();
	}
}
