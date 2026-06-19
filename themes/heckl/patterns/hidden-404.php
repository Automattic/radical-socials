<?php
/**
 * Title: Hidden 404 content
 * Slug: heckl/hidden-404
 * Inserter: no
 */
?>
<!-- wp:group {"tagName":"main","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","right":"var:preset|spacing|40","bottom":"var:preset|spacing|60","left":"var:preset|spacing|40"}}},"layout":{"type":"constrained"}} -->
<main class="wp-block-group" style="padding-top:var(--wp--preset--spacing--60);padding-right:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--60);padding-left:var(--wp--preset--spacing--40)">

	<!-- wp:heading {"level":1,"textAlign":"center"} -->
	<h1 class="wp-block-heading has-text-align-center"><?php echo esc_html__( '404', 'heckl' ); ?></h1>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"align":"center"} -->
	<p class="has-text-align-center"><?php echo esc_html__( 'The page you\'re looking for doesn\'t exist.', 'heckl' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:search {"label":"<?php echo esc_attr__( 'Search', 'heckl' ); ?>","showLabel":false,"placeholder":"<?php echo esc_attr_x( 'Search…', 'placeholder text', 'heckl' ); ?>","buttonText":"<?php echo esc_attr__( 'Search', 'heckl' ); ?>","align":"center"} /-->

</main>
<!-- /wp:group -->
