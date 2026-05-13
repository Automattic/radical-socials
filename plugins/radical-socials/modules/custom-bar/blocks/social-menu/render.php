<?php
/**
 * Social Menu block — server-side render.
 *
 * Renders the same set of links as the custom bar (via
 * Radical_Socials_Custom_Bar::get_links()), as a styleable list.
 *
 * @package RadicalSocials
 */

$links = Radical_Socials_Custom_Bar::get_links();
if ( empty( $links ) ) {
	return;
}

$items = '';
foreach ( $links as $link ) {
	$extra = '';
	foreach ( $link['attrs'] ?? [] as $name => $value ) {
		$extra .= sprintf( ' %s="%s"', esc_attr( $name ), esc_attr( $value ) );
	}
	$items .= sprintf(
		'<li class="wp-block-radical-socials-social-menu__item is-rs-link-%s"><a href="%s"%s><span class="dashicons %s" aria-hidden="true"></span><span class="wp-block-radical-socials-social-menu__label">%s</span></a></li>',
		esc_attr( $link['id'] ),
		esc_url( $link['url'] ),
		$extra,
		esc_attr( $link['icon'] ),
		esc_html( $link['label'] )
	);
}

printf(
	'<ul %s>%s</ul>',
	get_block_wrapper_attributes(),
	$items
);
