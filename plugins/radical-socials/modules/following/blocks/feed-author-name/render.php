<?php
/**
 * Feed Author Name block — renders the display name of an rs_feed_item's
 * author. Inside a Query loop the current post is set via the block context.
 *
 * @package RadicalSocials
 */

$post_id = $block->context['postId'] ?? get_the_ID();
if ( ! $post_id ) {
	return;
}

$name = (string) get_post_meta( $post_id, '_rs_author_name', true );
if ( '' === $name ) {
	// Fallback to the rs_source term (e.g. "user@host" for AP, feed title for RSS).
	$terms = get_the_terms( $post_id, 'rs_source' );
	if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
		$name = $terms[0]->name;
	}
}
if ( '' === $name ) {
	return;
}

$is_link  = ! empty( $attributes['isLink'] );
$url      = (string) get_post_meta( $post_id, '_rs_author_url', true );
$wrapper  = get_block_wrapper_attributes();
$contents = esc_html( $name );

if ( $is_link && $url ) {
	$contents = sprintf(
		'<a href="%s" target="_blank" rel="noopener noreferrer nofollow">%s</a>',
		esc_url( $url ),
		$contents
	);
}

printf( '<span %s>%s</span>', $wrapper, $contents );
