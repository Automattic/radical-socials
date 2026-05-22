<?php
/**
 * Favorites Link block — server-side render.
 *
 * Outputs a navigation-compatible <li> that links to the /favorites page.
 * Intended to be inserted inside a core/navigation block.
 *
 * @package RadicalSocials
 */

defined( 'ABSPATH' ) || exit;

// Mirrors /following/ access: hide the link when the current viewer can't see
// the feed-item area (logged-out + public-following option off).
if ( ! Radical_Socials_Following::can_view_following() ) {
	return;
}

$favorites_page = get_page_by_path( 'favorites', OBJECT, 'page' );
$url            = $favorites_page ? get_permalink( $favorites_page->ID ) : home_url( '/favorites/' );
?>
<li <?php echo get_block_wrapper_attributes( [ 'class' => 'wp-block-navigation-item wp-block-navigation-link' ] ); ?>><a class="wp-block-navigation-item__content" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Favorites', 'radical-socials' ); ?></a></li>
<?php
