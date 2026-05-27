<?php
/**
 * Rocket Maps theme bootstrap.
 *
 * Registers theme supports, nav menus, the standard + full-width page
 * templates, the `[rmaps-engine-switcher]` shortcode, and enqueues
 * the single bundled stylesheet + tiny script (dark-mode toggle +
 * engine-switcher click handler). No build step — all assets live
 * under `assets/`.
 *
 * The engine switcher itself talks to the plugin via the
 * `?rmaps_engine=<slug>` URL parameter, which the plugin honours
 * only when `define( 'RMAPS_ALLOW_ENGINE_URL_OVERRIDE', true );` is
 * set in `wp-config.php` (see helper `rmaps_get_active_map_engine()`
 * in the plugin's functions.php). The switcher is rendered both as
 * a template part (`get_template_part('template-parts/engine-switcher')`)
 * and as a shortcode so the admin can drop it anywhere inside post
 * content.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'RMAPS_THEME_VERSION' ) ) {
	define( 'RMAPS_THEME_VERSION', '1.0.0' );
}

/* ---------------------------------------------------------------
 * Theme setup
 * ---------------------------------------------------------------*/
add_action( 'after_setup_theme', function () {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'custom-logo', array(
		'height'      => 64,
		'width'       => 64,
		'flex-height' => true,
		'flex-width'  => true,
	) );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'html5', array(
		'search-form', 'comment-form', 'comment-list',
		'gallery', 'caption', 'style', 'script', 'navigation-widgets',
	) );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'align-wide' );

	register_nav_menus( array(
		'primary' => __( 'Header menu', 'rmaps-theme' ),
		'footer'  => __( 'Footer menu', 'rmaps-theme' ),
	) );

	load_theme_textdomain( 'rmaps-theme', get_template_directory() . '/languages' );
} );

/* ---------------------------------------------------------------
 * Enqueue assets
 * ---------------------------------------------------------------*/
add_action( 'wp_enqueue_scripts', function () {
	$css_path = get_template_directory() . '/assets/css/theme.css';
	$css_ver  = file_exists( $css_path ) ? filemtime( $css_path ) : RMAPS_THEME_VERSION;
	wp_enqueue_style(
		'rmaps-theme',
		get_template_directory_uri() . '/assets/css/theme.css',
		array(),
		$css_ver
	);

	$js_path = get_template_directory() . '/assets/js/theme.js';
	$js_ver  = file_exists( $js_path ) ? filemtime( $js_path ) : RMAPS_THEME_VERSION;
	wp_enqueue_script(
		'rmaps-theme',
		get_template_directory_uri() . '/assets/js/theme.js',
		array(),
		$js_ver,
		true
	);

	wp_localize_script( 'rmaps-theme', 'rmapsThemeData', array(
		'engineParam'       => 'rmaps_engine',
		'savedEngine'       => function_exists( 'rmaps_get_active_map_engine' )
			? rmaps_get_active_map_engine()
			: 'google',
		'urlOverrideActive' => defined( 'RMAPS_ALLOW_ENGINE_URL_OVERRIDE' )
			&& RMAPS_ALLOW_ENGINE_URL_OVERRIDE,
	) );
} );

/* ---------------------------------------------------------------
 * Engine switcher — template part + shortcode
 *
 * Renders a 100%-width strip of three engine buttons. Active button
 * is highlighted based on the live `?rmaps_engine=<slug>` URL param,
 * falling back to the site's saved `rmaps_map_engine` option. Click
 * sets the URL param and triggers a navigation so the next request
 * starts under the chosen engine.
 *
 * When `RMAPS_ALLOW_ENGINE_URL_OVERRIDE` is NOT defined the switcher
 * still renders but shows a small notice telling the admin to flip
 * the constant on — clicking still updates the URL but the plugin
 * ignores it. Better than silently doing nothing on a misconfigured
 * install.
 *
 * Canonical tag: `[rmaps-engine-switcher]` (hyphen). The
 * underscore variant `[rmaps_engine_switcher]` stays registered as a
 * back-compat alias so pages saved before the rename keep working.
 * ---------------------------------------------------------------*/
$rmaps_theme_engine_switcher_cb = function ( $atts, $content = null, $tag = '' ) {
	$atts = shortcode_atts( array(
		'compact' => 'no',   // `yes` strips labels, keeps just the icon
		'label'   => '',     // optional caption above the buttons
	), $atts, $tag ?: 'rmaps-engine-switcher' );

	// Enclosing form: `[rmaps-engine-switcher]<p>Pick an engine</p>[/rmaps-engine-switcher]`
	// — the inner content renders ABOVE the engine buttons inside the
	// switcher block. Run through `do_shortcode` so nested shortcodes
	// (e.g. translations, icons) still resolve, then trim — WordPress's
	// autop wraps the bare shortcode in <p> with stray whitespace and
	// that leaks into the rendered switcher as an empty paragraph.
	$rendered_content = '';
	if ( $content !== null && $content !== '' ) {
		$rendered_content = trim( do_shortcode( $content ) );
	}

	ob_start();
	set_query_var( 'rmaps_theme_switcher_atts',    $atts );
	set_query_var( 'rmaps_theme_switcher_content', $rendered_content );
	get_template_part( 'template-parts/engine-switcher' );
	set_query_var( 'rmaps_theme_switcher_atts',    null );
	set_query_var( 'rmaps_theme_switcher_content', null );
	return ob_get_clean();
};
add_shortcode( 'rmaps-engine-switcher', $rmaps_theme_engine_switcher_cb );
add_shortcode( 'rmaps_engine_switcher', $rmaps_theme_engine_switcher_cb ); // back-compat

/* ---------------------------------------------------------------
 * Gutenberg block — `rmaps/engine-switcher`
 *
 * Editor-friendly wrapper over the same engine-switcher template part.
 * The block is DYNAMIC: the editor saves only the InnerBlocks content
 * (the rich markup placed ABOVE the buttons) and PHP renders the
 * wrapper + live engine buttons at display time via the render
 * callback below. `block.json` lives in `blocks/engine-switcher/` and
 * declares the editor script/style; `register_block_type` reads it and
 * wires the render callback.
 *
 * `$content` here is the SERVER-RENDERED InnerBlocks HTML — already
 * safe block output, so we mark it `_content_safe` to skip the
 * template's `wp_kses_post` pass (which would otherwise strip block
 * wrapper classes / inline layout styles the editor emits).
 * ---------------------------------------------------------------*/
add_action( 'init', function () {
	$block_dir = get_template_directory() . '/blocks/engine-switcher';
	if ( ! file_exists( $block_dir . '/block.json' ) ) return;

	register_block_type( $block_dir, array(
		'render_callback' => function ( $attributes, $content ) {
			$atts = array(
				'compact' => ! empty( $attributes['compact'] ) ? 'yes' : 'no',
				'label'   => '',
			);

			// Content max-width (px) — caps just the above-buttons
			// content column. 0 / empty = full width. The divider + the
			// buttons row are NOT inside the content wrapper, so they
			// stay full-width regardless of this cap.
			$content_w  = isset( $attributes['contentMaxWidth'] ) ? (int) $attributes['contentMaxWidth'] : 0;
			$content_wv = $content_w > 0 ? $content_w . 'px' : '';

			ob_start();
			set_query_var( 'rmaps_theme_switcher_atts',          $atts );
			set_query_var( 'rmaps_theme_switcher_content',       (string) $content );
			set_query_var( 'rmaps_theme_switcher_content_safe',  true );
			set_query_var( 'rmaps_theme_switcher_content_width', $content_wv );
			get_template_part( 'template-parts/engine-switcher' );
			set_query_var( 'rmaps_theme_switcher_atts',          null );
			set_query_var( 'rmaps_theme_switcher_content',       null );
			set_query_var( 'rmaps_theme_switcher_content_safe',  null );
			set_query_var( 'rmaps_theme_switcher_content_width', null );
			$switcher = ob_get_clean();

			// Wrap so the block's align (wide/full), anchor id, and any
			// editor-added custom classes land on a real element. The
			// switcher section inside is `width: 100%`, so the wrapper's
			// alignfull/alignwide width is what positions the strip.
			$wrapper_attributes = function_exists( 'get_block_wrapper_attributes' )
				? get_block_wrapper_attributes()
				: '';
			return sprintf( '<div %s>%s</div>', $wrapper_attributes, $switcher );
		},
	) );
} );

/* ---------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------*/
if ( ! function_exists( 'rmaps_theme_engine_options' ) ) {
	function rmaps_theme_engine_options() {
		return array(
			'google'   => array(
				'label' => __( 'Google Maps', 'rmaps-theme' ),
				'short' => 'Google',
				'icon'  => 'google',
			),
			'mapbox'   => array(
				'label' => __( 'Mapbox', 'rmaps-theme' ),
				'short' => 'Mapbox',
				'icon'  => 'mapbox',
			),
			'maplibre' => array(
				'label' => __( 'MapLibre', 'rmaps-theme' ),
				'short' => 'MapLibre',
				'icon'  => 'maplibre',
			),
		);
	}
}

if ( ! function_exists( 'rmaps_theme_active_engine' ) ) {
	function rmaps_theme_active_engine() {
		if ( function_exists( 'rmaps_get_active_map_engine' ) ) {
			return rmaps_get_active_map_engine();
		}
		return get_option( 'rmaps_map_engine', 'google' );
	}
}

/* ---------------------------------------------------------------
 * Typography — Customizer-driven Google Font for body text
 *
 * Adds a "Typography" section under Appearance → Customize with a
 * dropdown of curated Google Fonts. The chosen font applies to body
 * copy, headings, buttons, and inline UI throughout the theme via
 * `var(--rmaps-theme-font-family)` (set as an inline `<style>` on
 * `<head>`), but the SITE NAV is explicitly excluded with its own
 * `--rmaps-theme-menu-font-family` override — admins who pick a
 * decorative display font for body text won't accidentally make the
 * primary navigation hard to read at small sizes.
 *
 * Stored as the slug (e.g. `Inter`) under option key
 * `rmaps_theme_body_font`. `Default` (system stack) skips the
 * Google Fonts request entirely — no needless network call when the
 * admin hasn't customized.
 * ---------------------------------------------------------------*/
if ( ! function_exists( 'rmaps_theme_google_fonts' ) ) {
	/**
	 * Curated Google Font list. Each entry maps slug → display label.
	 * Slug is the family name as Google Fonts expects it (spaces
	 * preserved — `add_query_arg` URL-encodes for us).
	 *
	 * "Default" is a sentinel: when chosen, no Google Fonts call is
	 * made and `--rmaps-theme-font-family` falls back to the system
	 * stack (see assets/css/theme.css).
	 */
	function rmaps_theme_google_fonts() {
		return array(
			'Default'          => __( 'Default (system)', 'rmaps-theme' ),
			'Inter'            => 'Inter',
			'Roboto'           => 'Roboto',
			'Open Sans'        => 'Open Sans',
			'Lato'             => 'Lato',
			'Montserrat'       => 'Montserrat',
			'Poppins'          => 'Poppins',
			'Nunito'           => 'Nunito',
			'Nunito Sans'      => 'Nunito Sans',
			'Source Sans 3'    => 'Source Sans 3',
			'Work Sans'        => 'Work Sans',
			'Raleway'          => 'Raleway',
			'PT Sans'          => 'PT Sans',
			'Mulish'           => 'Mulish',
			'Manrope'          => 'Manrope',
			'DM Sans'          => 'DM Sans',
			'Merriweather'     => 'Merriweather',
			'Lora'             => 'Lora',
			'Playfair Display' => 'Playfair Display',
			'Source Serif 4'   => 'Source Serif 4',
		);
	}
}

if ( ! function_exists( 'rmaps_theme_get_body_font' ) ) {
	function rmaps_theme_get_body_font() {
		$saved = get_theme_mod( 'rmaps_theme_body_font', 'Default' );
		$valid = array_keys( rmaps_theme_google_fonts() );
		return in_array( $saved, $valid, true ) ? $saved : 'Default';
	}
}

// Customizer registration
add_action( 'customize_register', function ( $wp_customize ) {
	$wp_customize->add_section( 'rmaps_theme_typography', array(
		'title'       => __( 'Typography', 'rmaps-theme' ),
		'priority'    => 40,
		'description' => __( 'Choose a Google Font for body text. The site navigation menu stays in the default system font for readability.', 'rmaps-theme' ),
	) );

	$wp_customize->add_setting( 'rmaps_theme_body_font', array(
		'default'           => 'Default',
		'sanitize_callback' => function ( $value ) {
			$valid = array_keys( rmaps_theme_google_fonts() );
			return in_array( $value, $valid, true ) ? $value : 'Default';
		},
		'transport'         => 'refresh',
	) );

	$wp_customize->add_control( 'rmaps_theme_body_font', array(
		'label'       => __( 'Body font', 'rmaps-theme' ),
		'description' => __( 'Applied to all theme text except the site menu.', 'rmaps-theme' ),
		'section'     => 'rmaps_theme_typography',
		'type'        => 'select',
		'choices'     => rmaps_theme_google_fonts(),
	) );

	// ---- Colors section --------------------------------------------
	$wp_customize->add_section( 'rmaps_theme_colors', array(
		'title'       => __( 'Colors', 'rmaps-theme' ),
		'priority'    => 41,
		'description' => __( 'Override heading and site-name colors. Leave empty to follow the light/dark theme default.', 'rmaps-theme' ),
	) );

	// Heading color (h1–h6)
	$wp_customize->add_setting( 'rmaps_theme_heading_color', array(
		'default'           => '',
		'sanitize_callback' => 'sanitize_hex_color',
		'transport'         => 'refresh',
	) );
	$wp_customize->add_control( new WP_Customize_Color_Control(
		$wp_customize,
		'rmaps_theme_heading_color',
		array(
			'label'       => __( 'Heading color', 'rmaps-theme' ),
			'description' => __( 'Applies to all headings (h1–h6).', 'rmaps-theme' ),
			'section'     => 'rmaps_theme_colors',
		)
	) );

	// Brand / site-name color
	$wp_customize->add_setting( 'rmaps_theme_brand_color', array(
		'default'           => '',
		'sanitize_callback' => 'sanitize_hex_color',
		'transport'         => 'refresh',
	) );
	$wp_customize->add_control( new WP_Customize_Color_Control(
		$wp_customize,
		'rmaps_theme_brand_color',
		array(
			'label'       => __( 'Site name color', 'rmaps-theme' ),
			'description' => __( 'Tints the header site name (.rmaps-theme-brand-name).', 'rmaps-theme' ),
			'section'     => 'rmaps_theme_colors',
		)
	) );
} );

// Enqueue the chosen Google Font + inject Customizer-driven CSS vars
// (body font + heading / brand colors).
// Hooked at priority 11 so it runs AFTER the main `wp_enqueue_scripts`
// handler above registered `rmaps-theme` — we attach the inline style
// to that handle so the vars live in the same cascade slot as the
// theme stylesheet (and so cache invalidation follows naturally).
add_action( 'wp_enqueue_scripts', function () {
	$global_vars = array(); // applied on :root, both light + dark
	$light_vars  = array(); // applied ONLY in light mode

	// --- Body font (Google Font) — global, theme-independent ---
	$font = rmaps_theme_get_body_font();
	if ( $font !== 'Default' ) {
		// Standard weight set covering body (400), emphasised (500),
		// strong (600) and headings (700). Italic 400 for em /
		// blockquote. `display=swap` so text renders in the system
		// fallback while the font fetches — no FOIT on slow networks.
		$url = add_query_arg( array(
			'family'  => str_replace( ' ', '+', $font ) . ':ital,wght@0,400;0,500;0,600;0,700;1,400',
			'display' => 'swap',
		), 'https://fonts.googleapis.com/css2' );
		wp_enqueue_style( 'rmaps-theme-google-font', $url, array(), null );

		$global_vars[] = sprintf(
			'--rmaps-theme-font-family: %s, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;',
			"'" . esc_attr( $font ) . "'"
		);
	}

	// --- Colors (heading + brand) — LIGHT MODE ONLY ---
	// A heading / brand colour picked in the Customizer is chosen
	// against the light background, so applying it in dark mode would
	// render dark text on the dark canvas (invisible). Scope these to
	// `html:not([data-theme="dark"])`; in dark mode the vars fall back
	// to their `:root` default (`var(--rmaps-theme-fg)` = the theme's
	// near-white foreground), so headings + site name stay readable.
	$heading_color = sanitize_hex_color( (string) get_theme_mod( 'rmaps_theme_heading_color', '' ) );
	if ( $heading_color ) {
		$light_vars[] = sprintf( '--rmaps-theme-heading-color: %s;', esc_attr( $heading_color ) );
	}
	$brand_color = sanitize_hex_color( (string) get_theme_mod( 'rmaps_theme_brand_color', '' ) );
	if ( $brand_color ) {
		$light_vars[] = sprintf( '--rmaps-theme-brand-color: %s;', esc_attr( $brand_color ) );
	}

	$css = '';
	if ( $global_vars ) {
		$css .= ':root { ' . implode( ' ', $global_vars ) . ' }';
	}
	if ( $light_vars ) {
		$css .= 'html:not([data-theme="dark"]) { ' . implode( ' ', $light_vars ) . ' }';
	}
	if ( $css !== '' ) {
		wp_add_inline_style( 'rmaps-theme', $css );
	}
}, 11 );

/**
 * Footer menu — flattened.
 *
 * The header menu renders parents + dropdowns via `wp_nav_menu()`,
 * but in the footer the same menu should appear as one flat row
 * (parents + children side by side) so visitors can jump straight
 * to any demo page without hovering. Walking the items list
 * directly is simpler than registering a custom Walker — we get
 * the items in tree-order (parent, its children, next parent) and
 * just print each as a sibling `<li>`, skipping the
 * `menu-item-has-children` class so the dropdown arrow CSS doesn't
 * fire.
 */
if ( ! function_exists( 'rmaps_theme_render_flat_menu' ) ) {
	function rmaps_theme_render_flat_menu( $location, $classes = 'rmaps-theme-menu rmaps-theme-menu-footer' ) {
		$locations = get_nav_menu_locations();
		if ( empty( $locations[ $location ] ) ) return false;

		$menu = wp_get_nav_menu_object( $locations[ $location ] );
		if ( ! $menu ) return false;

		$items = wp_get_nav_menu_items( $menu->term_id );
		if ( empty( $items ) ) return false;

		_wp_menu_item_classes_by_context( $items );

		echo '<ul class="' . esc_attr( $classes ) . '">';
		foreach ( $items as $item ) {
			$li_classes = array( 'menu-item', 'menu-item-' . (int) $item->ID );
			if ( ! empty( $item->current ) )          $li_classes[] = 'current-menu-item';
			if ( ! empty( $item->current_item_ancestor ) ) $li_classes[] = 'current-menu-ancestor';

			$target = $item->target ? ' target="' . esc_attr( $item->target ) . '"' : '';
			$rel    = $item->xfn    ? ' rel="' . esc_attr( $item->xfn ) . '"'      : '';

			printf(
				'<li class="%s"><a href="%s"%s%s>%s</a></li>',
				esc_attr( implode( ' ', $li_classes ) ),
				esc_url( $item->url ),
				$target,
				$rel,
				esc_html( $item->title )
			);
		}
		echo '</ul>';
		return true;
	}
}

/**
 * Inline SVG icon for the engine switcher.
 *
 * Kept inline (rather than spritesheet) so the button stays a single
 * DOM node and CSS can recolour it via `currentColor`. Icons are
 * geometric/wordmark-free — they're meant to evoke the engine, not
 * reproduce protected logos.
 */
if ( ! function_exists( 'rmaps_theme_engine_icon_svg' ) ) {
	function rmaps_theme_engine_icon_svg( $engine ) {
		// Common outline-icon style (Lucide / Feather family) — 2 px
		// stroke, rounded joins, `currentColor` so the button text
		// colour propagates. All three icons share the same visual
		// weight so the row reads as one switcher control. Brand-
		// agnostic on purpose: pin / compass / folded map evoke the
		// engines without copying any vendor wordmark.
		$open  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">';
		$close = '</svg>';

		switch ( $engine ) {
			case 'google':
				// Map pin — universal location-marker glyph.
				return $open
					. '<path d="M20 10c0 6.5-8 12-8 12s-8-5.5-8-12a8 8 0 1 1 16 0z"/>'
					. '<circle cx="12" cy="10" r="3"/>'
					. $close;

			case 'mapbox':
				// Compass with needle — navigation / wayfinding feel.
				return $open
					. '<circle cx="12" cy="12" r="10"/>'
					. '<polygon points="16.2 7.8 14.1 14.1 7.8 16.2 9.9 9.9" fill="currentColor" stroke="none"/>'
					. $close;

			case 'maplibre':
				// Folded paper map — three panels, two creases.
				return $open
					. '<path d="M1 6 8 3l8 3 7-3v15l-7 3-8-3-7 3z"/>'
					. '<path d="M8 3v18"/>'
					. '<path d="M16 6v18"/>'
					. $close;
		}
		return '';
	}
}

/* ---------------------------------------------------------------
 * Page templates — declared via the `Template Name:` header in
 * template-fullwidth.php and template-standard.php. Nothing else
 * to wire up; WP picks them up from the theme root automatically.
 * ---------------------------------------------------------------*/

/* ---------------------------------------------------------------
 * SEO — let the plugin handle its own. Theme just sets a sane
 * meta description fallback (excerpt or first 160 chars of content)
 * + OpenGraph image so social shares look right.
 * ---------------------------------------------------------------*/
add_action( 'wp_head', function () {
	if ( is_singular() ) {
		global $post;
		if ( ! $post ) return;

		$desc = '';
		if ( has_excerpt( $post ) ) {
			$desc = wp_strip_all_tags( get_the_excerpt( $post ) );
		} else {
			$desc = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
		}
		$desc = trim( preg_replace( '/\s+/u', ' ', $desc ) );
		if ( $desc !== '' ) {
			$desc = mb_substr( $desc, 0, 160 );
			echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
			echo '<meta property="og:description" content="' . esc_attr( $desc ) . '">' . "\n";
		}

		echo '<meta property="og:title" content="' . esc_attr( get_the_title( $post ) ) . '">' . "\n";
		echo '<meta property="og:type" content="website">' . "\n";
		echo '<meta property="og:url" content="' . esc_url( get_permalink( $post ) ) . '">' . "\n";

		if ( has_post_thumbnail( $post ) ) {
			$img = wp_get_attachment_image_url( get_post_thumbnail_id( $post ), 'large' );
			if ( $img ) {
				echo '<meta property="og:image" content="' . esc_url( $img ) . '">' . "\n";
				echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
			}
		}
	}
} );

/* ---------------------------------------------------------------
 * Body class hook so CSS can target the active engine for accent
 * colours / banner badges without an extra request.
 * ---------------------------------------------------------------*/
add_filter( 'body_class', function ( $classes ) {
	$engine = rmaps_theme_active_engine();
	if ( in_array( $engine, array( 'google', 'mapbox', 'maplibre' ), true ) ) {
		$classes[] = 'rmaps-theme-engine-' . $engine;
	}
	if ( defined( 'RMAPS_ALLOW_ENGINE_URL_OVERRIDE' ) && RMAPS_ALLOW_ENGINE_URL_OVERRIDE ) {
		$classes[] = 'rmaps-theme-engine-override-enabled';
	}
	return $classes;
} );
