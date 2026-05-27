<?php
/**
 * Engine switcher — 100%-width strip of three engine buttons.
 *
 * Includable two ways:
 *   1. `get_template_part( 'template-parts/engine-switcher' )` in a
 *      page template (full-width or standard);
 *   2. `[rmaps-engine-switcher]` shortcode inside the post content
 *      (registered in functions.php; the legacy underscore form
 *      `[rmaps_engine_switcher]` is also accepted as an alias).
 *
 * Reads optional attributes from `rmaps_theme_switcher_atts` (set by the
 * shortcode wrapper) — see the `add_shortcode` call in functions.php.
 *
 * Wiring:
 * - Active engine is highlighted based on
 *   `rmaps_theme_active_engine()` (URL-override aware).
 * - Each button is a real `<a>` with `?rmaps_engine=<slug>` so the
 *   server renders the same engine even before JS kicks in (SEO +
 *   no-JS friendly). The script in `assets/js/theme.js` upgrades
 *   the click to a same-tab navigation that preserves the rest of
 *   the URL.
 * - Switcher is gated by the `RMAPS_ALLOW_ENGINE_URL_OVERRIDE`
 *   wp-config constant; if it's NOT set we render an inline notice
 *   pointing to the docs.
 *
 * @package rmaps-theme
 */

$atts = get_query_var( 'rmaps_theme_switcher_atts' );
if ( ! is_array( $atts ) ) $atts = array();
$compact = isset( $atts['compact'] ) && in_array( strtolower( (string) $atts['compact'] ), array( 'yes', 'true', '1' ), true );
$label   = isset( $atts['label'] ) ? (string) $atts['label'] : '';

// Inner HTML rendered ABOVE the buttons inside the switcher's flex
// column. Two sources:
//   1. Enclosing-form shortcode:
//      [rmaps-engine-switcher]<h3>Pick an engine</h3>…[/rmaps-engine-switcher]
//      — RAW user input, run through `wp_kses_post` (headings, lists,
//        paragraphs, links, images — same surface as post body).
//   2. The `rmaps/engine-switcher` Gutenberg block — already
//      server-rendered InnerBlocks output, trusted. The block path
//      sets `rmaps_theme_switcher_content_safe = true` so we SKIP the
//      kses pass (it would strip block wrapper classes / inline layout
//      styles the editor legitimately emits).
$inner_html = get_query_var( 'rmaps_theme_switcher_content' );
if ( ! is_string( $inner_html ) ) $inner_html = '';
$content_pre_safe = (bool) get_query_var( 'rmaps_theme_switcher_content_safe' );
if ( $inner_html === '' ) {
	$safe_inner_html = '';
} elseif ( $content_pre_safe ) {
	$safe_inner_html = $inner_html;
} else {
	$safe_inner_html = wp_kses_post( $inner_html );
}

$engines       = rmaps_theme_engine_options();
$active_engine = rmaps_theme_active_engine();
$override_ok   = defined( 'RMAPS_ALLOW_ENGINE_URL_OVERRIDE' ) && RMAPS_ALLOW_ENGINE_URL_OVERRIDE;

// Optional max-width cap for the above-buttons content column, fed as a
// CSS length (e.g. `760px`) by the block render callback. Validated to a
// safe length token, then exposed as `--rmaps-esb-content-width` on the
// section; CSS applies it to `.rmaps-theme-engine-switcher-content` only,
// so the divider + buttons stay full width.
$content_width = get_query_var( 'rmaps_theme_switcher_content_width' );
$content_width = is_string( $content_width ) ? trim( $content_width ) : '';
$section_style = '';
if ( $content_width !== '' && preg_match( '/^[0-9.]+(px|%|rem|em|vw)$/', $content_width ) ) {
	$section_style = ' style="--rmaps-esb-content-width: ' . esc_attr( $content_width ) . ';"';
}

// Build base URL — drop the param we're about to set so we don't
// stack duplicates. `wp_get_referer()` is unreliable inside the
// loop; use the current request URL via server vars + home_url
// as base.
$current_path   = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
$base_url       = home_url( $current_path );
$base_no_engine = remove_query_arg( 'rmaps_engine', $base_url );

?>
<section class="rmaps-theme-engine-switcher<?php echo $compact ? ' is-compact' : ''; ?>"<?php echo $section_style; // phpcs:ignore — validated length token ?>
		role="region"
		aria-label="<?php esc_attr_e( 'Map engine switcher', 'rmaps-theme' ); ?>">
	<div class="rmaps-theme-engine-switcher-inner">
		<?php if ( $label !== '' ) : ?>
			<p class="rmaps-theme-engine-switcher-label"><?php echo esc_html( $label ); ?></p>
		<?php endif; ?>

		<?php if ( $safe_inner_html !== '' ) : ?>
			<div class="rmaps-theme-engine-switcher-content">
				<?php echo $safe_inner_html; // phpcs:ignore — already through wp_kses_post ?>
			</div>
		<?php endif; ?>

		<?php
		// Divider between the above-buttons content and the engine
		// buttons. Only when there IS content above (label or inner
		// HTML) — a standalone switcher (no content) shouldn't open
		// with a stray top rule.
		if ( $label !== '' || $safe_inner_html !== '' ) :
		?>
			<hr class="rmaps-theme-engine-switcher-divider" />
		<?php endif; ?>

		<div class="rmaps-theme-engine-switcher-buttons" role="group"
				aria-label="<?php esc_attr_e( 'Choose map engine', 'rmaps-theme' ); ?>">
			<?php foreach ( $engines as $slug => $meta ) :
				$is_active = $slug === $active_engine;
				$href      = add_query_arg( 'rmaps_engine', $slug, $base_no_engine );
				?>
				<a class="rmaps-theme-engine-button<?php echo $is_active ? ' is-active' : ''; ?> rmaps-theme-engine-button-<?php echo esc_attr( $slug ); ?>"
					href="<?php echo esc_url( $href ); ?>"
					data-engine="<?php echo esc_attr( $slug ); ?>"
					aria-pressed="<?php echo $is_active ? 'true' : 'false'; ?>"
					rel="nofollow">
					<span class="rmaps-theme-engine-button-icon" aria-hidden="true">
						<?php echo rmaps_theme_engine_icon_svg( $slug ); // phpcs:ignore — static SVG strings ?>
					</span>
					<?php if ( ! $compact ) : ?>
						<span class="rmaps-theme-engine-button-label"><?php echo esc_html( $meta['label'] ); ?></span>
					<?php endif; ?>
					<?php if ( $is_active ) : ?>
						<span class="screen-reader-text"><?php esc_html_e( '(active)', 'rmaps-theme' ); ?></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
		</div>

		<?php if ( ! $override_ok ) : ?>
			<p class="rmaps-theme-engine-switcher-notice">
				<?php
				printf(
					/* translators: %s: HTML-escaped wp-config constant snippet. */
					esc_html__( 'URL switching is disabled. Add %s to your wp-config.php to enable on-the-fly engine switching from the URL.', 'rmaps-theme' ),
					'<code>define( \'RMAPS_ALLOW_ENGINE_URL_OVERRIDE\', true );</code>'
				);
				?>
			</p>
		<?php endif; ?>
	</div>
</section>
