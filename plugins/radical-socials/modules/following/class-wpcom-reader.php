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

		$response = wp_safe_remote_get(
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
	 * @return array<int, array{id:string, type:string, url:string, title:string, blog_id:string, subscription_id:string}>
	 */
	public static function get_following_list(): array {
		$token = Radical_Socials_WPCOM_OAuth::get_token();
		if ( ! $token ) {
			return [];
		}

		$response = wp_safe_remote_get(
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

		return array_map(
			fn( $sub ) => [
				'id'              => (string) ( $sub['blog_ID'] ?? '' ),
				'type'            => 'wpcom',
				'url'             => $sub['URL'] ?? $sub['feed_URL'] ?? '',
				'title'           => $sub['blog_name'] ?? $sub['URL'] ?? '',
				'blog_id'         => (string) ( $sub['blog_ID'] ?? '' ),
				'subscription_id' => (string) ( $sub['ID'] ?? '' ),
			],
			$data['subscriptions']
		);
	}

	/**
	 * Unfollow a WP.com site by blog_ID from the following list.
	 */
	public static function unfollow_site( string $blog_id ): bool {
		$token = Radical_Socials_WPCOM_OAuth::get_token();
		if ( ! $token || ! $blog_id ) {
			return false;
		}

		$response = wp_safe_remote_post(
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

		// Author metadata: the Reader API returns `author` as a nested
		// object on each post (name / URL / avatar_URL). Fall back to
		// site-level fields (site_name / site_URL / site_icon) when the
		// post-level author shape is missing — some WP.com sites publish
		// without a per-post byline, but every site has a name + icon.
		$author      = isset( $post['author'] ) && is_array( $post['author'] ) ? $post['author'] : [];
		$author_name = wp_strip_all_tags( (string) ( $author['name'] ?? $post['site_name'] ?? '' ) );
		$author_url  = esc_url_raw( (string) ( $author['URL'] ?? $post['site_URL'] ?? '' ) );
		$author_icon = '';
		if ( ! empty( $author['avatar_URL'] ) ) {
			$author_icon = esc_url_raw( (string) $author['avatar_URL'] );
		} elseif ( isset( $post['site_icon']['img'] ) ) {
			$author_icon = esc_url_raw( (string) $post['site_icon']['img'] );
		} elseif ( ! empty( $post['site_icon'] ) && is_string( $post['site_icon'] ) ) {
			$author_icon = esc_url_raw( $post['site_icon'] );
		}

		// `content` is the full post HTML; `excerpt` is WP.com's (already
		// truncated) summary. RSS and ActivityPub fetchers store the full body
		// in `content`; we now do the same here so the post-content block
		// renders complete posts instead of a 30-word teaser. Upsert applies
		// our kses allowlist on top, so we leave the body unfiltered here.
		return [
			'title'           => wp_strip_all_tags( $post['title'] ?? '' ),
			'url'             => esc_url_raw( $post['URL'] ?? '' ),
			'content'         => (string) ( $post['content'] ?? '' ),
			'excerpt'         => wp_strip_all_tags( (string) ( $post['excerpt'] ?? '' ) ),
			'date'            => $post['date'] ?? current_time( 'c' ),
			'source_name'     => wp_strip_all_tags( $post['site_name'] ?? parse_url( $post['URL'] ?? '', PHP_URL_HOST ) ),
			'source_url'      => esc_url_raw( $post['site_URL'] ?? '' ),
			'thumbnail_url'   => esc_url_raw( $thumbnail ),
			'guid'            => md5( $post['URL'] ?? uniqid( 'wpcom_', true ) ),
			'feed_type'       => 'wpcom',
			'author_name'     => $author_name,
			'author_icon_url' => $author_icon,
			'author_url'      => $author_url,
		];
	}
}
