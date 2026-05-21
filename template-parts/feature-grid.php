<?php
/**
 * Optional feature grid below the map.
 *
 * Pages can render this block by including the part directly:
 *   get_template_part( 'template-parts/feature-grid' );
 *
 * Default content is hard-coded (Marker ranking / Fast rendering /
 * Provider support / Search etc.) and can be filtered via the
 * `rmaps_theme_features` filter to customise per-page.
 *
 * @package rmaps-theme
 */

$features = apply_filters( 'rmaps_theme_features', array(
	array(
		'title' => __( 'Marker ranking', 'rmaps-theme' ),
		'body'  => __( 'Clusters pick the highest-priority pin in each viewport tile so the most relevant locations stay visible while zooming out.', 'rmaps-theme' ),
		'icon'  => 'pin',
	),
	array(
		'title' => __( 'Fast rendering', 'rmaps-theme' ),
		'body'  => __( 'Bbox-aware loading streams only the visible markers on the first round-trip — handle hundreds of thousands of pins without choking the browser.', 'rmaps-theme' ),
		'icon'  => 'spark',
	),
	array(
		'title' => __( 'Clear search', 'rmaps-theme' ),
		'body'  => __( 'A drag-and-drop form builder lets you assemble search forms from any custom field, with autocomplete and radius search out of the box.', 'rmaps-theme' ),
		'icon'  => 'search',
	),
	array(
		'title' => __( 'Provider support', 'rmaps-theme' ),
		'body'  => __( 'Switch between Google Maps, Mapbox and MapLibre/MapTiler from a single setting. Same data, same shortcode — different engine.', 'rmaps-theme' ),
		'icon'  => 'layers',
	),
) );

if ( empty( $features ) ) return;

$icons = array(
	'pin'    => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2c4 0 7 3 7 7 0 5-7 13-7 13S5 14 5 9c0-4 3-7 7-7zm0 4a3 3 0 1 0 0 6 3 3 0 0 0 0-6z"/></svg>',
	'spark'  => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M13 2 4 14h6l-1 8 9-12h-6z"/></svg>',
	'search' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M10 4a6 6 0 1 1 0 12 6 6 0 0 1 0-12zm10 17l-5.6-5.6a8 8 0 1 0-1.4 1.4L18.6 22z"/></svg>',
	'layers' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2 2 7l10 5 10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>',
);
?>

<section class="rmaps-section rmaps-features">
	<div class="rmaps-container">
		<div class="rmaps-feature-grid">
			<?php foreach ( $features as $feat ) :
				$icon_key = isset( $feat['icon'] ) ? (string) $feat['icon'] : 'pin';
				?>
				<article class="rmaps-feature-card">
					<div class="rmaps-feature-icon" aria-hidden="true">
						<?php echo $icons[ $icon_key ] ?? $icons['pin']; // phpcs:ignore — static SVG ?>
					</div>
					<h3 class="rmaps-feature-title"><?php echo esc_html( $feat['title'] ); ?></h3>
					<p class="rmaps-feature-body"><?php echo esc_html( $feat['body'] ); ?></p>
				</article>
			<?php endforeach; ?>
		</div>
	</div>
</section>
