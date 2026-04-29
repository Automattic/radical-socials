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

		return [
			'title'         => wp_strip_all_tags( $name ?: $actor_name . ' posted' ),
			'url'           => $object_url,
			'excerpt'       => wp_trim_words( wp_strip_all_tags( $content ), 30 ),
			'date'          => get_the_date( 'c', $post_id ),
			'source_name'   => $actor_name,
			'source_url'    => esc_url_raw( (string) $actor_url ),
			'thumbnail_url' => esc_url_raw( $object['image']['url'] ?? $object['image'] ?? '' ),
			'guid'          => md5( $object_url ),
			'feed_type'     => 'activitypub',
		];
	}

	public static function is_available(): bool {
		return post_type_exists( self::POST_TYPE );
	}
}
