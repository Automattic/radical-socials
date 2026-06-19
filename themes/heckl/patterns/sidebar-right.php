<?php
/**
 * Title: Right sidebar — search, feed filter, favorite feeds
 * Slug: heckl/sidebar-right
 * Categories: query
 * Description: The theme's right-hand sidebar: a rounded search input, a feed-type filter list, and the visitor's favorite feeds. Useful as a self-contained discovery widget in any column.
 */
?>
<!-- wp:group {"tagName":"aside","style":{"spacing":{"padding":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|30"}}},"layout":{"type":"flex","orientation":"vertical"}} -->
<aside class="wp-block-group" style="padding-top:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--30)">

	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group">
		<!-- wp:search {"label":"<?php echo esc_attr__( 'Search', 'heckl' ); ?>","showLabel":false,"placeholder":"<?php echo esc_attr__( 'Search', 'heckl' ); ?>","buttonText":"<?php echo esc_attr__( 'Search', 'heckl' ); ?>","buttonPosition":"button-inside","buttonUseIcon":true,"style":{"border":{"radius":{"topLeft":"4em","topRight":"4em","bottomLeft":"4em","bottomRight":"4em"}},"spacing":{"margin":{"top":"0","bottom":"var:preset|spacing|30"}}},"fontSize":"small"} /-->
		</div>
	<!-- /wp:group -->

	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group">
		<?php if ( heckl_is_tools_plugin_active() ) : ?>
		<!-- wp:heading {"fontSize":"medium"} -->
		<h2 class="wp-block-heading has-medium-font-size"><?php echo esc_html__( 'Filter by source', 'heckl' ); ?></h2>
		<!-- /wp:heading -->

		<!-- wp:categories {"taxonomy":"rs_feed_type","style":{"typography":{"lineHeight":"2.5"},"spacing":{"padding":{"right":"0","left":"var:preset|spacing|30","top":"0","bottom":"0"}}}} /-->
		<?php else : ?>
		<!-- wp:heading {"fontSize":"medium"} -->
		<h2 class="wp-block-heading has-medium-font-size"><?php echo esc_html__( 'Categories', 'heckl' ); ?></h2>
		<!-- /wp:heading -->

		<!-- wp:categories {"style":{"typography":{"lineHeight":"2.5"},"spacing":{"padding":{"right":"0","left":"var:preset|spacing|30","top":"0","bottom":"0"}}}} /-->
		<?php endif; ?>
	</div>
	<!-- /wp:group -->

	<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|30"}}},"layout":{"type":"constrained"}} -->
	<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--30)">
		<!-- wp:heading {"fontSize":"medium"} -->
		<h2 class="wp-block-heading has-medium-font-size"><?php echo esc_html__( 'Favorite feeds', 'heckl' ); ?></h2>
		<!-- /wp:heading -->

		<?php if ( heckl_is_tools_plugin_active() ) : ?>
		<!-- wp:heckl/favorite-feeds {"style":{"spacing":{"blockGap":"var:preset|spacing|30","padding":{"left":"var:preset|spacing|30"}}}} /-->
		<?php else : ?>
		<!-- wp:navigation {"ariaLabel":"<?php echo esc_attr__( 'Favorite feeds', 'heckl' ); ?>","overlayMenu":"never","style":{"spacing":{"blockGap":"var:preset|spacing|30","padding":{"left":"var:preset|spacing|30"}}},"fontSize":"small","layout":{"type":"flex","orientation":"vertical"}} -->
			<!-- wp:navigation-link {"label":"<?php echo esc_attr__( 'Favorite feed', 'heckl' ); ?>","url":"#"} /-->
			<!-- wp:navigation-link {"label":"<?php echo esc_attr__( 'Another feed', 'heckl' ); ?>","url":"#"} /-->
			<!-- wp:navigation-link {"label":"<?php echo esc_attr__( 'Reading list', 'heckl' ); ?>","url":"#"} /-->
		<!-- /wp:navigation -->
		<?php endif; ?>
	</div>
	<!-- /wp:group -->

</aside>
<!-- /wp:group -->
