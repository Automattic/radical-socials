<?php
/**
 * Flatten radical-theme's plugin-specific templates into plugin-default
 * block templates.
 *
 * Radical Socials ships a plugin that should work on any active theme.
 * Most themes won't define `archive-rs_feed_item` or `archive-rs_favorite`,
 * so without a fallback, visiting /following or /favorites on a vanilla
 * theme falls back to the generic archive layout — which is missing the
 * sidebars, the feed query, and the navigation chrome that make this
 * actually feel like a social product.
 *
 * Solution: at build time, take the theme's authoritative version of each
 * plugin-specific template, inline every `<!-- wp:template-part -->` and
 * `<!-- wp:pattern -->` reference, and write the resulting self-contained
 * HTML into plugins/radical-socials/templates/. The plugin then registers
 * each one via register_block_template() (WP 6.7+). When the active theme
 * provides its own archive-rs_feed_item.html, WP's normal theme-over-plugin
 * resolution applies and the theme version wins.
 *
 * Theme is the source of truth: editing the theme + re-running this script
 * is the only supported way to update what the plugin ships. We never edit
 * the generated HTML by hand.
 *
 * Pattern PHP files use WP i18n helpers (__, _e, esc_attr__, ...). We're
 * running in CLI without WP loaded, so we shim those to identity/echo.
 * That intentionally bakes the source-locale strings into the output —
 * generated templates aren't expected to be re-translated; the theme's
 * source PHP patterns are.
 *
 * Usage:  php bin/build-templates.php
 */

declare(strict_types=1);

const FLATTEN_TEMPLATES = [ 'archive-rs_feed_item', 'archive-rs_favorite' ];

$root       = realpath( __DIR__ . '/..' );
$theme_dir  = $root . '/themes/radical-theme';
$output_dir = $root . '/plugins/radical-socials/templates';

// Shim the WP i18n / escape helpers the theme's pattern PHP files call.
// We're not the actual rendering path, so the goal is just to not crash
// and to produce stable output. The shims return their first arg
// unchanged (i18n) or apply htmlspecialchars (escape variants).
foreach (
	[
		'__'           => fn( $t, $d = null ) => (string) $t,
		'_x'           => fn( $t, $c = '', $d = null ) => (string) $t,
		'_n'           => fn( $s, $p, $n, $d = null ) => 1 === (int) $n ? (string) $s : (string) $p,
		'esc_html__'   => fn( $t, $d = null ) => htmlspecialchars( (string) $t, ENT_QUOTES ),
		'esc_attr__'   => fn( $t, $d = null ) => htmlspecialchars( (string) $t, ENT_QUOTES ),
		'esc_html'     => fn( $t ) => htmlspecialchars( (string) $t, ENT_QUOTES ),
		'esc_attr'     => fn( $t ) => htmlspecialchars( (string) $t, ENT_QUOTES ),
		'esc_url'      => fn( $t ) => (string) $t,
	] as $name => $impl
) {
	if ( ! function_exists( $name ) ) {
		eval( "function $name( ...\$args ) { return (\$GLOBALS['__rs_shims']['$name'])(...\$args); }" );
	}
	$GLOBALS['__rs_shims'][ $name ] = $impl;
}
foreach (
	[
		'_e'         => fn( $t, $d = null ) => print (string) $t,
		'_ex'        => fn( $t, $c = '', $d = null ) => print (string) $t,
		'esc_html_e' => fn( $t, $d = null ) => print htmlspecialchars( (string) $t, ENT_QUOTES ),
		'esc_attr_e' => fn( $t, $d = null ) => print htmlspecialchars( (string) $t, ENT_QUOTES ),
	] as $name => $impl
) {
	if ( ! function_exists( $name ) ) {
		eval( "function $name( ...\$args ) { (\$GLOBALS['__rs_shims']['$name'])(...\$args); }" );
	}
	$GLOBALS['__rs_shims'][ $name ] = $impl;
}

/**
 * Match a self-closing block comment and capture its JSON attributes.
 *
 * Block-comment attribute JSON can contain nested objects (e.g.
 * `{"metadata":{"foo":"bar"}}`), so a `\{[^}]*\}` regex would stop at
 * the first inner `}`. Instead, anchor on the literal trailing ` /-->`
 * and slurp non-greedily back to the opening `{` — that's stable for
 * our use because no theme block ever puts ` /-->` inside its attrs.
 */
function rs_find_self_closing_block( string $haystack, string $block_name ): array {
	$matches = [];
	$pattern = sprintf( '~<!-- wp:%s (\{.*?\}) /-->~s', preg_quote( $block_name, '~' ) );
	preg_match_all( $pattern, $haystack, $matches, PREG_OFFSET_CAPTURE );
	return $matches;
}

/**
 * Recursively inline `wp:template-part` and `wp:pattern` references.
 *
 * @param string   $content  Block markup.
 * @param string[] $stack    Slugs visited so far on this branch. Catches
 *                           recursive includes before they blow the stack.
 */
function rs_flatten( string $content, string $theme_dir, array $stack = [] ): string {
	// Template parts (HTML files under parts/).
	$content = preg_replace_callback(
		'~<!-- wp:template-part (\{.*?\}) /-->~s',
		function ( array $m ) use ( $theme_dir, $stack ): string {
			$attrs = json_decode( $m[1], true );
			if ( ! is_array( $attrs ) || empty( $attrs['slug'] ) ) {
				return $m[0];
			}
			$slug = (string) $attrs['slug'];
			$key  = "part:$slug";
			if ( in_array( $key, $stack, true ) ) {
				fwrite( STDERR, "✘ Recursive template-part: $slug\n" );
				exit( 1 );
			}
			$path = $theme_dir . '/parts/' . $slug . '.html';
			if ( ! is_readable( $path ) ) {
				fwrite( STDERR, "✘ Missing template part: $path\n" );
				exit( 1 );
			}
			$inner = rs_flatten( file_get_contents( $path ), $theme_dir, [ ...$stack, $key ] );
			// `tagName` on template-part means "render this part wrapped in
			// <tagName>". Preserve that semantics in the flattened markup.
			$tag = $attrs['tagName'] ?? '';
			return $tag ? "<{$tag}>{$inner}</{$tag}>" : $inner;
		},
		$content
	);

	// Patterns (PHP files under patterns/, evaluated with i18n shims).
	$content = preg_replace_callback(
		'~<!-- wp:pattern (\{.*?\}) /-->~s',
		function ( array $m ) use ( $theme_dir, $stack ): string {
			$attrs = json_decode( $m[1], true );
			if ( ! is_array( $attrs ) || empty( $attrs['slug'] ) ) {
				return $m[0];
			}
			$slug = (string) $attrs['slug'];
			$key  = "pattern:$slug";
			if ( in_array( $key, $stack, true ) ) {
				fwrite( STDERR, "✘ Recursive pattern: $slug\n" );
				exit( 1 );
			}
			// Pattern slugs are "theme//pattern-name". Take everything after
			// the first slash — we only ship the radical-theme variant.
			$pos  = strpos( $slug, '/' );
			$name = false !== $pos ? substr( $slug, $pos + 1 ) : $slug;
			$path = $theme_dir . '/patterns/' . $name . '.php';
			if ( ! is_readable( $path ) ) {
				fwrite( STDERR, "✘ Missing pattern: $path\n" );
				exit( 1 );
			}
			ob_start();
			include $path;
			$inner = ob_get_clean();
			return rs_flatten( $inner, $theme_dir, [ ...$stack, $key ] );
		},
		$content
	);

	return $content;
}

if ( ! is_dir( $theme_dir ) ) {
	fwrite( STDERR, "✘ Theme directory not found: $theme_dir\n" );
	exit( 1 );
}
if ( ! is_dir( $output_dir ) && ! mkdir( $output_dir, 0755, true ) ) {
	fwrite( STDERR, "✘ Could not create output dir: $output_dir\n" );
	exit( 1 );
}

foreach ( FLATTEN_TEMPLATES as $slug ) {
	$src = $theme_dir . '/templates/' . $slug . '.html';
	if ( ! is_readable( $src ) ) {
		fwrite( STDERR, "✘ Source template missing: $src\n" );
		exit( 1 );
	}
	$flat = rs_flatten( (string) file_get_contents( $src ), $theme_dir );
	$dest = $output_dir . '/' . $slug . '.html';
	file_put_contents( $dest, $flat );
	echo sprintf( "✓ %s.html (%d bytes)\n", $slug, strlen( $flat ) );
}
