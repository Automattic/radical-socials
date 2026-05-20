<?php
/**
 * Title: Default sidebar — search, feed filter, favorite feeds
 * Slug: radical-theme/sidebar-default
 * Categories: query
 * Description: The theme's right-hand sidebar: a rounded search input, a feed-type filter list, and the visitor's favorite feeds. Useful as a self-contained discovery widget in any column.
 */
?>
<!-- wp:group {"tagName":"aside","style":{"spacing":{"padding":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|30"}}},"layout":{"type":"flex","orientation":"vertical"}} -->
<aside class="wp-block-group" style="padding-top:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--30)">

	<!-- wp:search {"label":"<?php echo esc_attr__( 'Search', 'radical-theme' ); ?>","showLabel":false,"placeholder":"<?php echo esc_attr__( 'Search', 'radical-theme' ); ?>","width":100,"widthUnit":"%","buttonText":"<?php echo esc_attr__( 'Search', 'radical-theme' ); ?>","buttonPosition":"button-inside","buttonUseIcon":true,"style":{"border":{"radius":{"topLeft":"4em","topRight":"4em","bottomLeft":"4em","bottomRight":"4em"}}},"fontSize":"small"} /-->

	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group">
		<!-- wp:heading {"fontSize":"medium"} -->
		<h2 class="wp-block-heading has-medium-font-size"><?php echo esc_html__( 'Filter by source', 'radical-theme' ); ?></h2>
		<!-- /wp:heading -->

		<!-- wp:categories {"taxonomy":"rs_feed_type","style":{"typography":{"lineHeight":"2"},"spacing":{"padding":{"right":"0","left":"0"}}}} /-->
	</div>
	<!-- /wp:group -->

	<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|30"}}},"layout":{"type":"constrained"}} -->
	<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--30)">
		<!-- wp:heading {"fontSize":"medium"} -->
		<h2 class="wp-block-heading has-medium-font-size"><?php echo esc_html__( 'Favorite feeds', 'radical-theme' ); ?></h2>
		<!-- /wp:heading -->

		<!-- wp:radical-socials/favorite-feeds {"style":{"spacing":{"blockGap":"var:preset|spacing|30","padding":{"left":"0"}}}} /-->
	</div>
	<!-- /wp:group -->

</aside>
<!-- /wp:group -->
