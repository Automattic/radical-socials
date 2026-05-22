<?php
/**
 * Favorites Link block — server-side render.
 *
 * Outputs a navigation-compatible <li> that links to the /favorites page.
 * Intended to be inserted inside a core/navigation block.
 *
 * Uses the return-string-from-function pattern (matching WP core's blocks
 * in wp-includes/blocks/*) so the rendered HTML is composed and returned
 * rather than echoed inline — that's how get_block_wrapper_attributes()
 * passes through the EscapeOutput sniffer cleanly without suppression.
 *
 * @package RadicalSocials
 */

defined( 'ABSPATH' ) || exit;

function radical_socials_render_favorites_link( $attributes, $content, $block ): string {
	unset( $attributes, $content, $block );

	// Mirrors /following/ access: hide the link when the current viewer
	// can't see the feed-item area (logged-out + public-following off).
	if ( ! Radical_Socials_Following::can_view_following() ) {
		return '';
	}

	$favorites_page     = get_page_by_path( 'favorites', OBJECT, 'page' );
	$url                = $favorites_page ? get_permalink( $favorites_page->ID ) : home_url( '/favorites/' );
	$wrapper_attributes = get_block_wrapper_attributes(
		[ 'class' => 'wp-block-navigation-item wp-block-navigation-link' ]
	);

	return sprintf(
		'<li %1$s><a class="wp-block-navigation-item__content" href="%2$s">%3$s</a></li>',
		$wrapper_attributes,
		esc_url( $url ),
		esc_html__( 'Favorites', 'radical-socials-tools' )
	);
}
