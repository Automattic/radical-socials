<?php
/**
 * Following Link block — server-side render.
 *
 * Outputs a navigation-compatible <li> that links to the /following page.
 * Intended to be inserted inside a core/navigation block.
 *
 * @package RadicalSocials
 */

defined( 'ABSPATH' ) || exit;

// Hide the link entirely when the current viewer can't see the following
// page (logged-out visitor + the "public following" toggle is off).
if ( ! Radical_Socials_Following::can_view_following() ) {
	return;
}

$following_page = get_page_by_path( 'following', OBJECT, 'page' );
$url            = $following_page ? get_permalink( $following_page->ID ) : home_url( '/following/' );
?>
<li <?php echo get_block_wrapper_attributes( [ 'class' => 'wp-block-navigation-item wp-block-navigation-link' ] ); ?>><a class="wp-block-navigation-item__content" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Following', 'radical-socials' ); ?></a></li>
<?php
