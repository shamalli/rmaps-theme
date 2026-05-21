<?php
/**
 * Engine switcher — 100%-width strip of three engine buttons.
 *
 * Includable two ways:
 *   1. `get_template_part( 'template-parts/engine-switcher' )` in a
 *      page template (full-width or standard);
 *   2. `[rmaps_engine_switcher]` shortcode inside the post content
 *      (registered in functions.php).
 *
 * Reads optional attributes from `rmaps_switcher_atts` (set by the
 * shortcode wrapper) — see `rmaps_engine_switcher` in functions.php.
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

$atts = get_query_var( 'rmaps_switcher_atts' );
if ( ! is_array( $atts ) ) $atts = array();
$compact = isset( $atts['compact'] ) && in_array( strtolower( (string) $atts['compact'] ), array( 'yes', 'true', '1' ), true );
$label   = isset( $atts['label'] ) ? (string) $atts['label'] : '';

$engines       = rmaps_theme_engine_options();
$active_engine = rmaps_theme_active_engine();
$override_ok   = defined( 'RMAPS_ALLOW_ENGINE_URL_OVERRIDE' ) && RMAPS_ALLOW_ENGINE_URL_OVERRIDE;

// Build base URL — drop the param we're about to set so we don't
// stack duplicates. `wp_get_referer()` is unreliable inside the
// loop; use the current request URL via server vars + home_url
// as base.
$current_path   = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
$base_url       = home_url( $current_path );
$base_no_engine = remove_query_arg( 'rmaps_engine', $base_url );

?>
<section class="rmaps-engine-switcher<?php echo $compact ? ' is-compact' : ''; ?>"
		role="region"
		aria-label="<?php esc_attr_e( 'Map engine switcher', 'rmaps-theme' ); ?>">
	<div class="rmaps-engine-switcher-inner">
		<?php if ( $label !== '' ) : ?>
			<p class="rmaps-engine-switcher-label"><?php echo esc_html( $label ); ?></p>
		<?php endif; ?>

		<div class="rmaps-engine-switcher-buttons" role="group"
				aria-label="<?php esc_attr_e( 'Choose map engine', 'rmaps-theme' ); ?>">
			<?php foreach ( $engines as $slug => $meta ) :
				$is_active = $slug === $active_engine;
				$href      = add_query_arg( 'rmaps_engine', $slug, $base_no_engine );
				?>
				<a class="rmaps-engine-button<?php echo $is_active ? ' is-active' : ''; ?> rmaps-engine-button-<?php echo esc_attr( $slug ); ?>"
					href="<?php echo esc_url( $href ); ?>"
					data-engine="<?php echo esc_attr( $slug ); ?>"
					aria-pressed="<?php echo $is_active ? 'true' : 'false'; ?>"
					rel="nofollow">
					<span class="rmaps-engine-button-icon" aria-hidden="true">
						<?php echo rmaps_theme_engine_icon_svg( $slug ); // phpcs:ignore — static SVG strings ?>
					</span>
					<?php if ( ! $compact ) : ?>
						<span class="rmaps-engine-button-label"><?php echo esc_html( $meta['label'] ); ?></span>
					<?php endif; ?>
					<?php if ( $is_active ) : ?>
						<span class="screen-reader-text"><?php esc_html_e( '(active)', 'rmaps-theme' ); ?></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
		</div>

		<?php if ( ! $override_ok ) : ?>
			<p class="rmaps-engine-switcher-notice">
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
