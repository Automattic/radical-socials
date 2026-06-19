<?php
/**
 * Title: Left sidebar
 * Slug: heckl/sidebar-left
 * Categories: query
 * Inserter: no
 */
?>

<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap"}} -->
<div class="wp-block-group"><!-- wp:site-logo {"width":48,"className":"is-style-rounded"} /-->

<!-- wp:site-title {"style":{"typography":{"fontStyle":"normal","fontWeight":"400"}},"fontSize":"medium"} /--></div>
<!-- /wp:group -->

<?php if ( heckl_is_tools_plugin_active() ) : ?>
<!-- wp:heckl/social-menu {"style":{"elements":{"link":{"color":{"text":"var:preset|color|contrast-2"}}},"spacing":{"margin":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|30"}}},"textColor":"contrast-2","fontSize":"medium","layout":{"type":"flex","orientation":"vertical"}} /-->
<?php endif; ?>
