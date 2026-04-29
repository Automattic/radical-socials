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

		// WordPress caches feed results for 12 h by default; match our hourly cron instead.
		$ttl = fn() => HOUR_IN_SECONDS;
		add_filter( 'wp_feed_cache_transient_lifetime', $ttl );
		$feed = fetch_feed( $feed_url );

		// If the stored URL is dead, try to discover the current feed from the site homepage.
		if ( is_wp_error( $feed ) ) {
			$discovered = self::discover_feed_url( $feed_url );
			if ( $discovered && $discovered !== $feed_url ) {
				self::save_url_update( $feed_url, $discovered );
				$feed_url = $discovered;
				$feed     = fetch_feed( $feed_url );
			}
		}

		remove_filter( 'wp_feed_cache_transient_lifetime', $ttl );

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

	/**
	 * Resolve a potentially dead feed URL to its current location.
	 * Returns the original URL unchanged if no better URL is found.
	 * Called by add_rss() so the canonical URL is stored from the start.
	 */
	public static function resolve_url( string $url ): string {
		$discovered = self::discover_feed_url( $url );
		return ( $discovered && $discovered !== $url ) ? $discovered : $url;
	}

	/**
	 * Walk all stored RSS subscriptions, check each URL, and auto-discover
	 * replacements for any that return errors. Returns the number updated.
	 */
	public static function migrate_dead_urls(): int {
		$subs    = (array) get_option( 'rs_rss_subscriptions', [] );
		$updated = 0;
		foreach ( $subs as &$sub ) {
			$r    = wp_remote_head( $sub['url'], [ 'timeout' => 5, 'redirection' => 5 ] );
			$code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
			if ( $code === 0 || $code >= 400 ) {
				$new = self::discover_feed_url( $sub['url'] );
				if ( $new && $new !== $sub['url'] ) {
					$sub['url'] = $new;
					++$updated;
				}
			}
		}
		unset( $sub );
		if ( $updated ) {
			update_option( 'rs_rss_subscriptions', $subs, false );
		}
		return $updated;
	}

	/**
	 * Try to find the current feed URL for a site whose stored URL is dead.
	 * Checks the HTTP→HTTPS upgrade first, then parses the site homepage for
	 * an RSS/Atom <link> tag.
	 */
	private static function discover_feed_url( string $old_url ): string {
		// 1. Try upgrading http → https.
		if ( str_starts_with( $old_url, 'http://' ) ) {
			$https = 'https://' . substr( $old_url, 7 );
			$r     = wp_remote_head( $https, [ 'timeout' => 5, 'redirection' => 5 ] );
			if ( ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) < 400 ) {
				return $https;
			}
		}

		// 2. Fetch the site homepage and look for <link rel="alternate" type="application/rss+xml">.
		$parsed = wp_parse_url( $old_url );
		if ( empty( $parsed['host'] ) ) {
			return '';
		}
		$home = ( $parsed['scheme'] ?? 'https' ) . '://' . $parsed['host'] . '/';
		$r    = wp_remote_get( $home, [
			'timeout'     => 8,
			'redirection' => 5,
			'user-agent'  => 'Mozilla/5.0 (compatible; RadicalSocials/1.0; +https://github.com/Automattic/radical-socials)',
		] );
		if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) >= 400 ) {
			return '';
		}
		$body = wp_remote_retrieve_body( $r );
		if ( preg_match( '/<link[^>]+type=["\']application\/(?:rss|atom)\+xml["\'][^>]+href=["\']([^"\']+)["\']/', $body, $m )
			|| preg_match( '/<link[^>]+href=["\']([^"\']+)["\'][^>]+type=["\']application\/(?:rss|atom)\+xml["\']/', $body, $m )
		) {
			return esc_url_raw( html_entity_decode( $m[1] ) );
		}

		return '';
	}

	/**
	 * Update an existing subscription's URL in place.
	 * Used by fetch() when it discovers a better URL during a cron run.
	 */
	private static function save_url_update( string $old_url, string $new_url ): void {
		$subs = (array) get_option( 'rs_rss_subscriptions', [] );
		foreach ( $subs as &$sub ) {
			if ( $sub['url'] === $old_url ) {
				$sub['url'] = $new_url;
				break;
			}
		}
		unset( $sub );
		update_option( 'rs_rss_subscriptions', $subs, false );
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
