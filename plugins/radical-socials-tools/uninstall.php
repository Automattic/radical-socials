<?php
/**
 * Uninstall handler for Radical Socials.
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
 * post types, the registered taxonomies' terms, and all rs_* options.
 *
 * This file is invoked by WordPress core when the user deletes the plugin
 * from the admin Plugins screen. It runs in the WordPress bootstrap but
 * not the full plugin runtime — no classes are loaded.
 *
 * @package RadicalSocials
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Always remove: plugin-internal coordination state that can't outlive the
// plugin code. These all hold transient/runtime values and would point at
// nothing if left behind.
$always_delete_options = [
	'radical_socials_rewrite_version',
	'radical_socials_ap_defaults_applied',
	'rs_last_feed_fetch',
	'rs_rss_fetch_offset',
	'rs_ap_outbox_offset',
	'rs_ap_follow_user_id',
];
foreach ( $always_delete_options as $option ) {
	delete_option( $option );
}

// Transients live in options table too; clean those up explicitly.
delete_transient( 'rs_feed_refresh_lock' );
delete_transient( 'rs_diag_fetch_elapsed' );
delete_transient( 'rs_diag_fetch_error' );
delete_transient( 'rs_diag_test_result' );
delete_transient( 'rs_diag_feed_test_results' );

// Per-user welcome-redirect transients — best effort, only known users.
foreach ( get_users( [ 'fields' => [ 'ID' ] ] ) as $user ) {
	delete_transient( 'rs_welcome_redirect_' . (int) $user->ID );
}

// Scheduled events should already have been cleared on deactivation, but
// uninstall can also be invoked without prior deactivation in some flows.
wp_clear_scheduled_hook( 'rs_feed_fetch' );
wp_clear_scheduled_hook( 'rs_feed_refresh' );
wp_clear_scheduled_hook( 'rs_websub_renew' );

// Stop here unless the user opted in to a full purge. Preserves imported
// posts, favorites, follow lists, and the user's settings.
if ( ! get_option( 'rs_purge_on_uninstall', false ) ) {
	return;
}

// Full purge path. Drop CPT posts, taxonomy terms, and remaining rs_*
// options. Done with direct SQL because the WP post API would re-fire
// hooks from a plugin that's already gone.
global $wpdb;

// 1. Delete every post of our CPTs (feed items, favorites, AP actors).
$post_types = [ 'rs_feed_item', 'rs_favorite', 'ap_actor' ];
$post_ids   = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type IN (" . implode( ',', array_fill( 0, count( $post_types ), '%s' ) ) . ')',
		...$post_types
	)
);
if ( $post_ids ) {
	$id_placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->posts}    WHERE ID         IN ($id_placeholders)", ...$post_ids ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id    IN ($id_placeholders)", ...$post_ids ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($id_placeholders)", ...$post_ids ) );
}

// 2. Delete our custom taxonomy terms.
$taxonomies = [ 'rs_source', 'rs_feed_type', 'rs_feed_category' ];
foreach ( $taxonomies as $taxonomy ) {
	$term_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
			$taxonomy
		)
	);
	if ( $term_ids ) {
		$placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->terms}             WHERE term_id IN ($placeholders)", ...$term_ids ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->term_taxonomy}     WHERE term_id IN ($placeholders)", ...$term_ids ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ($placeholders)", ...$term_ids ) );
	}
}

// 3. Drop all remaining rs_* options. Using LIKE because we want
// every per-plugin option even if we didn't enumerate it above.
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE 'rs\\_%'
	    OR option_name LIKE 'radical\\_socials\\_%'
	    OR option_name LIKE '\\_transient\\_rs\\_%'
	    OR option_name LIKE '\\_transient\\_timeout\\_rs\\_%'"
);

// 4. Per-user metadata (welcome-tab dismissals etc., if any).
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'rs\\_%' OR meta_key LIKE 'radical\\_socials\\_%'" );

// 5. The privacy-page tag for Radical Socials, if registered.
delete_option( 'rs_following_public' );
delete_option( 'rs_purge_on_uninstall' );
