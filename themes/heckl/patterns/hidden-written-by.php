<?php
/**
 * Title: Hidden written by
 * Slug: heckl/hidden-written-by
 * Inserter: no
 */
?>
<!-- wp:group {"style":{"spacing":{"blockGap":"0.2em"}},"fontSize":"small","layout":{"type":"flex","flexWrap":"wrap"}} -->
<div class="wp-block-group has-small-font-size">
	<!-- wp:paragraph -->
	<p><?php echo esc_html_x( 'Written by', 'post meta byline', 'heckl' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:post-author-name {"isLink":true} /-->

	<!-- wp:paragraph -->
	<p><?php echo esc_html_x( 'in', 'as in "Written by [author] in [category]"', 'heckl' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:post-terms {"term":"category","style":{"typography":{"fontWeight":"300"}}} /-->
</div>
<!-- /wp:group -->
