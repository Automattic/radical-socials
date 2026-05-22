<?php
/**
 * Title: Hidden post footer
 * Slug: radical-theme/hidden-post-footer
 * Inserter: no
 */
?>
<!-- wp:group {"align":"full","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull">
	<!-- wp:activitypub/reactions {"align":"full","className":"is-style-facepile"} -->
	<div class="wp-block-activitypub-reactions alignfull is-style-facepile">
		<!-- wp:heading {"level":3,"fontSize":"small"} -->
		<h3 class="wp-block-heading has-small-font-size"><?php echo esc_html__( 'Fediverse reactions', 'radical-socials' ); ?></h3>
		<!-- /wp:heading -->
	</div>
	<!-- /wp:activitypub/reactions -->
</div>
<!-- /wp:group -->

<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained","justifyContent":"left"}} -->
<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)">
	<!-- wp:post-terms {"term":"post_tag","separator":"  ","prefix":"<?php echo esc_attr__( 'Tags: ', 'radical-socials' ); ?>","className":"is-style-post-terms-1"} /-->
</div>
<!-- /wp:group -->
