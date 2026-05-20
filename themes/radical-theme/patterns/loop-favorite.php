<?php
/**
 * Title: Loop favorite
 * Slug: radical-theme/loop-favorite
 * Categories: query
 * Inserter: no
 */
?>
<!-- wp:post-featured-image {"isLink":true,"style":{"spacing":{"margin":{"bottom":"var:preset|spacing|50"}}}} /-->

<!-- wp:post-title {"isLink":true,"style":{"spacing":{"margin":{"bottom":"0"}}}} /-->

<!-- wp:post-date {"metadata":{"bindings":{"datetime":{"source":"core/post-data","args":{"field":"date"}}}},"style":{"typography":{"fontStyle":"normal","fontWeight":"300"}},"fontSize":"small"} /-->

<!-- wp:spacer {"height":"var:preset|spacing|40"} -->
<div style="height:var(--wp--preset--spacing--40)" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:post-content {"metadata":{"ignoredHookedBlocks":["activitypub/reactions"]}} /-->

<!-- wp:spacer {"height":"var:preset|spacing|40"} -->
<div style="height:var(--wp--preset--spacing--40)" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:read-more {"content":"<?php echo esc_attr__( 'Read more', 'radical-theme' ); ?>"} /-->

<!-- wp:spacer {"height":"var:preset|spacing|40"} -->
<div style="height:var(--wp--preset--spacing--40)" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:group {"style":{"spacing":{"blockGap":"0.5em"}},"layout":{"type":"flex","orientation":"vertical"}} -->
<div class="wp-block-group">
	<!-- wp:post-terms {"term":"rs_feed_type","prefix":"<?php echo esc_attr__( 'Origin: ', 'radical-theme' ); ?>","className":"rs-feed-type-badge"} /-->
	<!-- wp:post-terms {"term":"rs_source","prefix":"<?php echo esc_attr__( 'Author: ', 'radical-theme' ); ?>","className":"rs-feed-source-label"} /-->
</div>
<!-- /wp:group -->

<!-- wp:separator {"className":"is-style-default","style":{"spacing":{"margin":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}}} -->
<hr class="wp-block-separator has-alpha-channel-opacity is-style-default" style="margin-top:var(--wp--preset--spacing--50);margin-bottom:var(--wp--preset--spacing--50)"/>
<!-- /wp:separator -->

<!-- wp:radical-socials/like-button /-->
