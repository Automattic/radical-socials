<?php
/**
 * Title: Default footer
 * Slug: radical-theme/footer-default
 * Categories: footer
 * Block Types: core/template-part/footer
 * Description: Centered credit line with a subtle top divider, sized to match the rest of the theme's chrome.
 */
?>
<!-- wp:group {"tagName":"footer","className":"has-border-color","style":{"spacing":{"padding":{"top":"var:preset|spacing|40","bottom":"var:preset|spacing|40"}},"border":{"top":{"width":"1px","color":"var:preset|color|contrast-3"},"right":{"width":"0px","style":"none"},"bottom":{"width":"0px","style":"none"},"left":{"width":"0px","style":"none"}}},"layout":{"type":"constrained"}} -->
<footer class="wp-block-group has-border-color" style="border-top-color:var(--wp--preset--color--contrast-3);border-top-width:1px;border-right-style:none;border-right-width:0px;border-bottom-style:none;border-bottom-width:0px;border-left-style:none;border-left-width:0px;padding-top:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--40)">
	<!-- wp:paragraph {"className":"has-contrast-1-color has-text-color","style":{"typography":{"textAlign":"center"}},"textColor":"contrast-1","fontSize":"small"} -->
	<p class="has-text-align-center has-contrast-1-color has-text-color has-small-font-size">
		<?php
		printf(
			/* translators: %s: WordPress link. */
			esc_html__( 'Proudly powered by %s', 'radical-theme' ),
			'<a href="' . esc_url( __( 'https://wordpress.org', 'radical-theme' ) ) . '">' . esc_html__( 'WordPress', 'radical-theme' ) . '</a>'
		);
		?>
	</p>
	<!-- /wp:paragraph -->
</footer>
<!-- /wp:group -->
