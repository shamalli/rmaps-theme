<?php
/**
 * Rocket Maps theme bootstrap.
 *
 * Registers theme supports, nav menus, the standard + full-width page
 * templates, the `[rmaps_engine_switcher]` shortcode, and enqueues
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
 * ---------------------------------------------------------------*/
add_shortcode( 'rmaps_engine_switcher', function ( $atts ) {
	$atts = shortcode_atts( array(
		'compact' => 'no',   // `yes` strips labels, keeps just the icon
		'label'   => '',     // optional caption above the buttons
	), $atts, 'rmaps_engine_switcher' );

	ob_start();
	set_query_var( 'rmaps_theme_switcher_atts', $atts );
	get_template_part( 'template-parts/engine-switcher' );
	set_query_var( 'rmaps_theme_switcher_atts', null );
	return ob_get_clean();
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
