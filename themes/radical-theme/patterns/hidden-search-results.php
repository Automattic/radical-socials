<?php
/**
 * Title: Hidden search results
 * Slug: radical-theme/hidden-search-results
 * Inserter: no
 */
?>
<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->
<main class="wp-block-group">

	<!-- wp:group {"style":{"spacing":{"padding":{"right":"var:preset|spacing|30","left":"var:preset|spacing|30"}}},"layout":{"type":"constrained"}} -->
	<div class="wp-block-group" style="padding-right:var(--wp--preset--spacing--30);padding-left:var(--wp--preset--spacing--30)">
		<!-- wp:query-title {"type":"search"} /-->

		<!-- wp:search {"label":"<?php echo esc_attr__( 'Search', 'radical-theme' ); ?>","showLabel":false,"placeholder":"<?php echo esc_attr_x( 'Search…', 'placeholder text', 'radical-theme' ); ?>","buttonText":"<?php echo esc_attr__( 'Search', 'radical-theme' ); ?>"} /-->
	</div>
	<!-- /wp:group -->
	<!-- wp:query {"queryId":3,"query":{"perPage":10,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":true}} -->
	<div class="wp-block-query">
		<!-- wp:post-template -->
			<!-- wp:pattern {"slug":"radical-theme/loop-content"} /-->
		<!-- /wp:post-template -->

		<!-- wp:group {"style":{"spacing":{"padding":{"right":"var:preset|spacing|30","left":"var:preset|spacing|30"}}},"layout":{"type":"constrained"}} -->
		<div class="wp-block-group" style="padding-right:var(--wp--preset--spacing--30);padding-left:var(--wp--preset--spacing--30)">
			<!-- wp:query-no-results -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html__( 'No results found. Try a different search?', 'radical-theme' ); ?></p>
				<!-- /wp:paragraph -->
			<!-- /wp:query-no-results -->
		</div>
		<!-- /wp:group -->

		<!-- wp:query-pagination {"layout":{"type":"flex","justifyContent":"space-between"}} -->
			<!-- wp:query-pagination-previous /-->
			<!-- wp:query-pagination-next /-->
		<!-- /wp:query-pagination -->
	</div>
	<!-- /wp:query -->

</main>
<!-- /wp:group -->
