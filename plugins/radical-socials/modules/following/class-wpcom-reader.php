<?php
/**
 * WP.com Reader Fetcher
 *
 * Fetches the authenticated user's following feed from the WordPress.com
 * Reader REST API and returns a normalized array of feed items.
 *
 * @package RadicalSocials
 */

defined( 'ABSPATH' ) || exit;

class Radical_Socials_WPCOM_Reader {

	const API_BASE = 'https://public-api.wordpress.com/rest/v1.2/read/following';

	/**
	 * Fetch up to $count items from the WP.com Reader following feed.
	 *
	 * @return array<int, array{title:string, url:string, excerpt:string, date:string, source_name:string, source_url:string, thumbnail_url:string, guid:string}>
	 */
	public static function fetch( int $count = 40 ): array {
		$token = Radical_Socials_WPCOM_OAuth::get_token();
		if ( ! $token ) {
			return [];
		}

		$response = wp_remote_get(
			add_query_arg( [ 'number' => $count, 'order' => 'DESC' ], self::API_BASE ),
			[
				'headers' => [ 'Authorization' => 'Bearer ' . $token ],
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return [];
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['posts'] ) || ! is_array( $data['posts'] ) ) {
			return [];
		}

		return array_map( [ __CLASS__, 'normalize' ], $data['posts'] );
	}

	/**
	 * Return the list of sites the authenticated user follows on WP.com Reader.
	 * Returns up to 100 entries (one API page).
	 *
	 * @return array<int, array{id:string, type:string, url:string, title:string, blog_id:string}>
	 */
	public static function get_following_list(): array {
		$token = Radical_Socials_WPCOM_OAuth::get_token();
		if ( ! $token ) {
			return [];
		}

		$response = wp_remote_get(
			'https://public-api.wordpress.com/rest/v1.2/read/following?number=100',
			[
				'headers' => [ 'Authorization' => 'Bearer ' . $token ],
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return [];
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['subscriptions'] ) ) {
			return [];
		}

		return array_map( fn( $sub ) => [
			'id'      => (string) ( $sub['ID'] ?? '' ),
			'type'    => 'wpcom',
			'url'     => $sub['URL'] ?? $sub['feed_URL'] ?? '',
			'title'   => $sub['blog_name'] ?? $sub['URL'] ?? '',
			'blog_id' => (string) ( $sub['blog_ID'] ?? '' ),
		], $data['subscriptions'] );
	}

	/**
	 * Unfollow a WP.com site by subscription ID (blog_ID from the following list).
	 */
	public static function unfollow_site( string $blog_id ): bool {
		$token = Radical_Socials_WPCOM_OAuth::get_token();
		if ( ! $token || ! $blog_id ) {
			return false;
		}

		$response = wp_remote_post(
			'https://public-api.wordpress.com/rest/v1.1/sites/' . absint( $blog_id ) . '/follows/mine/delete',
			[
				'headers' => [ 'Authorization' => 'Bearer ' . $token ],
				'timeout' => 15,
			]
		);

		return ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response );
	}

	private static function normalize( array $post ): array {
		$thumbnail = '';
		if ( ! empty( $post['featured_image'] ) ) {
			$thumbnail = $post['featured_image'];
		} elseif ( ! empty( $post['attachments'] ) ) {
			$first     = reset( $post['attachments'] );
			$thumbnail = $first['URL'] ?? '';
		}

		return [
			'title'        => wp_strip_all_tags( $post['title'] ?? '' ),
			'url'          => esc_url_raw( $post['URL'] ?? '' ),
			'excerpt'      => wp_trim_words( wp_strip_all_tags( $post['excerpt'] ?? '' ), 30 ),
			'date'         => $post['date'] ?? current_time( 'c' ),
			'source_name'  => wp_strip_all_tags( $post['site_name'] ?? parse_url( $post['URL'] ?? '', PHP_URL_HOST ) ),
			'source_url'   => esc_url_raw( $post['site_URL'] ?? '' ),
			'thumbnail_url' => esc_url_raw( $thumbnail ),
			'guid'         => md5( $post['URL'] ?? uniqid( 'wpcom_', true ) ),
			'feed_type'    => 'wpcom',
		];
	}
}
