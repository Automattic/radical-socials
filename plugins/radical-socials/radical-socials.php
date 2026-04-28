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

// ── Modules ──────────────────────────────────────────────────────────────────

require_once __DIR__ . '/modules/custom-bar/class-custom-bar.php';
require_once __DIR__ . '/modules/settings/class-settings-page.php';

// ─────────────────────────────────────────────────────────────────────────────

/**
 * Register the top-level admin menu page, pointing directly at the Settings
 * page so there is only one registered route (admin.php?page=radical-socials-settings).
 */
function radical_socials_add_menu_page(): void {
	add_menu_page(
		__( 'Radical Socials', 'radical-socials' ),
		__( 'Radical Socials', 'radical-socials' ),
		'manage_options',
		'radical-socials-settings',
		[ Radical_Socials_Settings_Page::class, 'render' ],
		'dashicons-share',
		30
	);
}
add_action( 'admin_menu', 'radical_socials_add_menu_page' );
