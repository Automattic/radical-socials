<?php
/**
 * Favorites Link block — server-side render.
 *
 * Outputs a navigation-compatible <li> that links to the /favorites page.
 * Intended to be inserted inside a core/navigation block.
 *
 * @package RadicalSocials
 */

$favorites_page = get_page_by_path( 'favorites', OBJECT, 'page' );
$url            = $favorites_page ? get_permalink( $favorites_page->ID ) : home_url( '/favorites/' );

printf(
	'<li %s><a class="wp-block-navigation-item__content" href="%s">%s</a></li>',
	get_block_wrapper_attributes( [ 'class' => 'wp-block-navigation-item wp-block-navigation-link' ] ),
	esc_url( $url ),
	esc_html__( 'Favorites', 'radical-socials' )
);
