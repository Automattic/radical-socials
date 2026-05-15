<?php
/**
 * Feed Author Avatar block — renders the avatar image of an rs_feed_item's
 * author. Inside a Query loop the current post is set via the block context.
 *
 * @package RadicalSocials
 */

$post_id = $block->context['postId'] ?? get_the_ID();
if ( ! $post_id ) {
	return;
}

$icon_url = (string) get_post_meta( $post_id, '_rs_author_icon_url', true );
if ( '' === $icon_url ) {
	return;
}

$name    = (string) get_post_meta( $post_id, '_rs_author_name', true );
$url     = (string) get_post_meta( $post_id, '_rs_author_url', true );
$width   = isset( $attributes['width'] ) && '' !== $attributes['width'] ? $attributes['width'] : '48px';
$is_link = ! empty( $attributes['isLink'] );
$rounded = ! empty( $attributes['rounded'] );

$wrapper_class = $rounded ? 'is-rounded' : '';
$wrapper       = get_block_wrapper_attributes( [
	'class' => $wrapper_class,
	'style' => sprintf( '--rs-author-avatar-size:%s;', esc_attr( $width ) ),
] );

$img = sprintf(
	'<img class="wp-block-radical-socials-feed-author-avatar__img" src="%s" alt="%s" loading="lazy" decoding="async"/>',
	esc_url( $icon_url ),
	esc_attr( $name )
);

if ( $is_link && $url ) {
	$img = sprintf(
		'<a href="%s" target="_blank" rel="noopener noreferrer nofollow">%s</a>',
		esc_url( $url ),
		$img
	);
}

printf( '<span %s>%s</span>', $wrapper, $img );
