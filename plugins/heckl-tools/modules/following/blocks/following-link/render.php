<?php
/**
 * Following Link block — server-side render.
 *
 * Outputs a navigation-compatible <li> that links to the /following page.
 * Intended to be inserted inside a core/navigation block.
 *
 * Uses the return-string-from-function pattern (matching WP core's blocks
 * in wp-includes/blocks/*) so get_block_wrapper_attributes() passes
 * through the EscapeOutput sniffer cleanly without suppression.
 *
 * @package Heckl
 */

defined( 'ABSPATH' ) || exit;

function heckl_render_following_link( $attributes, $content, $block ): string {
	unset( $attributes, $content, $block );

	// Hide the link entirely when the current viewer can't see the
	// following page (logged-out visitor + public-following toggle off).
	if ( ! Radical_Socials_Following::can_view_following() ) {
		return '';
	}

	$following_page     = get_page_by_path( 'following', OBJECT, 'page' );
	$url                = $following_page ? get_permalink( $following_page->ID ) : home_url( '/following/' );
	$wrapper_attributes = get_block_wrapper_attributes(
		[ 'class' => 'wp-block-navigation-item wp-block-navigation-link' ]
	);

	return sprintf(
		'<li %1$s><a class="wp-block-navigation-item__content" href="%2$s">%3$s</a></li>',
		$wrapper_attributes,
		esc_url( $url ),
		esc_html__( 'Following', 'heckl-tools' )
	);
}
