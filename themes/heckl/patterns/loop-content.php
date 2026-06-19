<?php
/**
 * Title: Loop content
 * Slug: heckl/loop-content
 * Categories: query
 * Inserter: no
 */
?>

<!-- wp:group {"style":{"spacing":{"padding":{"right":"var:preset|spacing|30","left":"var:preset|spacing|30"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="padding-right:var(--wp--preset--spacing--30);padding-left:var(--wp--preset--spacing--30)">

<?php if ( heckl_is_tools_plugin_active() ) : ?>
<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between"}} -->
<div class="wp-block-group">
<!-- wp:post-terms {"term":"rs_feed_type","className":"rs-feed-type-badge"} /--><!-- wp:heckl/like-button /--></div>
<!-- /wp:group -->
<?php endif; ?>
	
<!-- wp:post-featured-image {"isLink":true,"style":{"spacing":{"margin":{"bottom":"var:preset|spacing|30"}}}} /-->
<!-- wp:post-title {"isLink":true,"style":{"spacing":{"margin":{"bottom":"0"}}}} /-->
<!-- wp:post-date {"metadata":{"bindings":{"datetime":{"source":"core/post-data","args":{"field":"date"}}}},"style":{"typography":{"fontStyle":"normal","fontWeight":"300"}},"fontSize":"small"} /-->

<!-- wp:group {"layout":{"type":"flex","orientation":"vertical"}} --><div class="wp-block-group">

<!-- wp:post-content {"metadata":{"ignoredHookedBlocks":["activitypub/reactions"]}} /-->

<!-- wp:read-more {"content":"<?php echo esc_attr__( 'Read more', 'heckl' ); ?>"} /-->

</div>
<!-- /wp:group -->

<!-- wp:activitypub/reactions {"className":"is-style-facepile"} -->
<div class="wp-block-activitypub-reactions is-style-facepile"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading"><?php echo esc_html__( 'Fediverse reactions', 'heckl' ); ?></h3>
<!-- /wp:heading --></div>
<!-- /wp:activitypub/reactions -->

<!-- wp:group {"style":{"spacing":{"blockGap":"0.5em","margin":{"top":"0","bottom":"0"}}},"layout":{"type":"flex","flexWrap":"nowrap"}} -->
<div class="wp-block-group" style="margin-top:0;margin-bottom:0"><!-- wp:post-comments-link /--></div>
<!-- /wp:group -->

</div>
<!-- /wp:group -->

<!-- wp:separator {"className":"is-style-default","style":{"spacing":{"margin":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|30"}}}} -->
<hr class="wp-block-separator has-alpha-channel-opacity is-style-default" style="margin-top:var(--wp--preset--spacing--30);margin-bottom:var(--wp--preset--spacing--30)"/>
<!-- /wp:separator -->
