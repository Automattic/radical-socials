<?php
/**
 * Following Link block — server-side render.
 *
 * Outputs a navigation-compatible <li> that links to the /following page.
 * Intended to be inserted inside a core/navigation block.
 *
 * @package RadicalSocials
 */

$following_page = get_page_by_path( 'following', OBJECT, 'page' );
$url            = $following_page ? get_permalink( $following_page->ID ) : home_url( '/following/' );

printf(
	'<li %s><a class="wp-block-navigation-item__content" href="%s">%s</a></li>',
	get_block_wrapper_attributes( [ 'class' => 'wp-block-navigation-item wp-block-navigation-link' ] ),
	esc_url( $url ),
	esc_html__( 'Following', 'radical-socials' )
);
