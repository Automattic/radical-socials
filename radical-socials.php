<?php
/**
 * Plugin Name: Radical Socials
 * Plugin URI:  https://github.com/Automattic/radical-socials
 * Description: Escape walled gardens with a self-hosted WordPress site that feels like home.
 * Version:     0.1.0
 * Author:      Automattic
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: radical-socials
 *
 * @package RadicalSocials
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the top-level admin menu page.
 */
function radical_socials_add_menu_page() {
	add_menu_page(
		__( 'Radical Socials', 'radical-socials' ),
		__( 'Radical Socials', 'radical-socials' ),
		'manage_options',
		'radical-socials',
		'radical_socials_render_page',
		'dashicons-share',
		30
	);
}
add_action( 'admin_menu', 'radical_socials_add_menu_page' );

/**
 * Render the admin page shell. The React app mounts here.
 */
function radical_socials_render_page() {
	echo '<div id="radical-socials-app"></div>';
}

/**
 * Enqueue the dashboard script only on our admin page.
 *
 * @param string $hook The current admin page hook.
 */
function radical_socials_enqueue_scripts( $hook ) {
	if ( 'toplevel_page_radical-socials' !== $hook ) {
		return;
	}

	$asset_file = plugin_dir_path( __FILE__ ) . 'build/index.asset.php';

	if ( ! file_exists( $asset_file ) ) {
		return;
	}

	$asset = include $asset_file;

	wp_enqueue_script(
		'radical-socials',
		plugin_dir_url( __FILE__ ) . 'build/index.js',
		$asset['dependencies'],
		$asset['version'],
		true
	);
}
add_action( 'admin_enqueue_scripts', 'radical_socials_enqueue_scripts' );
