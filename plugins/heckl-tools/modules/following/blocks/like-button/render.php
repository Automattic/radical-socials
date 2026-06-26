<?php
/**
 * Like Button block — server-side render.
 *
 * Only renders for the site owner viewing an heckl_feed_item post.
 * The block is hooked into core/post-template so it appears on every feed
 * card; the early-return guards keep it invisible everywhere else.
 *
 * @package Heckl
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
	return;
}

$post_id = get_the_ID();
if ( ! $post_id ) {
	return;
}

$post = get_post( $post_id );
if ( ! $post || 'heckl_feed_item' !== $post->post_type ) {
	return;
}

$heckl_favorited = Radical_Socials_Favorites::is_favorited( $post_id );

wp_interactivity_state( 'heckl/like-button', [
	'toggleUrl' => rest_url( 'heckl/v1/favorites/toggle' ),
	'nonce'     => wp_create_nonce( 'wp_rest' ),
] );

$heckl_context = wp_json_encode( [
	'postId'    => $post_id,
	'favorited' => $heckl_favorited,
] );
?>
<div
	class="heckl-like-button-wrap wp-block-heckl-like-button"
	data-wp-interactive="heckl/like-button"
	data-wp-context="<?php echo esc_attr( $heckl_context ); ?>"
>
	<button
		type="button"
		class="heckl-like-btn"
		data-wp-on--click="actions.toggle"
		data-wp-class--heckl-liked="context.favorited"
		aria-label="<?php esc_attr_e( 'Save to favorites', 'heckl-tools' ); ?>"
	>
		<span aria-hidden="true" class="heckl-like-icon-filled" data-wp-bind--hidden="!context.favorited"<?php echo $heckl_favorited ? '' : ' hidden'; ?>>♥</span>
		<span aria-hidden="true" class="heckl-like-icon-empty"  data-wp-bind--hidden="context.favorited"<?php echo $heckl_favorited ? ' hidden' : ''; ?>>♡</span>
	</button>
</div>
