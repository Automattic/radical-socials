<?php
/**
 * OPML Import / Export
 *
 * parse()  — converts OPML XML into a flat array of feed records.
 * import() — upserts those records into rs_rss_subscriptions:
 *              new feeds are added; existing ones get title/source_url/categories
 *              backfilled if currently empty, and categories are merged.
 * export() — serialises rs_rss_subscriptions back to OPML, grouping feeds
 *              into <outline> folders by their first category.
 *
 * @package RadicalSocials
 */

defined( 'ABSPATH' ) || exit;

class Radical_Socials_OPML {

	// ── Parse ─────────────────────────────────────────────────────────────────

	/**
	 * Parse an OPML XML string.
	 *
	 * @return array<int, array{url:string, title:string, source_url:string, categories:string[]}>
	 */
	public static function parse( string $xml ): array {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return [];
		}

		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$loaded = $doc->loadXML( trim( $xml ) );
		libxml_clear_errors();

		if ( ! $loaded ) {
			return [];
		}

		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body ) {
			return [];
		}

		$feeds = [];
		self::walk( $body->childNodes, [], $feeds );
		return $feeds;
	}

	private static function walk( DOMNodeList $nodes, array $ancestors, array &$feeds ): void {
		foreach ( $nodes as $node ) {
			if ( XML_ELEMENT_NODE !== $node->nodeType || 'outline' !== $node->nodeName ) {
				continue;
			}

			// getAttribute is case-sensitive; OPML uses xmlUrl but some exporters lowercase it.
			$xml_url = $node->getAttribute( 'xmlUrl' ) ?: $node->getAttribute( 'xmlurl' );

			if ( $xml_url ) {
				$title    = $node->getAttribute( 'text' ) ?: $node->getAttribute( 'title' ) ?: $xml_url;
				$html_url = $node->getAttribute( 'htmlUrl' ) ?: $node->getAttribute( 'htmlurl' ) ?: '';

				$feeds[] = [
					'url'        => esc_url_raw( $xml_url ),
					'title'      => sanitize_text_field( $title ),
					'source_url' => esc_url_raw( $html_url ),
					'categories' => $ancestors,
				];
			} else {
				// Folder — recurse with this folder appended to the ancestor path.
				$name     = sanitize_text_field( $node->getAttribute( 'text' ) ?: $node->getAttribute( 'title' ) ?: '' );
				$children = $node->childNodes;
				if ( $children && $children->length ) {
					self::walk( $children, $name ? array_merge( $ancestors, [ $name ] ) : $ancestors, $feeds );
				}
			}
		}
	}

	// ── Import ────────────────────────────────────────────────────────────────

	/**
	 * Upsert parsed feed records into rs_rss_subscriptions.
	 *
	 * - New feeds are appended.
	 * - Existing feeds (matched by URL) get their title, source_url, and
	 *   categories backfilled if the stored value is currently empty; categories
	 *   from the OPML are merged with any already stored.
	 *
	 * @param  array $feeds  Output of parse().
	 * @return array{added:int, updated:int, skipped:int}
	 */
	public static function import( array $feeds ): array {
		$subs    = (array) get_option( 'rs_rss_subscriptions', [] );
		$added   = 0;
		$updated = 0;
		$skipped = 0;

		// Build a URL→index map for O(1) lookups.
		$url_index = [];
		foreach ( $subs as $i => $sub ) {
			$url_index[ $sub['url'] ] = $i;
		}

		foreach ( $feeds as $feed ) {
			if ( ! wp_http_validate_url( $feed['url'] ) ) {
				$skipped++;
				continue;
			}

			if ( isset( $url_index[ $feed['url'] ] ) ) {
				$i       = $url_index[ $feed['url'] ];
				$changed = false;

				if ( empty( $subs[ $i ]['title'] ) && ! empty( $feed['title'] ) ) {
					$subs[ $i ]['title'] = $feed['title'];
					$changed = true;
				}

				if ( empty( $subs[ $i ]['source_url'] ) && ! empty( $feed['source_url'] ) ) {
					$subs[ $i ]['source_url'] = $feed['source_url'];
					$changed = true;
				}

				if ( ! empty( $feed['categories'] ) ) {
					$existing = $subs[ $i ]['categories'] ?? [];
					$merged   = array_values( array_unique( array_merge( $existing, $feed['categories'] ) ) );
					if ( $merged !== $existing ) {
						$subs[ $i ]['categories'] = $merged;
						$changed = true;
					}
				}

				if ( $changed ) {
					$updated++;
				} else {
					$skipped++;
				}
			} else {
				$subs[]                      = [
					'url'        => $feed['url'],
					'title'      => $feed['title'],
					'source_url' => $feed['source_url'],
					'categories' => $feed['categories'],
				];
				$url_index[ $feed['url'] ] = count( $subs ) - 1;
				$added++;
			}
		}

		if ( $added || $updated ) {
			update_option( 'rs_rss_subscriptions', $subs, false );
		}

		return compact( 'added', 'updated', 'skipped' );
	}

	// ── Export ────────────────────────────────────────────────────────────────

	/**
	 * Generate an OPML 1.0 document from the current RSS subscriptions.
	 * Feeds with categories are nested inside <outline> folder elements;
	 * uncategorised feeds sit directly under <body>.
	 */
	public static function export(): string {
		$subs = (array) get_option( 'rs_rss_subscriptions', [] );

		$dom = new DOMDocument( '1.0', 'UTF-8' );
		$dom->formatOutput = true;

		$opml = $dom->createElement( 'opml' );
		$opml->setAttribute( 'version', '1.0' );
		$dom->appendChild( $opml );

		$head  = $dom->createElement( 'head' );
		$title = $dom->createElement( 'title' );
		$title->appendChild( $dom->createTextNode( get_bloginfo( 'name' ) . ' — subscriptions' ) );
		$head->appendChild( $title );
		$opml->appendChild( $head );

		$body = $dom->createElement( 'body' );
		$opml->appendChild( $body );

		// Group by first-level category; uncategorised feeds go last.
		$folders       = [];
		$uncategorised = [];

		foreach ( $subs as $sub ) {
			$cats = $sub['categories'] ?? [];
			if ( $cats ) {
				$folders[ $cats[0] ][] = $sub;
			} else {
				$uncategorised[] = $sub;
			}
		}

		foreach ( $folders as $folder_name => $folder_subs ) {
			$folder = $dom->createElement( 'outline' );
			$folder->setAttribute( 'text',  $folder_name );
			$folder->setAttribute( 'title', $folder_name );
			foreach ( $folder_subs as $sub ) {
				$folder->appendChild( self::feed_outline( $dom, $sub ) );
			}
			$body->appendChild( $folder );
		}

		foreach ( $uncategorised as $sub ) {
			$body->appendChild( self::feed_outline( $dom, $sub ) );
		}

		return $dom->saveXML();
	}

	private static function feed_outline( DOMDocument $dom, array $sub ): DOMElement {
		$el = $dom->createElement( 'outline' );
		$el->setAttribute( 'type',   'rss' );
		$el->setAttribute( 'text',   $sub['title'] ?: $sub['url'] );
		$el->setAttribute( 'title',  $sub['title'] ?: $sub['url'] );
		$el->setAttribute( 'xmlUrl', $sub['url'] );
		if ( ! empty( $sub['source_url'] ) ) {
			$el->setAttribute( 'htmlUrl', $sub['source_url'] );
		}
		// Preserve full category path as a comma-separated attribute for round-trips.
		$cats = $sub['categories'] ?? [];
		if ( $cats ) {
			$el->setAttribute( 'category', implode( ', ', array_map( 'sanitize_text_field', $cats ) ) );
		}
		return $el;
	}
}
