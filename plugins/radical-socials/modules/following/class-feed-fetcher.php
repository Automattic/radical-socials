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

		if ( empty( $items ) ) {
			update_option( 'rs_last_feed_fetch', time(), false );
			return;
		}

		foreach ( $items as $item ) {
			self::upsert( $item );
		}

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

	private static function upsert( array $item ): void {
		$guid = $item['guid'] ?? md5( $item['url'] ?? uniqid() );

		// Check for an existing post with the same slug.
		$existing = get_posts(
			[
				'post_type'   => 'rs_feed_item',
				'post_status' => 'any',
				'name'        => $guid,
				'fields'      => 'ids',
				'numberposts' => 1,
			]
		);

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

		if ( $existing ) {
			$post_data['ID'] = $existing[0];
			wp_update_post( $post_data );
			$post_id = $existing[0];
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
		$common = [ 'class' => true, 'id' => true, 'style' => true, 'dir' => true, 'lang' => true ];
		return [
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
