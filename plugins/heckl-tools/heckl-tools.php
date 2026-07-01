<?php
/**
 * Plugin Name:       Heckl Tools
 * Description:       Escape walled gardens with a self-hosted WordPress site that feels like home.
 * Version:           1.0.3
 * Requires at least: 6.7
 * Tested up to:      7.0
 * Requires PHP:      8.0
 * Author:            Automattic
 * Author URI:        https://automattic.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       heckl-tools
 * Domain Path:       /languages
 *
 * @package Heckl
 */

defined( 'ABSPATH' ) || exit;

// Bump when plugin rewrite registrations change and existing sites need a refresh.
const HECKL_REWRITE_VERSION = 'following-archives-v1';
const HECKL_ACTIVITYPUB_DEFAULT_BLOG_MODE_OPTION = 'heckl_activitypub_default_blog_mode';
const HECKL_ACTIVITYPUB_BLOG_IDENTIFIER_OPTION  = 'heckl_activitypub_blog_identifier';

function heckl_default_option_filter_name( string $option_name ): string {
	return 'default_option_' . $option_name;
}

// ── Modules ──────────────────────────────────────────────────────────────────

require_once __DIR__ . '/modules/custom-bar/class-custom-bar.php';
require_once __DIR__ . '/modules/settings/class-settings-page.php';
require_once __DIR__ . '/modules/frontend-editor/class-frontend-editor.php';
require_once __DIR__ . '/modules/following/loader.php';
require_once __DIR__ . '/modules/templates/class-block-templates.php';
require_once __DIR__ . '/modules/cover-photo/class-cover-photo-binding.php';
require_once __DIR__ . '/modules/profile-bindings/class-profile-bindings.php';

// Dev-only modules. The WP_CLI guard keeps the test harness completely
// inert during browser requests — it never even loads. modules/dev/ is
// also stripped from the production zip by bin/build-plugin-zip.sh,
// so this check is belt-and-suspenders against accidental inclusion.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/modules/dev/class-integration-tests.php';
}

// ─────────────────────────────────────────────────────────────────────────────

/**
 * Register the top-level admin menu page, pointing directly at the Settings
 * page so there is only one registered route (admin.php?page=heckl-settings).
 */
function heckl_add_menu_page(): void {
	add_menu_page(
		__( 'Heckl Tools', 'heckl-tools' ),
		__( 'Heckl Tools', 'heckl-tools' ),
		'manage_options',
		'heckl-settings',
		[ Radical_Socials_Settings_Page::class, 'render' ],
		'dashicons-share',
		30
	);
}
add_action( 'admin_menu', 'heckl_add_menu_page' );

function heckl_deactivate(): void {
	Radical_Socials_Following::deactivate();
	Radical_Socials_WebSub_Subscriber::deactivate();
}
register_deactivation_hook( __FILE__, 'heckl_deactivate' );

function heckl_register_rewrite_objects(): void {
	Radical_Socials_Following::register_cpt();
	Radical_Socials_Following::register_taxonomy();
	Radical_Socials_Favorites::register_cpt();
	Radical_Socials_Favorites::extend_taxonomies();
}

/**
 * Switch Plain permalinks to a structure that supports Heckl's pretty URLs.
 *
 * This is called only after an explicit admin choice. Existing non-Plain
 * permalink structures are preserved.
 */
function heckl_set_pretty_permalinks_if_plain(): bool {
	if ( '' !== (string) get_option( 'permalink_structure', '' ) ) {
		return false;
	}

	global $wp_rewrite;
	if ( $wp_rewrite instanceof WP_Rewrite ) {
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
	} else {
		update_option( 'permalink_structure', '/%postname%/' );
	}

	return true;
}

function heckl_activate(): void {
	heckl_register_rewrite_objects();

	// Trigger a one-shot redirect to our onboarding wizard on the next
	// admin page load. Transient (not option) so it auto-expires if the
	// user closes the tab before being redirected, and `for_blog` so the
	// flag is per-user — a network admin bulk-activating across many
	// sites doesn't get hijacked to every Welcome page in turn.
	set_transient( 'heckl_welcome_redirect_' . get_current_user_id(), 1, MINUTE_IN_SECONDS );

	// Pretty permalinks improve Heckl's /following/ and /favorites/ URLs,
	// but changing a site's permalink structure is a site-wide behaviour
	// change. Only apply it after the admin explicitly enables the option.
	if ( get_option( 'heckl_auto_pretty_permalinks', false ) ) {
		heckl_set_pretty_permalinks_if_plain();
	}

	flush_rewrite_rules();
	update_option( 'heckl_rewrite_version', HECKL_REWRITE_VERSION, false );
}
register_activation_hook( __FILE__, 'heckl_activate' );

/**
 * Default ActivityPub to a site-wide actor for Heckl installs without writing
 * to ActivityPub-owned options. If the site later saves ActivityPub's own
 * actor-mode option, that saved value takes precedence over this default.
 *
 * @param mixed $default_value Default option value from WordPress/ActivityPub.
 * @return mixed
 */
function heckl_default_activitypub_actor_mode( $default_value ) {
	if ( ! defined( 'ACTIVITYPUB_BLOG_MODE' ) ) {
		return $default_value;
	}
	if ( defined( 'ACTIVITYPUB_DISABLE_BLOG_USER' ) && ACTIVITYPUB_DISABLE_BLOG_USER ) {
		return $default_value;
	}

	return (bool) get_option( HECKL_ACTIVITYPUB_DEFAULT_BLOG_MODE_OPTION, true )
		? ACTIVITYPUB_BLOG_MODE
		: $default_value;
}
add_filter( heckl_default_option_filter_name( 'activitypub_actor_mode' ), 'heckl_default_activitypub_actor_mode' );

/**
 * Let Heckl's one-screen onboarding provide the Blog Profile username as an
 * ActivityPub default. ActivityPub's own saved option still wins if present.
 *
 * @param mixed $default_value Default option value from WordPress/ActivityPub.
 * @return mixed
 */
function heckl_default_activitypub_blog_identifier( $default_value ) {
	$identifier = sanitize_user( (string) get_option( HECKL_ACTIVITYPUB_BLOG_IDENTIFIER_OPTION, '' ), true );
	return '' !== $identifier ? $identifier : $default_value;
}
add_filter( heckl_default_option_filter_name( 'activitypub_blog_identifier' ), 'heckl_default_activitypub_blog_identifier' );

/**
 * Read ActivityPub's saved Blog Profile username without Heckl's default
 * filter. Used only to decide whether the wizard can edit the Heckl default
 * or should point to ActivityPub's own settings for an already-saved value.
 */
function heckl_saved_activitypub_blog_identifier(): string {
	remove_filter( 'default_option_activitypub_blog_identifier', 'heckl_default_activitypub_blog_identifier' );
	$identifier = sanitize_user( (string) get_option( 'activitypub_blog_identifier', '' ), true );
	add_filter( 'default_option_activitypub_blog_identifier', 'heckl_default_activitypub_blog_identifier' );

	return $identifier;
}

/**
 * Read a query-string parameter from the current request URI without
 * going through $_GET. The nonce-verification sniff fires on $_GET/$_POST
 * access regardless of intent, but parsing $_SERVER['REQUEST_URI'] is
 * appropriate when we just need to surface a UI flag from a URL the
 * server itself generated (a redirect target, a tab anchor) and aren't
 * processing form input. Always returns a sanitised string or null.
 */
function heckl_url_param( string $name ): ?string {
	$uri = isset( $_SERVER['REQUEST_URI'] )
		? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
		: '';
	if ( '' === $uri ) {
		return null;
	}
	$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );
	if ( '' === $query ) {
		return null;
	}
	$params = [];
	wp_parse_str( $query, $params );
	if ( ! isset( $params[ $name ] ) || ! is_string( $params[ $name ] ) ) {
		return null;
	}
	return sanitize_text_field( $params[ $name ] );
}

/**
 * One-shot redirect to the Welcome wizard right after the user activates
 * Heckl. Skips when WP is bulk-activating multiple plugins or
 * when the user landed via an AJAX/CLI/cron context. The flag is per-user
 * so other admins activating later don't inherit the redirect.
 */
function heckl_maybe_redirect_to_welcome(): void {
	if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return;
	}
	if ( null !== heckl_url_param( 'activate-multi' ) ) {
		return;
	}
	$user_id = get_current_user_id();
	if ( ! $user_id || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$key = 'heckl_welcome_redirect_' . $user_id;
	if ( ! get_transient( $key ) ) {
		return;
	}
	delete_transient( $key );
	wp_safe_redirect( admin_url( 'admin.php?page=heckl-settings&tab=welcome' ) );
	exit;
}
add_action( 'admin_init', 'heckl_maybe_redirect_to_welcome' );

/**
 * Add a "Settings" link to our row on the Plugins page. While the
 * onboarding wizard still has incomplete steps, the link points at the
 * Welcome tab so a returning user lands on whatever's left to do; once
 * every step is checked off, it points at the main Profile tab.
 *
 * @param string[] $links Existing action links (rendered as <a> nodes).
 * @return string[]
 */
function heckl_plugin_action_links( array $links ): array {
	if ( ! class_exists( 'Radical_Socials_Settings_Page' ) ) {
		return $links;
	}
	$status = Radical_Socials_Settings_Page::wizard_status();
	$tab    = $status['all'] ? 'profile' : 'welcome';
	$url    = admin_url( 'admin.php?page=heckl-settings&tab=' . $tab );
	array_unshift(
		$links,
		'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'heckl-tools' ) . '</a>'
	);
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'heckl_plugin_action_links' );

/**
 * Add an `heckl-plugin` class to <body>. Companion themes (Heckl,
 * others) scope their plugin-specific CSS under this selector so the
 * styling is inert when the plugin is missing. Mirrors WooCommerce's
 * `woocommerce-active` body class — the same pattern Storefront and
 * friends use to keep theme/plugin coupling graceful.
 *
 * @param string[] $classes
 * @return string[]
 */
function heckl_body_class( array $classes ): array {
	$classes[] = 'heckl-plugin';
	return $classes;
}
add_filter( 'body_class', 'heckl_body_class' );

function heckl_maybe_flush_rewrite_rules(): void {
	if ( HECKL_REWRITE_VERSION === get_option( 'heckl_rewrite_version' ) ) {
		return;
	}

	flush_rewrite_rules( false );
	update_option( 'heckl_rewrite_version', HECKL_REWRITE_VERSION, false );
}
add_action( 'init', 'heckl_maybe_flush_rewrite_rules', 20 );
