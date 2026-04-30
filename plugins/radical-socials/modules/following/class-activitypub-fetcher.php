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

	/**
	 * Fetch up to $count recent Create activities from the ActivityPub inbox.
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
						'key'   => '_activitypub_activity_type',
						'value' => 'Create',
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

		$actor_url = get_post_meta( $post_id, '_activitypub_activity_remote_actor', true );
		$object_id = get_post_meta( $post_id, '_activitypub_object_id', true );

		// Parse the full activity JSON for richer object data.
		$activity = json_decode( $post->post_content, true );
		$object   = $activity['object'] ?? [];

		if ( is_string( $object ) ) {
			// Some activities store only the object URL as a string.
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

		// Derive actor display name from the stored actor URL (best we have without a separate lookup).
		$actor_name = parse_url( (string) $actor_url, PHP_URL_HOST ) ?? '';
		$actor_path = ltrim( parse_url( (string) $actor_url, PHP_URL_PATH ) ?? '', '/' );
		if ( $actor_path ) {
			$actor_name = $actor_path . '@' . $actor_name;
		}

		$image = $object['image'] ?? '';
		$thumbnail = is_array( $image ) ? esc_url_raw( $image['url'] ?? '' ) : esc_url_raw( (string) $image );

		return [
			'title'         => wp_strip_all_tags( $name ?: $actor_name . ' posted' ),
			'url'           => $object_url,
			'content'       => wp_kses_post( $content ),
			'excerpt'       => wp_trim_words( wp_strip_all_tags( $content ), 30 ),
			'date'          => get_the_date( 'c', $post_id ),
			'source_name'   => $actor_name,
			'source_url'    => esc_url_raw( (string) $actor_url ),
			'thumbnail_url' => $thumbnail,
			'guid'          => md5( $object_url ),
			'feed_type'     => 'activitypub',
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

		$user_id = (int) get_option( 'rs_ap_follow_user_id', 0 );
		if ( ! $user_id ) {
			// Fall back to the first admin user if the option hasn't been set yet.
			$admins  = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
			$user_id = $admins ? (int) $admins[0] : 0;
		}
		if ( ! $user_id ) {
			return [];
		}

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
			$actor_url  = $post->guid;
			$outbox_url = get_post_meta( $post->ID, '_rs_outbox_url', true );

			if ( ! $outbox_url ) {
				$actor_json = self::fetch_json( $actor_url );
				if ( ! $actor_json || empty( $actor_json['outbox'] ) ) {
					continue;
				}
				$outbox_url = $actor_json['outbox'];
				update_post_meta( $post->ID, '_rs_outbox_url', $outbox_url );
			}

			$page = self::fetch_outbox_page( $outbox_url );
			if ( ! $page ) {
				continue;
			}

			$activities = $page['orderedItems'] ?? [];
			foreach ( array_slice( $activities, 0, $per_actor ) as $activity ) {
				if ( ( $activity['type'] ?? '' ) !== 'Create' ) {
					continue;
				}
				$item = self::normalize_outbox_activity( $activity, $actor_url );
				if ( $item ) {
					$items[] = $item;
				}
			}
		}

		return $items;
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
		$response = wp_remote_get( $url, [
			'timeout' => 10,
			'headers' => [
				'Accept' => 'application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
			],
		] );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : null;
	}

	private static function normalize_outbox_activity( array $activity, string $actor_url ): ?array {
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

		$actor_name = parse_url( $actor_url, PHP_URL_HOST ) ?? '';
		$actor_path = ltrim( parse_url( $actor_url, PHP_URL_PATH ) ?? '', '/' );
		if ( $actor_path ) {
			$actor_name = $actor_path . '@' . $actor_name;
		}

		$image     = $object['image'] ?? '';
		$thumbnail = is_array( $image ) ? esc_url_raw( $image['url'] ?? '' ) : esc_url_raw( (string) $image );

		$published = $object['published'] ?? $activity['published'] ?? '';

		return [
			'title'         => wp_strip_all_tags( $name ?: $actor_name . ' posted' ),
			'url'           => $object_url,
			'content'       => wp_kses_post( $content ),
			'excerpt'       => wp_trim_words( wp_strip_all_tags( $content ), 30 ),
			'date'          => $published ?: current_time( 'c' ),
			'source_name'   => $actor_name,
			'source_url'    => esc_url_raw( $actor_url ),
			'thumbnail_url' => $thumbnail,
			'guid'          => md5( $object_url ),
			'feed_type'     => 'activitypub',
		];
	}

	public static function is_available(): bool {
		return post_type_exists( self::POST_TYPE );
	}
}
