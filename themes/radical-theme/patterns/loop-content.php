<?php
/**
 * Title: Loop content
 * Slug: radical-theme/loop-content
 * Categories: 
 * Inserter: no
 */ 
?>

<!-- wp:group {"style":{"spacing":{"padding":{"right":"var:preset|spacing|30","left":"var:preset|spacing|30"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="padding-right:var(--wp--preset--spacing--30);padding-left:var(--wp--preset--spacing--30)">

<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between"}} -->
<div class="wp-block-group">
<!-- wp:post-terms {"term":"rs_feed_type","className":"rs-feed-type-badge"} /--><!-- wp:radical-socials/like-button /--></div>
<!-- /wp:group -->
	
<!-- wp:post-featured-image {"isLink":true,"style":{"spacing":{"margin":{"bottom":"var:preset|spacing|50"}}}} /-->
<!-- wp:post-title {"isLink":true,"style":{"spacing":{"margin":{"bottom":"0"}}}} /-->
<!-- wp:post-date {"metadata":{"bindings":{"datetime":{"source":"core/post-data","args":{"field":"date"}}}},"style":{"typography":{"fontStyle":"normal","fontWeight":"300"}},"fontSize":"small"} /-->

<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap"}} -->
<div class="wp-block-group"><!-- wp:radical-socials/feed-author-avatar /-->
<!-- wp:radical-socials/feed-author-name /--></div>
<!-- /wp:group -->

<!-- wp:group {"layout":{"type":"flex","orientation":"vertical"}} --><div class="wp-block-group">

<!-- wp:post-content {"metadata":{"ignoredHookedBlocks":["activitypub/reactions"]}} /-->

<!-- wp:read-more {"content":"Read more"} /-->

</div>
<!-- /wp:group -->

<!-- wp:activitypub/reactions {"className":"is-style-facepile"} -->
<div class="wp-block-activitypub-reactions is-style-facepile"><!-- wp:heading {"level":6} -->
<h6 class="wp-block-heading">Fediverse reactions</h6>
<!-- /wp:heading --></div>
<!-- /wp:activitypub/reactions -->

<!-- wp:group {"style":{"spacing":{"blockGap":"0.5em"}},"layout":{"type":"flex","flexWrap":"nowrap"}} -->
<div class="wp-block-group"><!-- wp:post-comments-link /--></div>
<!-- /wp:group -->

</div>
<!-- /wp:group -->

<!-- wp:separator {"className":"is-style-default","style":{"spacing":{"margin":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}}} -->
<hr class="wp-block-separator has-alpha-channel-opacity is-style-default" style="margin-top:var(--wp--preset--spacing--50);margin-bottom:var(--wp--preset--spacing--50)"/>
<!-- /wp:separator -->
