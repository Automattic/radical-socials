<?php
/**
 * Profile Block Bindings — Fediverse handle + bio.
 *
 * Exposes the site's public profile fields as Block Bindings sources so
 * `core/paragraph` blocks in a template can render them dynamically and
 * keep all of paragraph's native tools (typography, color, alignment, link).
 *
 * Sources registered:
 *
 *   - `heckl/profile-handle` → "@user@host" for the AP blog
 *     actor in Blog Mode. Matches what AP federates as the actor's
 *     WebFinger handle, so the value the user sees in this template
 *     equals the address other Fediverse users follow.
 *
 *   - `heckl/profile-bio` → the site owner's bio (the
 *     `description` user meta — the same field WP exposes as "Bio" in
 *     wp-admin → Users → Profile, and the same field our Profile tab
 *     surfaces as "Bio").
 *
 * Usage in a theme template (or via the editor's "Connect to source"
 * UI in the Block Inspector's Attributes panel for the paragraph's
 * Content attribute):
 *
 *   <!-- wp:paragraph {"metadata":{"bindings":{
 *           "content": { "source": "heckl/profile-handle" }
 *       }}} -->
 *   <p>@placeholder@example.com</p>
 *   <!-- /wp:paragraph -->
 *
 *   <!-- wp:paragraph {"metadata":{"bindings":{
 *           "content": { "source": "heckl/profile-bio" }
 *       }}} -->
 *   <p>Placeholder bio</p>
 *   <!-- /wp:paragraph -->
 *
 * If a value isn't set (no bio, no blog identifier), the source
 * returns null and the paragraph falls back to whatever placeholder
 * the saved markup contains. Themes can hide the empty fallback with
 * a `:empty` selector or a wrapping group with conditional display.
 *
 * @package Heckl
 */

defined( 'ABSPATH' ) || exit;

class Radical_Socials_Profile_Bindings {

	const HANDLE_SOURCE = 'heckl/profile-handle';
	const BIO_SOURCE    = 'heckl/profile-bio';

	public static function init(): void {
		add_action( 'init', [ self::class, 'register_sources' ] );
	}

	public static function register_sources(): void {
		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			return;
		}

		register_block_bindings_source(
			self::HANDLE_SOURCE,
			[
				'label'              => __( 'Fediverse handle', 'heckl-tools' ),
				'get_value_callback' => [ self::class, 'get_handle' ],
			]
		);

		register_block_bindings_source(
			self::BIO_SOURCE,
			[
				'label'              => __( 'Bio', 'heckl-tools' ),
				'get_value_callback' => [ self::class, 'get_bio' ],
			]
		);
	}

	/**
	 * Compute `@<blog_identifier>@<host>` matching the AP plugin's Blog
	 * Mode WebFinger handle so the value the visitor sees on the page
	 * equals what they would type to follow this site from Mastodon
	 * et al. Returns null if either piece is missing so the paragraph's
	 * placeholder shows through.
	 */
	public static function get_handle( $source_args, $block_instance, $attribute_name ): ?string {
		unset( $source_args, $block_instance, $attribute_name );

		$identifier = (string) get_option( 'activitypub_blog_identifier', '' );
		if ( '' === $identifier ) {
			// AP's own fallback when no explicit identifier has been set
			// — the slugified blog name.
			$identifier = sanitize_title( (string) get_bloginfo( 'name' ) );
		}
		if ( '' === $identifier ) {
			return null;
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return null;
		}

		return '@' . $identifier . '@' . $host;
	}

	/**
	 * Resolve the site owner's bio from `description` user meta. Empty
	 * bio → null so the paragraph's placeholder text remains.
	 */
	public static function get_bio( $source_args, $block_instance, $attribute_name ): ?string {
		unset( $source_args, $block_instance, $attribute_name );

		$user_id = self::resolve_user_id();
		if ( ! $user_id ) {
			return null;
		}

		$bio = trim( (string) get_user_meta( $user_id, 'description', true ) );
		return '' !== $bio ? $bio : null;
	}

	/**
	 * Which user's profile field to read. Default: first administrator
	 * (the Blog Mode owner). Filterable so a theme can swap, e.g. to
	 * the queried author on `is_author()` pages.
	 */
	private static function resolve_user_id(): int {
		$default = 0;
		$admins  = get_users( [
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		] );
		if ( $admins ) {
			$default = (int) $admins[0];
		}

		/**
		 * Filters which user's profile the `heckl/profile-*`
		 * binding sources read from. Return 0 to render nothing.
		 *
		 * @param int $user_id Default user id (first administrator).
		 */
		return (int) apply_filters( 'heckl_profile_user_id', $default );
	}
}

Radical_Socials_Profile_Bindings::init();
