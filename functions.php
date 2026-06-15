<?php
/**
 * Rocket Maps theme bootstrap: theme supports, nav menus, page templates,
 * the `[rmaps-engine-switcher]` shortcode/block, and asset enqueues. The
 * switcher flips the plugin's `?rmaps_engine=<slug>` param (honoured only
 * when RMAPS_ALLOW_ENGINE_URL_OVERRIDE is defined in wp-config.php).
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
 * Default site logo
 *
 * Bundles the Rocket Maps mark (assets/images/logo-mark.png) as the
 * theme's default custom-logo. WordPress only emits a logo through
 * the_custom_logo()/get_custom_logo() once an admin uploads one (a
 * custom_logo theme-mod -> media attachment). This filter supplies the
 * bundled mark as the fallback so the brand shows out of the box -- in
 * the Site Logo block and the Customizer preview -- with no manual
 * upload. Uploading a logo via Appearance -> Customize -> Site Identity
 * overrides it automatically. (The classic header/footer already render
 * the mark + site-name lockup directly.)
 * ---------------------------------------------------------------*/
add_filter( 'get_custom_logo', function ( $html ) {
	if ( has_custom_logo() ) {
		return $html; // admin-uploaded logo wins
	}
	return sprintf(
		'<a href="%1$s" class="custom-logo-link rmaps-theme-default-logo" rel="home"><img src="%2$s" class="custom-logo" alt="%3$s" width="258" height="512" decoding="async" /></a>',
		esc_url( home_url( '/' ) ),
		esc_url( get_theme_file_uri( 'assets/images/logo-mark.png' ) ),
		esc_attr( get_bloginfo( 'name', 'display' ) )
	);
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
 * Engine switcher - template part + shortcode
 *
 * Renders a 100%-width strip of three engine buttons. Active button
 * is highlighted based on the live `?rmaps_engine=<slug>` URL param,
 * falling back to the site's saved `rmaps_map_engine` option. Click
 * sets the URL param and triggers a navigation so the next request
 * starts under the chosen engine.
 *
 * When `RMAPS_ALLOW_ENGINE_URL_OVERRIDE` is NOT defined the switcher
 * still renders but shows a small notice telling the admin to flip
 * the constant on - clicking still updates the URL but the plugin
 * ignores it. Better than silently doing nothing on a misconfigured
 * install.
 *
 * Canonical tag: `[rmaps-engine-switcher]` (hyphen only).
 * ---------------------------------------------------------------*/
$rmaps_theme_engine_switcher_cb = function ( $atts, $content = null, $tag = '' ) {
	$atts = shortcode_atts( array(
		'compact' => 'no',   // `yes` strips labels, keeps just the icon
		'label'   => '',     // optional caption above the buttons
	), $atts, $tag ?: 'rmaps-engine-switcher' );

	// Enclosing form: `[rmaps-engine-switcher]<p>Pick an engine</p>[/rmaps-engine-switcher]`
	// - the inner content renders ABOVE the engine buttons inside the
	// switcher block. Run through `do_shortcode` so nested shortcodes
	// (e.g. translations, icons) still resolve, then trim - WordPress's
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

/* ---------------------------------------------------------------
 * Gutenberg block - `rmaps/engine-switcher`
 *
 * Editor-friendly wrapper over the same engine-switcher template part.
 * The block is DYNAMIC: the editor saves only the InnerBlocks content
 * (the rich markup placed ABOVE the buttons) and PHP renders the
 * wrapper + live engine buttons at display time via the render
 * callback below. `block.json` lives in `blocks/engine-switcher/` and
 * declares the editor script/style; `register_block_type` reads it and
 * wires the render callback.
 *
 * `$content` here is the SERVER-RENDERED InnerBlocks HTML - already
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

			// Content max-width (px) - caps just the above-buttons
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
 * Typography - Customizer-driven Google Font for body text
 *
 * Adds a "Typography" section under Appearance -> Customize with a
 * dropdown of curated Google Fonts. The chosen font applies to body
 * copy, headings, buttons, and inline UI throughout the theme via
 * `var(--rmaps-theme-font-family)` (set as an inline `<style>` on
 * `<head>`), but the SITE NAV is explicitly excluded with its own
 * `--rmaps-theme-menu-font-family` override - admins who pick a
 * decorative display font for body text won't accidentally make the
 * primary navigation hard to read at small sizes.
 *
 * Stored as the slug (e.g. `Inter`) under option key
 * `rmaps_theme_body_font`. `Default` (system stack) skips the
 * Google Fonts request entirely - no needless network call when the
 * admin hasn't customized.
 * ---------------------------------------------------------------*/
if ( ! function_exists( 'rmaps_theme_google_fonts' ) ) {
	/**
	 * Curated Google Font list. Each entry maps slug -> display label.
	 * Slug is the family name as Google Fonts expects it (spaces
	 * preserved - `add_query_arg` URL-encodes for us).
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

	// Heading color (h1-h6)
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
// handler above registered `rmaps-theme` - we attach the inline style
// to that handle so the vars live in the same cascade slot as the
// theme stylesheet (and so cache invalidation follows naturally).
add_action( 'wp_enqueue_scripts', function () {
	$global_vars = array(); // applied on :root, both light + dark
	$light_vars  = array(); // applied ONLY in light mode

	// --- Body font (Google Font) - global, theme-independent ---
	$font = rmaps_theme_get_body_font();
	if ( $font !== 'Default' ) {
		// Standard weight set covering body (400), emphasised (500),
		// strong (600) and headings (700). Italic 400 for em /
		// blockquote. `display=swap` so text renders in the system
		// fallback while the font fetches - no FOIT on slow networks.
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

	// --- Colors (heading + brand) - LIGHT MODE ONLY ---
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
 * Footer menu - flattened.
 *
 * The header menu renders parents + dropdowns via `wp_nav_menu()`,
 * but in the footer the same menu should appear as one flat row
 * (parents + children side by side) so visitors can jump straight
 * to any demo page without hovering. Walking the items list
 * directly is simpler than registering a custom Walker - we get
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
 * Prev / next demo navigation, sourced from the `primary` (Header) menu.
 *
 * The demo tour is the SUBMENU under the top-level "Demo" item in the
 * header menu: its direct children (in menu order) are the demo
 * sequence. Returns the items immediately before and after the page
 * currently being viewed, wrapping around at both ends so the switcher
 * can cycle endlessly. Other top-level items (Home, Docs, ...) are ignored.
 *
 * `#`/empty placeholder children are skipped so they don't become a dead
 * stop.
 *
 * Returns null when there is no `primary` menu, fewer than two navigable
 * items, or the current page isn't one of them (front page, non-demo
 * template, ...) - the caller then renders no prev/next strip.
 *
 * @return array{prev:array{url:string,title:string},next:array{url:string,title:string},index:int,total:int}|null
 */
if ( ! function_exists( 'rmaps_theme_demo_nav' ) ) {
	function rmaps_theme_demo_nav() {
		$locations = get_nav_menu_locations();
		if ( empty( $locations['primary'] ) ) {
			return null;
		}

		$menu = wp_get_nav_menu_object( $locations['primary'] );
		if ( ! $menu ) {
			return null;
		}

		$items = wp_get_nav_menu_items( $menu->term_id );
		if ( empty( $items ) ) {
			return null;
		}

		// The demo tour is the submenu under the top-level "Demo" item:
		// find that parent, then collect its direct children in menu
		// order. Other top-level items (Home, Docs, ...) are ignored.
		$demo_parent_id = 0;
		foreach ( $items as $item ) {
			if ( 0 === (int) $item->menu_item_parent
				&& 0 === stripos( trim( (string) $item->title ), 'demo' ) ) {
				$demo_parent_id = (int) $item->ID;
				break;
			}
		}
		if ( ! $demo_parent_id ) {
			return null;
		}

		$seq = array();
		foreach ( $items as $item ) {
			if ( (int) $item->menu_item_parent !== $demo_parent_id ) {
				continue;
			}
			$url = isset( $item->url ) ? trim( (string) $item->url ) : '';
			if ( $url === '' || $url === '#' ) {
				continue;
			}
			$seq[] = $item;
		}

		$total = count( $seq );
		if ( $total < 2 ) {
			return null;
		}

		// Locate the current page. Prefer each item's `object_id` (the
		// page ID for post-type items); fall back to a forgiving URL
		// compare (scheme/engine-param/fragment/trailing-slash stripped)
		// for custom-link items.
		$current_id = (int) get_queried_object_id();
		$idx        = -1;
		if ( $current_id ) {
			foreach ( $seq as $i => $item ) {
				if ( (int) $item->object_id === $current_id ) {
					$idx = $i;
					break;
				}
			}
		}
		if ( $idx === -1 ) {
			$norm = static function ( $url ) {
				$url = remove_query_arg( 'rmaps_engine', (string) $url );
				$url = preg_replace( '#^https?://#i', '', $url );
				$url = strtok( $url, '#' );
				return untrailingslashit( (string) $url );
			};
			$current_url = $norm( home_url( add_query_arg( array() ) ) );
			foreach ( $seq as $i => $item ) {
				if ( $norm( $item->url ) === $current_url ) {
					$idx = $i;
					break;
				}
			}
		}
		if ( $idx === -1 ) {
			return null;
		}

		$prev = $seq[ ( $idx - 1 + $total ) % $total ];
		$next = $seq[ ( $idx + 1 ) % $total ];

		return array(
			'prev'  => array( 'url' => (string) $prev->url, 'title' => (string) $prev->title ),
			'next'  => array( 'url' => (string) $next->url, 'title' => (string) $next->title ),
			'index' => $idx,
			'total' => $total,
		);
	}
}

/**
 * Inline SVG icon for the engine switcher.
 *
 * Kept inline (rather than spritesheet) so the button stays a single
 * DOM node and CSS can recolour it via `currentColor`. Icons are
 * geometric/wordmark-free - they're meant to evoke the engine, not
 * reproduce protected logos.
 */
if ( ! function_exists( 'rmaps_theme_engine_icon_svg' ) ) {
	function rmaps_theme_engine_icon_svg( $engine ) {
		// Common outline-icon style (Lucide / Feather family) - 2 px
		// stroke, rounded joins, `currentColor` so the button text
		// colour propagates. All three icons share the same visual
		// weight so the row reads as one switcher control. Brand-
		// agnostic on purpose: pin / compass / folded map evoke the
		// engines without copying any vendor wordmark.
		$open  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">';
		$close = '</svg>';

		switch ( $engine ) {
			case 'google':
				// Map pin - universal location-marker glyph.
				return $open
					. '<path d="M20 10c0 6.5-8 12-8 12s-8-5.5-8-12a8 8 0 1 1 16 0z"/>'
					. '<circle cx="12" cy="10" r="3"/>'
					. $close;

			case 'mapbox':
				// Compass with needle - navigation / wayfinding feel.
				return $open
					. '<circle cx="12" cy="12" r="10"/>'
					. '<polygon points="16.2 7.8 14.1 14.1 7.8 16.2 9.9 9.9" fill="currentColor" stroke="none"/>'
					. $close;

			case 'maplibre':
				// Folded paper map - three panels, two creases.
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
 * Page templates - declared via the `Template Name:` header in
 * template-fullwidth.php and template-standard.php. Nothing else
 * to wire up; WP picks them up from the theme root automatically.
 * ---------------------------------------------------------------*/

/* ---------------------------------------------------------------
 * SEO - meta description, Open Graph + Twitter Card, and JSON-LD
 * structured data (WebSite + Organization site-wide, plus a
 * SoftwareApplication "product" schema on the front page for the
 * Rocket Maps plugin). Everything is skipped automatically when a
 * dedicated SEO plugin (Yoast / Rank Math / AIOSEO / SEOPress) is
 * active so head tags never double up. WP core already prints the
 * <title> (title-tag support) and rel=canonical for singular views.
 * ---------------------------------------------------------------*/

/** True when a full SEO plugin owns the head meta - the theme then stays quiet. */
function rmaps_theme_seo_plugin_active() {
	return defined( 'WPSEO_VERSION' )
		|| defined( 'RANK_MATH_VERSION' )
		|| defined( 'SEOPRESS_VERSION' )
		|| function_exists( 'aioseo' )
		|| class_exists( 'All_in_One_SEO_Pack' );
}

/** Site-wide default description (front page + schema). Uses the tagline when
 *  set, otherwise a curated plugin pitch. Filterable. */
function rmaps_theme_seo_default_description() {
	$tagline = trim( wp_strip_all_tags( (string) get_bloginfo( 'description' ) ) );
	$default = '' !== $tagline
		? $tagline
		: __( 'A powerful WordPress maps & directory plugin - Google Maps, Mapbox and MapLibre engines, marker clustering, advanced search, listings, popups and a drag-and-drop form builder.', 'rmaps-theme' );
	return (string) apply_filters( 'rmaps_theme_seo_description', $default );
}

/** Best brand image URL (custom logo -> site icon -> bundled mark). */
function rmaps_theme_seo_logo_url() {
	$logo_id = (int) get_theme_mod( 'custom_logo' );
	if ( $logo_id ) {
		$u = wp_get_attachment_image_url( $logo_id, 'full' );
		if ( $u ) {
			return $u;
		}
	}
	if ( function_exists( 'get_site_icon_url' ) ) {
		$icon = get_site_icon_url( 512 );
		if ( $icon ) {
			return $icon;
		}
	}
	return get_theme_file_uri( 'assets/images/logo-mark.png' );
}

/** OG/Twitter image: the post's featured image, else the brand logo. */
function rmaps_theme_seo_image() {
	if ( is_singular() && has_post_thumbnail() ) {
		$img = wp_get_attachment_image_url( get_post_thumbnail_id(), 'large' );
		if ( $img ) {
			return $img;
		}
	}
	return rmaps_theme_seo_logo_url();
}

/** Resolve a <=160-char description for the current view. */
function rmaps_theme_seo_description() {
	if ( is_front_page() ) {
		return rmaps_theme_seo_default_description();
	}
	if ( is_singular() ) {
		$post = get_queried_object();
		if ( $post instanceof WP_Post ) {
			$raw  = has_excerpt( $post ) ? get_the_excerpt( $post ) : strip_shortcodes( $post->post_content );
			$desc = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $raw ) ) );
			if ( '' !== $desc ) {
				return mb_substr( $desc, 0, 160 );
			}
		}
	}
	if ( is_archive() ) {
		$d = trim( wp_strip_all_tags( get_the_archive_description() ) );
		if ( '' !== $d ) {
			return mb_substr( $d, 0, 160 );
		}
	}
	return rmaps_theme_seo_default_description();
}

/** Canonical-ish URL for OG. */
function rmaps_theme_seo_url() {
	if ( is_singular() && ! is_front_page() ) {
		$id = get_queried_object_id();
		if ( $id ) {
			return get_permalink( $id );
		}
	}
	return home_url( '/' );
}

/* ---- Meta description + Open Graph + Twitter Card ---- */
add_action( 'wp_head', function () {
	if ( rmaps_theme_seo_plugin_active() ) {
		return;
	}
	$desc  = rmaps_theme_seo_description();
	$image = rmaps_theme_seo_image();
	$url   = rmaps_theme_seo_url();
	$title = is_front_page() ? get_bloginfo( 'name' ) : ( is_singular() ? get_the_title() : wp_get_document_title() );
	$type  = is_singular( 'post' ) ? 'article' : 'website';

	$out = '';
	if ( '' !== $desc ) {
		$out .= '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
	}
	$out .= '<meta property="og:locale" content="' . esc_attr( str_replace( '-', '_', get_bloginfo( 'language' ) ) ) . '">' . "\n";
	$out .= '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '">' . "\n";
	$out .= '<meta property="og:type" content="' . esc_attr( $type ) . '">' . "\n";
	$out .= '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
	if ( '' !== $desc ) {
		$out .= '<meta property="og:description" content="' . esc_attr( $desc ) . '">' . "\n";
	}
	$out .= '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
	if ( $image ) {
		$out .= '<meta property="og:image" content="' . esc_url( $image ) . '">' . "\n";
	}
	$out .= '<meta name="twitter:card" content="summary_large_image">' . "\n";
	$out .= '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
	if ( '' !== $desc ) {
		$out .= '<meta name="twitter:description" content="' . esc_attr( $desc ) . '">' . "\n";
	}
	if ( $image ) {
		$out .= '<meta name="twitter:image" content="' . esc_url( $image ) . '">' . "\n";
	}
	echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each value escaped above.
}, 5 );

/**
 * SoftwareApplication schema for the Rocket Maps plugin (front-page product
 * micro-markup). Filterable - set the REAL price / currency, and add an
 * `aggregateRating` only if you have genuine reviews, via:
 *
 *   add_filter( 'rmaps_theme_software_schema', function ( $s ) {
 *       $s['offers']['price'] = '49';                 // your CodeCanyon price
 *       $s['aggregateRating'] = array(                // real numbers only
 *           '@type' => 'AggregateRating',
 *           'ratingValue' => '4.8', 'reviewCount' => '37',
 *       );
 *       return $s;
 *   } );
 */
function rmaps_theme_software_schema() {
	$home = home_url( '/' );
	$buy  = get_theme_mod( 'rmaps_theme_buy_url', 'https://codecanyon.net/item/rocket-maps-plugin-for-wordpress/63717983' );
	$name = get_bloginfo( 'name' );

	$schema = array(
		'@type'                => 'SoftwareApplication',
		'name'                 => '' !== $name ? $name : 'Rocket Maps',
		'description'          => rmaps_theme_seo_default_description(),
		'applicationCategory'  => 'BusinessApplication',
		'operatingSystem'      => 'WordPress',
		'url'                  => $home,
		'image'                => rmaps_theme_seo_logo_url(),
		'softwareRequirements' => 'WordPress 6.0 or higher, PHP 7.4+',
		// CodeCanyon regular-license price - keep in sync with the actual item
		// price (a mismatch with the sale page hurts rich results).
		'offers'               => array(
			'@type'         => 'Offer',
			'price'         => '19',
			'priceCurrency' => 'USD',
			'url'           => $buy,
			'availability'  => 'https://schema.org/InStock',
		),
	);
	return apply_filters( 'rmaps_theme_software_schema', $schema );
}

/* ---- JSON-LD structured data ---- */
add_action( 'wp_head', function () {
	if ( rmaps_theme_seo_plugin_active() ) {
		return;
	}
	$home  = home_url( '/' );
	$graph = array();

	$org = array(
		'@type' => 'Organization',
		'@id'   => $home . '#organization',
		'name'  => get_bloginfo( 'name' ),
		'url'   => $home,
	);
	$logo = rmaps_theme_seo_logo_url();
	if ( $logo ) {
		$org['logo'] = array( '@type' => 'ImageObject', 'url' => $logo );
	}
	$graph[] = $org;

	$graph[] = array(
		'@type'       => 'WebSite',
		'@id'         => $home . '#website',
		'name'        => get_bloginfo( 'name' ),
		'url'         => $home,
		'description' => rmaps_theme_seo_default_description(),
		'inLanguage'  => get_bloginfo( 'language' ),
		'publisher'   => array( '@id' => $home . '#organization' ),
	);

	if ( is_front_page() ) {
		$app              = rmaps_theme_software_schema();
		$app['publisher'] = array( '@id' => $home . '#organization' );
		$graph[]          = $app;
	}

	$data = array(
		'@context' => 'https://schema.org',
		'@graph'   => $graph,
	);
	// wp_json_encode escapes slashes by default, so a stray "</script>" inside
	// any value can't break out of the tag.
	echo '<script type="application/ld+json">' . wp_json_encode( $data ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}, 20 );

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

/* ---------------------------------------------------------------
 * Google Analytics (GA4)
 *
 * Outputs the gtag.js tag in the FRONT-END <head>. The Measurement ID
 * (format `G-XXXXXXXXXX`, from GA4 Admin -> Data streams -> your stream
 * -> "Measurement ID" -- NOT the numeric property/stream ID like
 * 307736038) is resolved from, in order of precedence:
 *   1. the `rmaps_theme_ga_id` filter,
 *   2. the `RMAPS_THEME_GA_ID` constant (e.g. define() in wp-config.php
 *      or just below),
 *   3. the `rmaps_theme_ga_id` theme-mod.
 * Nothing is printed in wp-admin or when no valid `G-...` ID is set, so
 * the theme ships analytics-free until an ID is configured.
 *
 * The config call sets `cookie_flags: 'SameSite=None;Secure'` so the `_ga`
 * cookie keeps working when the demo is loaded inside the CodeCanyon
 * live-preview iframe (a cross-site/third-party context); harmless on the
 * standalone site.
 * ---------------------------------------------------------------*/
if ( ! defined( 'RMAPS_THEME_GA_ID' ) ) {
	define( 'RMAPS_THEME_GA_ID', 'G-M1K5L8X0SC' ); // GA4 Measurement ID (www.salephpscripts.com)
}

if ( ! function_exists( 'rmaps_theme_ga_measurement_id' ) ) {
	function rmaps_theme_ga_measurement_id() {
		$id = RMAPS_THEME_GA_ID !== '' ? RMAPS_THEME_GA_ID : (string) get_theme_mod( 'rmaps_theme_ga_id', '' );
		$id = (string) apply_filters( 'rmaps_theme_ga_id', $id );
		return preg_match( '/^G-[A-Z0-9]{4,}$/i', $id ) ? $id : '';
	}
}

if ( ! function_exists( 'rmaps_theme_ga_is_excluded_ip' ) ) {
	/**
	 * Whether the current visitor should be kept OUT of Google Analytics.
	 *
	 * Lets the site owner exclude their own IP(s) so personal visits don't
	 * inflate the stats. When this returns true the gtag snippet is not
	 * emitted at all, so no hit is ever sent -- nothing to filter later.
	 *
	 * The IP list is combined from:
	 *   1. the `RMAPS_THEME_GA_EXCLUDE_IPS` constant -- a comma/space
	 *      separated string. Best set in wp-config.php so personal IPs stay
	 *      OUT of the theme repo, e.g.:
	 *          define( 'RMAPS_THEME_GA_EXCLUDE_IPS', '203.0.113.7, 2001:db8::1' );
	 *   2. the `rmaps_theme_ga_exclude_ips` filter (array of IP strings).
	 * Empty list => nobody is excluded.
	 *
	 * Matching is exact but address-normalised via inet_pton(), so IPv6
	 * short/long forms compare equal and malformed entries are ignored. Uses
	 * REMOTE_ADDR (the connecting IP); if the demo ever sits behind a reverse
	 * proxy / CDN, the real client IP would instead be in X-Forwarded-For.
	 */
	function rmaps_theme_ga_is_excluded_ip() {
		$list = array();
		if ( defined( 'RMAPS_THEME_GA_EXCLUDE_IPS' ) && is_string( RMAPS_THEME_GA_EXCLUDE_IPS ) ) {
			$list = preg_split( '/[\s,]+/', RMAPS_THEME_GA_EXCLUDE_IPS, -1, PREG_SPLIT_NO_EMPTY );
		}
		$list = (array) apply_filters( 'rmaps_theme_ga_exclude_ips', $list );

		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		if ( empty( $list ) || ! filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		$client = inet_pton( $remote );

		foreach ( $list as $ip ) {
			$ip = trim( $ip );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) && inet_pton( $ip ) === $client ) {
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'rmaps_theme_ga_print_snippet' ) ) {
	/**
	 * Echo the gtag.js snippet for a GA4 Measurement ID. Shared by the
	 * front-end (wp_head) and the demo-only admin emitter below so the tag is
	 * byte-for-byte identical in both contexts.
	 */
	function rmaps_theme_ga_print_snippet( $ga_id ) {
		?>
<!-- Google tag (gtag.js) -->
<script async src="<?php echo esc_url( 'https://www.googletagmanager.com/gtag/js?id=' . $ga_id ); ?>"></script>
<script>
	window.dataLayer = window.dataLayer || [];
	function gtag(){dataLayer.push(arguments);}
	gtag('js', new Date());
	gtag('config', <?php echo wp_json_encode( $ga_id ); ?>, { cookie_flags: 'SameSite=None;Secure' });
</script>
		<?php
	}
}

// Front-end <head>.
add_action( 'wp_head', function () {
	if ( is_admin() ) {
		return;
	}
	$ga_id = rmaps_theme_ga_measurement_id();
	if ( '' === $ga_id ) {
		return;
	}
	if ( rmaps_theme_ga_is_excluded_ip() ) {
		echo "<!-- Rocket Maps: GA skipped (excluded IP) -->\n";
		return;
	}
	rmaps_theme_ga_print_snippet( $ga_id );
}, 1 );

/*
 * Demo-only: also load GA on the wp-admin screens. On the Rocket Maps demo
 * server (RMAPS_DEMO) logged-out visitors are auto-signed into a sandboxed,
 * read-only wp-admin to explore the plugin's editors (maps, listings, form
 * builder, settings, CSV) - this measures that admin-side browsing too.
 * Hard-gated on RMAPS_DEMO so a real install never tracks its own wp-admin
 * usage; same IP-exclusion as the front-end.
 */
add_action( 'admin_print_footer_scripts', function () {
	if ( ! ( defined( 'RMAPS_DEMO' ) && RMAPS_DEMO ) ) {
		return;
	}
	$ga_id = rmaps_theme_ga_measurement_id();
	if ( '' === $ga_id ) {
		return;
	}
	if ( rmaps_theme_ga_is_excluded_ip() ) {
		return;
	}
	rmaps_theme_ga_print_snippet( $ga_id );
} );

/* ---------------------------------------------------------------
 * Admin media tools (Tools menu), admin-only so they load only in wp-admin:
 *
 *  - Media Cleanup  — finds orphaned image files under wp-content/uploads/
 *    (on disk but not in the Media Library) and moves them to a reversible
 *    quarantine folder.
 *  - WebP Convert   — converts JPEG/PNG Media Library images to WebP in
 *    place, quarantining the originals (reversible until finalized).
 * ---------------------------------------------------------------*/
if ( is_admin() ) {
	require_once get_template_directory() . '/inc/uploads-cleanup.php';
	require_once get_template_directory() . '/inc/webp-convert.php';
}
