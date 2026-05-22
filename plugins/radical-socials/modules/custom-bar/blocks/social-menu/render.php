<?php
/**
 * Social Menu block — server-side render.
 *
 * Renders the same set of links as the custom bar (via
 * Radical_Socials_Custom_Bar::get_links()), as a styleable list.
 *
 * @package RadicalSocials
 */

defined( 'ABSPATH' ) || exit;

$links = Radical_Socials_Custom_Bar::get_links();
if ( empty( $links ) ) {
	return;
}
?>
<ul <?php echo get_block_wrapper_attributes(); ?>>
	<?php foreach ( $links as $link ) : ?>
		<li class="wp-block-radical-socials-social-menu__item is-rs-link-<?php echo esc_attr( $link['id'] ); ?>">
			<a href="<?php echo esc_url( $link['url'] ); ?>"<?php foreach ( $link['attrs'] ?? [] as $name => $value ) : ?> <?php echo esc_attr( $name ); ?>="<?php echo esc_attr( $value ); ?>"<?php endforeach; ?>>
				<span class="dashicons <?php echo esc_attr( $link['icon'] ); ?>" aria-hidden="true"></span>
				<span class="wp-block-radical-socials-social-menu__label"><?php echo esc_html( $link['label'] ); ?></span>
			</a>
		</li>
	<?php endforeach; ?>
</ul>
