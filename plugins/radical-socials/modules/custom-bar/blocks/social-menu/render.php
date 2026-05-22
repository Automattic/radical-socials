<?php
/**
 * Social Menu block — server-side render.
 *
 * Renders the same set of links as the custom bar (via
 * Radical_Socials_Custom_Bar::get_links()), as a styleable list.
 *
 * Uses the return-string-from-function pattern (matching WP core's blocks
 * in wp-includes/blocks/*) so get_block_wrapper_attributes() passes
 * through the EscapeOutput sniffer cleanly without suppression.
 *
 * @package RadicalSocials
 */

defined( 'ABSPATH' ) || exit;

function radical_socials_render_social_menu( $attributes, $content, $block ): string {
	unset( $attributes, $content, $block );

	$links = Radical_Socials_Custom_Bar::get_links();
	if ( empty( $links ) ) {
		return '';
	}

	$list_items = '';
	foreach ( $links as $link ) {
		$extra_attrs = '';
		foreach ( $link['attrs'] ?? [] as $name => $value ) {
			$extra_attrs .= sprintf( ' %s="%s"', esc_attr( $name ), esc_attr( $value ) );
		}
		$list_items .= sprintf(
			'<li class="wp-block-radical-socials-social-menu__item is-rs-link-%1$s">'
				. '<a href="%2$s"%3$s>'
					. '<span class="dashicons %4$s" aria-hidden="true"></span>'
					. '<span class="wp-block-radical-socials-social-menu__label">%5$s</span>'
				. '</a>'
			. '</li>',
			esc_attr( $link['id'] ),
			esc_url( $link['url'] ),
			$extra_attrs,
			esc_attr( $link['icon'] ),
			esc_html( $link['label'] )
		);
	}

	return sprintf( '<ul %1$s>%2$s</ul>', get_block_wrapper_attributes(), $list_items );
}
