<?php
/**
 * Favorite Feeds block — server-side render.
 *
 * Variables in scope (set by WordPress before including this file):
 *   $attributes  array     Block attributes.
 *   $content     string    Inner block content (unused — no inner blocks).
 *   $block       WP_Block  Block instance.
 *
 * @package RadicalSocials
 */

defined( 'ABSPATH' ) || exit;

$favorites = (array) get_option( 'rs_following_favorites', [] );
if ( empty( $favorites ) ) {
	return;
}

$items = [];

// ── RSS subscriptions ─────────────────────────────────────────────────────────
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

// ── ActivityPub follows ───────────────────────────────────────────────────────
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
	return;
}

$items_html = '';
foreach ( $items as $item ) {
	$items_html .= sprintf(
		'<li class="cat-item"><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></li>',
		esc_url( $item['url'] ),
		esc_html( $item['title'] )
	);
}

return sprintf(
	'<ul %s>%s</ul>',
	get_block_wrapper_attributes( [ 'class' => 'wp-block-categories wp-block-categories-list' ] ),
	$items_html
);
