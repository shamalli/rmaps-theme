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

<a class="rmaps-skip-link screen-reader-text" href="#rmaps-main">
	<?php esc_html_e( 'Skip to content', 'rmaps-theme' ); ?>
</a>

<header class="rmaps-site-header" role="banner">
	<div class="rmaps-container rmaps-header-inner">
		<div class="rmaps-brand">
			<?php if ( has_custom_logo() ) : ?>
				<?php the_custom_logo(); ?>
			<?php else : ?>
				<a class="rmaps-brand-link" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
					<span class="rmaps-brand-mark" aria-hidden="true">
						<svg viewBox="0 0 32 32" focusable="false">
							<path fill="currentColor" d="M16 2c5 0 9 4 9 9 0 6.5-7.7 18-8.1 18.6a1 1 0 0 1-1.8 0C14.7 29 7 17.5 7 11c0-5 4-9 9-9zm0 12.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z"/>
						</svg>
					</span>
					<span class="rmaps-brand-name"><?php bloginfo( 'name' ); ?></span>
				</a>
			<?php endif; ?>
		</div>

		<button class="rmaps-burger" type="button" aria-expanded="false"
				aria-controls="rmaps-primary-menu"
				aria-label="<?php esc_attr_e( 'Toggle menu', 'rmaps-theme' ); ?>">
			<span></span><span></span><span></span>
		</button>

		<nav class="rmaps-primary-nav" id="rmaps-primary-menu" aria-label="<?php esc_attr_e( 'Primary', 'rmaps-theme' ); ?>">
			<?php
			if ( has_nav_menu( 'primary' ) ) {
				wp_nav_menu( array(
					'theme_location'  => 'primary',
					'container'       => false,
					'menu_class'      => 'rmaps-menu',
					'depth'           => 2,
					'fallback_cb'     => false,
				) );
			} else {
				echo '<ul class="rmaps-menu rmaps-menu-fallback">';
				printf( '<li><a href="%s">%s</a></li>',
					esc_url( home_url( '/' ) ),
					esc_html__( 'Home', 'rmaps-theme' )
				);
				echo '</ul>';
				echo '<p class="rmaps-menu-hint">'
					. esc_html__( 'Set up a menu in Appearance → Menus and assign it to the “Header menu” location.', 'rmaps-theme' )
					. '</p>';
			}
			?>

			<div class="rmaps-header-actions">
				<?php
				$active_engine = rmaps_theme_active_engine();
				$engines       = rmaps_theme_engine_options();
				$engine_label  = $engines[ $active_engine ]['short'] ?? $active_engine;
				?>
				<span class="rmaps-engine-badge" title="<?php esc_attr_e( 'Active map engine', 'rmaps-theme' ); ?>">
					<span class="rmaps-engine-dot" aria-hidden="true"></span>
					<span class="rmaps-engine-badge-label"><?php echo esc_html( $engine_label ); ?></span>
				</span>

				<button class="rmaps-theme-toggle" type="button"
						aria-label="<?php esc_attr_e( 'Toggle dark mode', 'rmaps-theme' ); ?>">
					<svg class="rmaps-icon-sun" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
						<path fill="currentColor" d="M12 4V2m0 20v-2m8-8h2M2 12h2m13.7-5.7l1.4-1.4M4.9 19.1l1.4-1.4m11.4 0l1.4 1.4M4.9 4.9l1.4 1.4M12 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10z"
							stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/>
					</svg>
					<svg class="rmaps-icon-moon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
						<path fill="currentColor" d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>
					</svg>
				</button>

				<a class="rmaps-cta-buy" href="<?php
					$buy = get_theme_mod( 'rmaps_buy_url', 'https://www.salephpscripts.com/wordpress_maps/' );
					echo esc_url( $buy );
				?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Buy plugin', 'rmaps-theme' ); ?>
				</a>
			</div>
		</nav>
	</div>
</header>

<main id="rmaps-main" class="rmaps-main" tabindex="-1">
