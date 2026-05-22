<?php
/**
 * Favorite Feeds block — server-side render.
 *
 * Lists the visitor-side links for every feed/follow the site owner has
 * marked as a favorite. Empty output when nothing is favorited.
 *
 * Uses the return-string-from-function pattern (matching WP core's blocks
 * in wp-includes/blocks/*) so get_block_wrapper_attributes() passes
 * through the EscapeOutput sniffer cleanly without suppression.
 *
 * @package RadicalSocials
 */

defined( 'ABSPATH' ) || exit;

function radical_socials_render_favorite_feeds( $attributes, $content, $block ): string {
	unset( $attributes, $content, $block );

	$favorites = (array) get_option( 'rs_following_favorites', [] );
	if ( empty( $favorites ) ) {
		return '';
	}

	$items = [];

	// ── RSS subscriptions ────────────────────────────────────────────
	foreach ( (array) get_option( 'rs_rss_subscriptions', [] ) as $sub ) {
		if ( empty( $sub['title'] ) ) {
			continue;
		}
		if ( ! in_array( 'rss:' . md5( $sub['url'] ), $favorites, true ) ) {
			continue;
		}
		$items[] = [
			'title' => $sub['title'],
			'url'   => $sub['source_url'] ?: $sub['url'],
		];
	}

	// ── ActivityPub follows ──────────────────────────────────────────
	if ( class_exists( 'Activitypub\Collection\Following' ) ) {
		foreach ( $favorites as $fav ) {
			if ( ! str_starts_with( $fav, 'activitypub:' ) ) {
				continue;
			}
			$post_id = (int) substr( $fav, strlen( 'activitypub:' ) );
			$post    = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}
			$title = get_post_meta( $post_id, '_activitypub_acct', true ) ?: $post->post_title;
			if ( empty( $title ) ) {
				continue;
			}
			$items[] = [
				'title' => $title,
				'url'   => $post->guid,
			];
		}
	}

	if ( empty( $items ) ) {
		return '';
	}

	$wrapper_attributes = get_block_wrapper_attributes(
		[ 'class' => 'wp-block-categories wp-block-categories-list' ]
	);

	$list_items = '';
	foreach ( $items as $item ) {
		$list_items .= sprintf(
			'<li class="cat-item"><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></li>',
			esc_url( $item['url'] ),
			esc_html( $item['title'] )
		);
	}

	return sprintf( '<ul %1$s>%2$s</ul>', $wrapper_attributes, $list_items );
}
