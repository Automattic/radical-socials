<?php
/**
 * Uninstall handler for Heckl.
 *
 * Default behaviour is conservative: we remove only the small set of
 * plugin-internal options that have no meaning without the plugin loaded
 * (cron coordination flags, OAuth tokens, transients). Imported feed
 * items, favorites, ActivityPub actors, and the user's saved settings
 * stay in the database — for the Radical-Socials-as-archive-host use
 * case, the imported posts may be the user's only copy and silently
 * deleting them on uninstall would be a data-loss surprise.
 *
 * Users who want a full wipe opt in via Settings → Following → "Delete
 * all data when the plugin is uninstalled" (option `rs_purge_on_uninstall`).
 * When that's on, we also remove the rs_feed_item / rs_favorite / ap_actor
 * post types, the registered taxonomies' terms, and known rs_* options +
 * user-meta keys.
 *
 * This file is invoked by WordPress core when the user deletes the plugin
 * from the admin Plugins screen. It runs in the WordPress bootstrap but
 * not the full plugin runtime — no classes are loaded.
 *
 * Implementation note: we use WP's high-level APIs (wp_delete_post,
 * wp_delete_term, delete_option, delete_user_meta) instead of direct
 * $wpdb->query so meta/relationship rows go through the proper cleanup
 * path. Slightly slower than bulk SQL but correct.
 *
 * @package Heckl
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// ── Always-delete: plugin-internal coordination state ────────────────────
// These hold transient/runtime values that mean nothing once the plugin
// code is gone. Safe to wipe regardless of the purge toggle.
$heckl_always_delete_options = [
	'heckl_rewrite_version',
	'heckl_ap_defaults_applied',
	'rs_last_feed_fetch',
	'rs_rss_fetch_offset',
	'rs_ap_outbox_offset',
	'rs_ap_follow_user_id',
	'rs_auto_pretty_permalinks',
];
foreach ( $heckl_always_delete_options as $heckl_option ) {
	delete_option( $heckl_option );
}

// Transients live in the options table too; clean them up by name.
$heckl_always_delete_transients = [
	'rs_feed_refresh_lock',
	'rs_diag_fetch_elapsed',
	'rs_diag_fetch_error',
	'rs_diag_test_result',
	'rs_diag_feed_test_results',
];
foreach ( $heckl_always_delete_transients as $heckl_transient ) {
	delete_transient( $heckl_transient );
}

// Per-user welcome-redirect transients — best effort, only known users.
foreach ( get_users( [ 'fields' => [ 'ID' ] ] ) as $heckl_user ) {
	delete_transient( 'rs_welcome_redirect_' . (int) $heckl_user->ID );
	delete_transient( 'rs_settings_notice_' . (int) $heckl_user->ID );
}

// Scheduled events should already have been cleared on deactivation, but
// uninstall can also be invoked without prior deactivation in some flows.
wp_clear_scheduled_hook( 'rs_feed_fetch' );
wp_clear_scheduled_hook( 'rs_feed_refresh' );
wp_clear_scheduled_hook( 'rs_websub_renew' );

// Stop here unless the user opted in to a full purge. Preserves imported
// posts, favorites, follow lists, and per-user settings.
if ( ! get_option( 'rs_purge_on_uninstall', false ) ) {
	return;
}

// ── Full-purge path ──────────────────────────────────────────────────────
// Drop CPT posts, taxonomy terms, plugin options, and user meta.

// 1. Delete every post of our CPTs. wp_delete_post takes care of the
//    associated postmeta + term-relationships, so we don't have to.
$heckl_purge_post_types = [ 'rs_feed_item', 'rs_favorite', 'ap_actor' ];
foreach ( $heckl_purge_post_types as $heckl_post_type ) {
	// `numberposts` -1 retrieves all matching posts; safe here because
	// uninstall is invoked once and the runtime is single-threaded.
	$heckl_ids = get_posts( [
		'post_type'   => $heckl_post_type,
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
	] );
	foreach ( $heckl_ids as $heckl_id ) {
		wp_delete_post( (int) $heckl_id, true );
	}
}

// 2. Delete our custom taxonomy terms.
$heckl_purge_taxonomies = [ 'rs_source', 'rs_feed_type', 'rs_feed_category' ];
foreach ( $heckl_purge_taxonomies as $heckl_taxonomy ) {
	// get_terms() requires the taxonomy to be registered — at uninstall
	// time it isn't, because the plugin's init code never ran. Fall back
	// to a direct WP_Term_Query which doesn't care about registration.
	$heckl_query = new WP_Term_Query( [
		'taxonomy'   => $heckl_taxonomy,
		'hide_empty' => false,
		'fields'     => 'ids',
	] );
	foreach ( (array) $heckl_query->get_terms() as $heckl_term_id ) {
		wp_delete_term( (int) $heckl_term_id, $heckl_taxonomy );
	}
}

// 3. Remove all known rs_* / heckl_* options. Enumerated rather
//    than wildcard-SQL'd so each call goes through delete_option (which
//    invalidates the alloptions cache and fires the standard hooks).
$heckl_purge_options = [
	// Always-delete subset, in case the user toggled purge after the
	// initial deletes above (no-op if already gone).
	'heckl_rewrite_version',
	'heckl_ap_defaults_applied',
	'rs_last_feed_fetch',
	'rs_rss_fetch_offset',
	'rs_ap_outbox_offset',
	'rs_ap_follow_user_id',
	// Subscription + favorites state.
	'rs_rss_subscriptions',
	'rs_websub_subscriptions',
	'rs_following_favorites',
	// Privacy / visibility flags.
	'rs_following_public',
	'rs_purge_on_uninstall',
	'rs_auto_pretty_permalinks',
	// WP.com OAuth token + state.
	'rs_wpcom_access_token',
	'rs_wpcom_oauth_state',
];
foreach ( $heckl_purge_options as $heckl_option ) {
	delete_option( $heckl_option );
}

// 4. Per-user metadata: profile fields the plugin's settings page wrote.
//    Iterate users and call delete_user_meta — proper API path that
//    invalidates the user-meta cache.
$heckl_purge_user_meta_keys = [
	'rs_profile_handle',
	'rs_profile_avatar_id',
	'rs_profile_banner_id',
];
foreach ( get_users( [ 'fields' => [ 'ID' ] ] ) as $heckl_user ) {
	foreach ( $heckl_purge_user_meta_keys as $heckl_meta_key ) {
		delete_user_meta( (int) $heckl_user->ID, $heckl_meta_key );
	}
}
