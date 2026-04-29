<?php
/**
 * RSS/Atom Feed Fetcher
 *
 * Wraps WordPress's built-in fetch_feed() (SimplePie) to pull items from a
 * single RSS or Atom feed URL and return a normalized item array.
 *
 * @package RadicalSocials
 */

defined( 'ABSPATH' ) || exit;

class Radical_Socials_RSS_Fetcher {

	/**
	 * Fetch up to $count items from a single feed URL.
	 *
	 * @return array<int, array{title:string, url:string, excerpt:string, date:string, source_name:string, source_url:string, thumbnail_url:string, guid:string, feed_type:string}>
	 */
	public static function fetch( string $feed_url, int $count = 20 ): array {
		if ( ! function_exists( 'fetch_feed' ) ) {
			require_once ABSPATH . WPINC . '/feed.php';
		}

		$feed = fetch_feed( $feed_url );
		if ( is_wp_error( $feed ) ) {
			return [];
		}

		$items = $feed->get_items( 0, $count );
		if ( empty( $items ) ) {
			return [];
		}

		$channel_title = wp_strip_all_tags( (string) $feed->get_title() );
		$channel_url   = esc_url_raw( (string) $feed->get_permalink() );
		if ( ! $channel_url ) {
			$channel_url = $feed_url;
		}

		$normalized = [];
		foreach ( $items as $item ) {
			$url = esc_url_raw( (string) $item->get_permalink() );
			if ( ! $url ) {
				continue;
			}

			$raw_content = (string) ( $item->get_content() ?: $item->get_description() );

			$thumbnail = '';
			$enclosure = $item->get_enclosure();
			if ( $enclosure && str_starts_with( (string) $enclosure->get_type(), 'image/' ) ) {
				$thumbnail = esc_url_raw( (string) $enclosure->get_link() );
			}
			if ( ! $thumbnail ) {
				$thumbnail = self::extract_first_image( $raw_content );
			}

			$normalized[] = [
				'title'         => wp_strip_all_tags( (string) $item->get_title() ),
				'url'           => $url,
				'content'       => $raw_content,
				'excerpt'       => wp_trim_words( wp_strip_all_tags( (string) $item->get_description() ), 30 ),
				'date'          => $item->get_date( 'c' ) ?: current_time( 'c' ),
				'source_name'   => $channel_title ?: parse_url( $feed_url, PHP_URL_HOST ),
				'source_url'    => $channel_url,
				'thumbnail_url' => $thumbnail,
				'guid'          => md5( $url ),
				'feed_type'     => 'rss',
			];
		}

		return $normalized;
	}

	private static function extract_first_image( string $html ): string {
		if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/', $html, $m ) ) {
			return esc_url_raw( $m[1] );
		}
		return '';
	}

	/**
	 * Get the list of RSS feed URLs from the structured subscriptions option.
	 *
	 * @return string[]
	 */
	public static function get_feed_urls(): array {
		$subs = (array) get_option( 'rs_rss_subscriptions', [] );
		return array_values( array_filter( array_column( $subs, 'url' ), 'wp_http_validate_url' ) );
	}
}
