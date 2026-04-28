<?php
/**
 * Frontend Editor
 *
 * Enqueues the React-based inline post composer for logged-in users on the
 * front end. Also outputs a `radicalSocials` JS object with the REST nonce
 * and root URL so the editor can make authenticated API calls.
 *
 * @package RadicalSocials
 */

defined( 'ABSPATH' ) || exit;

class Radical_Socials_Frontend_Editor {

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	public static function enqueue(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$asset_file = plugin_dir_path( __FILE__ ) . '../../build/frontend.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = include $asset_file;

		wp_enqueue_script(
			'radical-socials-frontend',
			plugin_dir_url( __FILE__ ) . '../../build/frontend.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'radical-socials-frontend',
			plugin_dir_url( __FILE__ ) . '../../build/frontend.css',
			[ 'wp-block-library', 'wp-components' ],
			$asset['version']
		);

		wp_localize_script(
			'radical-socials-frontend',
			'radicalSocials',
			[
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'restUrl' => rest_url(),
			]
		);
	}
}

Radical_Socials_Frontend_Editor::init();
