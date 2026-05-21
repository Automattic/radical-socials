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

	public const HTTP_TIMEOUT     = 10;
	public const HTTP_REDIRECTION = 3;

	/**
	 * Fetch up to $count items from a single feed URL.
	 *
	 * @return array<int, array{title:string, url:string, excerpt:string, date:string, source_name:string, source_url:string, thumbnail_url:string, guid:string, feed_type:string}>
	 */
	/** @var string|null Most recent error from a fetch() call. */
	private static ?string $last_error = null;

	/**
	 * Last human-readable error from fetch(), or empty string when the
	 * last fetch succeeded. Used by Feed_Fetcher::fetch_all_rss() to record
	 * per-subscription health.
	 */
	public static function last_error(): string {
		return (string) self::$last_error;
	}

	public static function fetch( string $feed_url, int $count = 20 ): array {
		self::$last_error = null;

		if ( ! self::is_safe_remote_url( $feed_url ) ) {
			self::$last_error = __( 'URL is not a safe public address (private IP, localhost, or invalid format).', 'radical-socials' );
			return [];
		}

		if ( ! function_exists( 'fetch_feed' ) ) {
			require_once ABSPATH . WPINC . '/feed.php';
		}

		// WordPress caches feed results for 12 h by default; match our refresh cadence instead.
		$ttl = static fn() => Radical_Socials_Following::FETCH_INTERVAL;
		add_filter( 'wp_feed_cache_transient_lifetime', $ttl );
		$feed = self::fetch_feed_with_http_args( $feed_url );

		// If the stored URL is dead, try to discover the current feed from the site homepage.
		if ( is_wp_error( $feed ) ) {
			$discovered = self::discover_feed_url( $feed_url );
			if ( $discovered && $discovered !== $feed_url ) {
				self::save_url_update( $feed_url, $discovered );
				$feed_url = $discovered;
				$feed     = self::fetch_feed_with_http_args( $feed_url );
			}
		}

		remove_filter( 'wp_feed_cache_transient_lifetime', $ttl );

		if ( is_wp_error( $feed ) ) {
			self::$last_error = self::humanise_feed_error( $feed );
			return [];
		}

		$items = $feed->get_items( 0, $count );
		if ( empty( $items ) ) {
			// Not necessarily an error — feeds can be quiet. Let the caller decide.
			return [];
		}

		$channel_title = wp_strip_all_tags( (string) $feed->get_title() );
		$channel_url   = esc_url_raw( (string) $feed->get_permalink() );
		if ( ! $channel_url ) {
			$channel_url = $feed_url;
		}

		// Channel-level image (the feed's `<image>` / `<itunes:image>` /
		// Atom `<icon>`). Used as the default avatar for every item from
		// this feed — RSS rarely carries per-item author avatars, so the
		// feed's own icon is the closest thing to a "who posted this".
		$channel_icon = esc_url_raw( (string) ( $feed->get_image_url() ?: '' ) );

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

			// Author: prefer the item's own author (<author> / <dc:creator>
			// / <atom:author><name>); fall back to the channel title so a
			// per-author byline is rare but a feed name is always there.
			$author_obj  = $item->get_author();
			$author_name = $author_obj
				? wp_strip_all_tags( (string) ( $author_obj->get_name() ?: $author_obj->get_email() ) )
				: '';
			if ( '' === $author_name ) {
				$author_name = $channel_title;
			}
			$author_url = $author_obj
				? esc_url_raw( (string) ( $author_obj->get_link() ?: '' ) )
				: '';
			if ( '' === $author_url ) {
				$author_url = $channel_url;
			}

			$normalized[] = [
				'title'           => wp_strip_all_tags( (string) $item->get_title() ),
				'url'             => $url,
				'content'         => $raw_content,
				'excerpt'         => wp_trim_words( wp_strip_all_tags( (string) $item->get_description() ), 30 ),
				'date'            => $item->get_date( 'c' ) ?: current_time( 'c' ),
				'source_name'     => $channel_title ?: parse_url( $feed_url, PHP_URL_HOST ),
				'source_url'      => $channel_url,
				'thumbnail_url'   => $thumbnail,
				'guid'            => md5( $url ),
				'feed_type'       => 'rss',
				'author_name'     => $author_name,
				'author_icon_url' => $channel_icon,
				'author_url'      => $author_url,
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
		if ( ! self::is_safe_remote_url( $url ) ) {
			return $url;
		}

		$discovered = self::discover_feed_url( $url );
		return ( $discovered && $discovered !== $url ) ? $discovered : $url;
	}

	public static function is_safe_remote_url( string $url ): bool {
		if ( ! self::has_valid_remote_url_format( $url ) ) {
			return false;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}

		$host = strtolower( trim( $host, "[] \t\n\r\0\x0B." ) );
		if ( ! $host || 'localhost' === $host || str_ends_with( $host, '.localhost' ) ) {
			return false;
		}

		$ips = [];
		$is_ip_host = (bool) filter_var( $host, FILTER_VALIDATE_IP );
		if ( $is_ip_host ) {
			$ips[] = $host;
		} elseif ( function_exists( 'dns_get_record' ) ) {
			$records = @dns_get_record( $host, DNS_A + DNS_AAAA );
			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( ! empty( $record['ip'] ) ) {
						$ips[] = $record['ip'];
					}
					if ( ! empty( $record['ipv6'] ) ) {
						$ips[] = $record['ipv6'];
					}
				}
			}
		}

		if ( ! $ips ) {
			$resolved = gethostbyname( $host );
			if ( $resolved && $resolved !== $host ) {
				$ips[] = $resolved;
			}
		}

		if ( ! $ips && ! $is_ip_host ) {
			// DNS preflight failed (no A/AAAA records, no gethostbyname
			// resolution). In some legitimate environments this just
			// means we can't pre-resolve a perfectly valid public host
			// — WP Playground, locked-down PHP installs, or containers
			// with custom resolvers. We deliberately fall through to
			// `true` here and rely on the *caller* to use wp_safe_remote_*
			// (which runs wp_http_validate_url at fetch time and refuses
			// private/loopback IPs the moment a real connection is
			// attempted, regardless of what DNS does at preflight).
			//
			// SSRF risk note: an attacker controlling DNS could serve
			// SERVFAIL here and a private IP at fetch time. Defense
			// against that lives one layer down — every external caller
			// in this plugin goes through wp_safe_remote_*, and any
			// future caller MUST do the same. The only exception is
			// the same-host wp-cron loopback in Radical_Socials_Following,
			// which deliberately bypasses safety to ping itself.
			return true;
		}

		foreach ( array_unique( $ips ) as $ip ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				// WordPress Playground resolves external domains through a local
				// proxy IP. For hostnames, trust WP's own HTTP validator when it
				// explicitly accepts the URL; literal IPs must still pass above.
				if ( ! $is_ip_host && wp_http_validate_url( $url ) ) {
					return true;
				}
				return false;
			}
		}

		return true;
	}

	public static function has_valid_remote_url_format( string $url ): bool {
		$url = trim( $url );
		if ( '' === $url || preg_match( '/[\x00-\x1F\x7F]/', $url ) ) {
			return false;
		}

		$parsed = wp_parse_url( $url );
		if ( false === $parsed || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			return false;
		}

		if ( ! in_array( strtolower( (string) $parsed['scheme'] ), [ 'http', 'https' ], true ) ) {
			return false;
		}

		if ( isset( $parsed['user'] ) || isset( $parsed['pass'] ) ) {
			return false;
		}

		if ( isset( $parsed['port'] ) && ( (int) $parsed['port'] < 1 || (int) $parsed['port'] > 65535 ) ) {
			return false;
		}

		return (bool) esc_url_raw( $url, [ 'http', 'https' ] );
	}

	/**
	 * Walk all stored RSS subscriptions, check each URL, and auto-discover
	 * replacements for any that return errors. Returns the number updated.
	 */
	public static function migrate_dead_urls(): int {
		$subs    = (array) get_option( 'rs_rss_subscriptions', [] );
		$updated = 0;
		foreach ( $subs as &$sub ) {
			if ( ! self::is_safe_remote_url( $sub['url'] ) ) {
				continue;
			}

			$r    = wp_safe_remote_head( $sub['url'], self::http_args() );
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
	/**
	 * Translate a SimplePie / WP HTTP WP_Error into a sentence a non-technical
	 * user can act on. Falls back to the raw message when we don't recognise
	 * the code.
	 */
	private static function humanise_feed_error( WP_Error $error ): string {
		$code    = (string) $error->get_error_code();
		$raw     = (string) $error->get_error_message();
		$lc_raw  = strtolower( $raw );

		// SimplePie surfaces HTTP status as part of its 'simplepie-error' code.
		if ( false !== strpos( $lc_raw, 'a feed could not be found at' ) ) {
			return __( 'The URL doesn\'t serve an RSS or Atom feed.', 'radical-socials' );
		}
		if ( false !== strpos( $lc_raw, '404 not found' ) || str_contains( $lc_raw, ' 404' ) ) {
			return __( 'The feed URL returns 404 Not Found — it may have moved or been removed.', 'radical-socials' );
		}
		if ( false !== strpos( $lc_raw, '403 forbidden' ) || str_contains( $lc_raw, ' 403' ) ) {
			return __( 'The feed server blocked our request (HTTP 403). The site may be rate-limiting or restricting feed access.', 'radical-socials' );
		}
		if ( false !== strpos( $lc_raw, '5' ) && ( str_contains( $lc_raw, '500' ) || str_contains( $lc_raw, '502' ) || str_contains( $lc_raw, '503' ) || str_contains( $lc_raw, '504' ) ) ) {
			return __( 'The feed server returned a 5xx error. The site is probably temporarily down.', 'radical-socials' );
		}
		if ( false !== strpos( $lc_raw, 'ssl' ) || false !== strpos( $lc_raw, 'certificate' ) ) {
			return __( 'The feed server\'s SSL certificate is invalid or expired.', 'radical-socials' );
		}
		if ( false !== strpos( $lc_raw, 'name or service not known' ) || false !== strpos( $lc_raw, 'could not resolve host' ) ) {
			return __( 'The feed\'s domain name doesn\'t resolve — the site may no longer exist.', 'radical-socials' );
		}
		if ( false !== strpos( $lc_raw, 'connection refused' ) ) {
			return __( 'The feed server refused the connection.', 'radical-socials' );
		}
		if ( false !== strpos( $lc_raw, 'timed out' ) || false !== strpos( $lc_raw, 'timeout' ) ) {
			return __( 'The feed server didn\'t respond in time (timeout).', 'radical-socials' );
		}
		if ( 'http_request_failed' === $code ) {
			return sprintf(
				/* translators: %s: low-level error message */
				__( 'Couldn\'t reach the feed server: %s', 'radical-socials' ),
				$raw
			);
		}

		// Last resort: pass the raw message through but at least frame it.
		return $raw ?: __( 'Unknown feed error.', 'radical-socials' );
	}

	private static function discover_feed_url( string $old_url ): string {
		if ( ! self::is_safe_remote_url( $old_url ) ) {
			return '';
		}

		// 1. Try upgrading http → https.
		if ( str_starts_with( $old_url, 'http://' ) ) {
			$https = 'https://' . substr( $old_url, 7 );
			$r     = self::is_safe_remote_url( $https ) ? wp_safe_remote_head( $https, self::http_args() ) : null;
			if ( $r && ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) < 400 ) {
				return $https;
			}
		}

		// 2. Fetch the site homepage and look for <link rel="alternate" type="application/rss+xml">.
		$parsed = wp_parse_url( $old_url );
		if ( empty( $parsed['host'] ) ) {
			return '';
		}
		$home = ( $parsed['scheme'] ?? 'https' ) . '://' . $parsed['host'] . ( isset( $parsed['port'] ) ? ':' . $parsed['port'] : '' ) . '/';
		if ( ! self::is_safe_remote_url( $home ) ) {
			return '';
		}

		$r    = wp_safe_remote_get( $home, self::http_args() );
		if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) >= 400 ) {
			return '';
		}
		$body = wp_remote_retrieve_body( $r );
		if ( preg_match( '/<link[^>]+type=["\']application\/(?:rss|atom)\+xml["\'][^>]+href=["\']([^"\']+)["\']/', $body, $m )
			|| preg_match( '/<link[^>]+href=["\']([^"\']+)["\'][^>]+type=["\']application\/(?:rss|atom)\+xml["\']/', $body, $m )
		) {
			return self::resolve_relative_url( html_entity_decode( $m[1] ), $home );
		}

		return '';
	}

	private static function resolve_relative_url( string $url, string $base_url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		if ( self::is_safe_remote_url( $url ) ) {
			return esc_url_raw( $url );
		}

		$base = wp_parse_url( $base_url );
		if ( empty( $base['scheme'] ) || empty( $base['host'] ) ) {
			return '';
		}

		if ( str_starts_with( $url, '//' ) ) {
			$absolute = $base['scheme'] . ':' . $url;
		} elseif ( str_starts_with( $url, '/' ) ) {
			$absolute = $base['scheme'] . '://' . $base['host'] . ( isset( $base['port'] ) ? ':' . $base['port'] : '' ) . $url;
		} else {
			$path      = $base['path'] ?? '/';
			$directory = trailingslashit( preg_replace( '~/[^/]*$~', '/', $path ) );
			$absolute  = $base['scheme'] . '://' . $base['host'] . ( isset( $base['port'] ) ? ':' . $base['port'] : '' ) . $directory . $url;
		}

		return self::is_safe_remote_url( $absolute ) ? esc_url_raw( $absolute ) : '';
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

	public static function http_args(): array {
		return [
			'timeout'            => self::HTTP_TIMEOUT,
			'redirection'        => self::HTTP_REDIRECTION,
			'reject_unsafe_urls' => true,
			'user-agent'         => 'Mozilla/5.0 (compatible; RadicalSocials/1.0; +https://github.com/Automattic/radical-socials)',
		];
	}

	private static function fetch_feed_with_http_args( string $feed_url ) {
		$http_args = static function ( array $args ) {
			return array_merge( $args, self::http_args() );
		};

		add_filter( 'http_request_args', $http_args );
		try {
			return fetch_feed( $feed_url );
		} finally {
			remove_filter( 'http_request_args', $http_args );
		}
	}

	/**
	 * Get the list of RSS feed URLs from the structured subscriptions option.
	 *
	 * @return string[]
	 */
	public static function get_feed_urls(): array {
		$subs = (array) get_option( 'rs_rss_subscriptions', [] );
		return array_values( array_filter( array_column( $subs, 'url' ), [ __CLASS__, 'is_safe_remote_url' ] ) );
	}
}
