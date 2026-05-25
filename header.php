<?php
/**
 * Header — site-wide top of every page.
 *
 * Skip-link, custom logo, primary nav (with dropdown support via
 * the default WP walker), the engine badge for the currently
 * active engine, theme toggle (dark/light), and the "Buy plugin"
 * CTA. Mobile menu opens via the burger button on the right.
 *
 * @package rmaps-theme
 */
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="theme-color" content="#0b0d10" media="(prefers-color-scheme: dark)">
	<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
	<link rel="profile" href="https://gmpg.org/xfn/11">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="rmaps-theme-skip-link screen-reader-text" href="#rmaps-theme-main">
	<?php esc_html_e( 'Skip to content', 'rmaps-theme' ); ?>
</a>

<header class="rmaps-theme-site-header" role="banner">
	<div class="rmaps-theme-container rmaps-theme-header-inner">
		<div class="rmaps-theme-brand">
			<?php if ( has_custom_logo() ) : ?>
				<?php the_custom_logo(); ?>
			<?php else : ?>
				<a class="rmaps-theme-brand-link" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
					<span class="rmaps-theme-brand-mark" aria-hidden="true">
						<svg viewBox="0 0 32 32" focusable="false">
							<path fill="currentColor" d="M16 2c5 0 9 4 9 9 0 6.5-7.7 18-8.1 18.6a1 1 0 0 1-1.8 0C14.7 29 7 17.5 7 11c0-5 4-9 9-9zm0 12.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z"/>
						</svg>
					</span>
					<span class="rmaps-theme-brand-name"><?php bloginfo( 'name' ); ?></span>
				</a>
			<?php endif; ?>
		</div>

		<button class="rmaps-theme-burger" type="button" aria-expanded="false"
				aria-controls="rmaps-theme-primary-menu"
				aria-label="<?php esc_attr_e( 'Toggle menu', 'rmaps-theme' ); ?>">
			<span></span><span></span><span></span>
		</button>

		<nav class="rmaps-theme-primary-nav" id="rmaps-theme-primary-menu" aria-label="<?php esc_attr_e( 'Primary', 'rmaps-theme' ); ?>">
			<?php
			if ( has_nav_menu( 'primary' ) ) {
				wp_nav_menu( array(
					'theme_location'  => 'primary',
					'container'       => false,
					'menu_class'      => 'rmaps-theme-menu',
					'depth'           => 2,
					'fallback_cb'     => false,
				) );
			} else {
				echo '<ul class="rmaps-theme-menu rmaps-theme-menu-fallback">';
				printf( '<li><a href="%s">%s</a></li>',
					esc_url( home_url( '/' ) ),
					esc_html__( 'Home', 'rmaps-theme' )
				);
				echo '</ul>';
				echo '<p class="rmaps-theme-menu-hint">'
					. esc_html__( 'Set up a menu in Appearance → Menus and assign it to the “Header menu” location.', 'rmaps-theme' )
					. '</p>';
			}
			?>

			<div class="rmaps-theme-header-actions">
				<?php
				$active_engine = rmaps_theme_active_engine();
				$engines       = rmaps_theme_engine_options();
				$engine_label  = $engines[ $active_engine ]['short'] ?? $active_engine;
				// Same URL chain `[rmaps_engine_switcher]` builds: drop
				// `rmaps_engine` from the current URL so we don't stack
				// duplicates when re-attaching it per item.
				$rmaps_theme_path        = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
				$rmaps_theme_base_url    = remove_query_arg( 'rmaps_engine', home_url( $rmaps_theme_path ) );
				$rmaps_theme_override_ok = defined( 'RMAPS_ALLOW_ENGINE_URL_OVERRIDE' ) && RMAPS_ALLOW_ENGINE_URL_OVERRIDE;
				?>
				<div class="rmaps-theme-engine-switch rmaps-theme-engine-<?php echo esc_attr( $active_engine ); ?><?php echo $rmaps_theme_override_ok ? '' : ' is-locked'; ?>"
					data-active-engine="<?php echo esc_attr( $active_engine ); ?>">
					<button class="rmaps-theme-engine-switch-trigger rmaps-theme-engine-badge"
							type="button"
							aria-haspopup="menu"
							aria-expanded="false"
							aria-label="<?php esc_attr_e( 'Switch map engine', 'rmaps-theme' ); ?>"
							title="<?php esc_attr_e( 'Active map engine — hover to switch', 'rmaps-theme' ); ?>">
						<span class="rmaps-theme-engine-dot" aria-hidden="true"></span>
						<span class="rmaps-theme-engine-badge-label"><?php echo esc_html( $engine_label ); ?></span>
						<svg class="rmaps-theme-engine-switch-caret" viewBox="0 0 12 8" aria-hidden="true" focusable="false">
							<path fill="currentColor" d="M6 8L0 0h12z"/>
						</svg>
					</button>
					<ul class="rmaps-theme-engine-switch-menu" role="menu"
							aria-label="<?php esc_attr_e( 'Choose map engine', 'rmaps-theme' ); ?>">
						<?php foreach ( $engines as $slug => $meta ) :
							$is_active = $slug === $active_engine;
							$href      = add_query_arg( 'rmaps_engine', $slug, $rmaps_theme_base_url );
							?>
							<li role="none">
								<a class="rmaps-theme-engine-button rmaps-theme-engine-button-<?php echo esc_attr( $slug ); ?><?php echo $is_active ? ' is-active' : ''; ?>"
									role="menuitem"
									href="<?php echo esc_url( $href ); ?>"
									data-engine="<?php echo esc_attr( $slug ); ?>"
									aria-pressed="<?php echo $is_active ? 'true' : 'false'; ?>"
									rel="nofollow">
									<span class="rmaps-theme-engine-button-icon" aria-hidden="true">
										<?php echo rmaps_theme_engine_icon_svg( $slug ); // phpcs:ignore — static SVG strings ?>
									</span>
									<span class="rmaps-theme-engine-button-label"><?php echo esc_html( $meta['label'] ); ?></span>
									<?php if ( $is_active ) : ?>
										<span class="screen-reader-text"><?php esc_html_e( '(active)', 'rmaps-theme' ); ?></span>
									<?php endif; ?>
								</a>
							</li>
						<?php endforeach; ?>
						<?php if ( ! $rmaps_theme_override_ok ) : ?>
							<li class="rmaps-theme-engine-switch-notice-row" role="none">
								<p class="rmaps-theme-engine-switch-notice">
									<?php
									printf(
										/* translators: %s: HTML-escaped wp-config constant snippet. */
										esc_html__( 'URL switching is disabled. Add %s to wp-config.php.', 'rmaps-theme' ),
										'<code>RMAPS_ALLOW_ENGINE_URL_OVERRIDE</code>'
									);
									?>
								</p>
							</li>
						<?php endif; ?>
					</ul>
				</div>

				<button class="rmaps-theme-mode-toggle" type="button"
						aria-label="<?php esc_attr_e( 'Toggle dark mode', 'rmaps-theme' ); ?>"
						title="<?php esc_attr_e( 'Toggle dark mode', 'rmaps-theme' ); ?>">
					<svg class="rmaps-theme-icon-sun" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
						<path fill="currentColor" d="M12 4V2m0 20v-2m8-8h2M2 12h2m13.7-5.7l1.4-1.4M4.9 19.1l1.4-1.4m11.4 0l1.4 1.4M4.9 4.9l1.4 1.4M12 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10z"
							stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/>
					</svg>
					<svg class="rmaps-theme-icon-moon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
						<path fill="currentColor" d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>
					</svg>
				</button>

				<?php
				// "Submit marker" — auto-discovers the page that
				// carries the `[webmap-submit]` shortcode via the
				// plugin's `RMAPS_Dashboard_Helpers::find_page_id()`
				// (cached scan of post_content). Site owners can still
				// hard-code a URL by setting the `rmaps_theme_submit_url`
				// theme mod in Appearance → Customize — overrides the
				// auto-discovery.
				//
				// Button is hidden entirely when no submit page exists
				// AND no override is set — better than dangling a CTA
				// that lands the visitor on the homepage.
				$rmaps_theme_submit_override = get_theme_mod( 'rmaps_theme_submit_url', '' );
				$rmaps_theme_submit_url      = '';
				if ( $rmaps_theme_submit_override !== '' ) {
					$rmaps_theme_submit_url = $rmaps_theme_submit_override;
				} elseif ( class_exists( 'RMAPS_Dashboard_Helpers' ) ) {
					$rmaps_theme_submit_page_id = RMAPS_Dashboard_Helpers::find_page_id( 'webmap-submit' );
					if ( $rmaps_theme_submit_page_id ) {
						$rmaps_theme_submit_url = (string) get_permalink( $rmaps_theme_submit_page_id );
					}
				}
				if ( $rmaps_theme_submit_url !== '' ) : ?>
					<a class="rmaps-theme-cta-submit" href="<?php echo esc_url( $rmaps_theme_submit_url ); ?>">
						<?php esc_html_e( 'Submit marker', 'rmaps-theme' ); ?>
					</a>
				<?php endif; ?>

				<a class="rmaps-theme-cta-buy" href="<?php
					$buy = get_theme_mod( 'rmaps_theme_buy_url', 'https://www.salephpscripts.com/wordpress_maps/' );
					echo esc_url( $buy );
				?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Buy plugin', 'rmaps-theme' ); ?>
				</a>
			</div>
		</nav>
	</div>
</header>

<main id="rmaps-theme-main" class="rmaps-theme-main" tabindex="-1">
