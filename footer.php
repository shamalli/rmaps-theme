<?php
/**
 * Footer - closes the <main>, renders the footer nav + colophon,
 * fires wp_footer() so plugins (incl. the React frontend mount)
 * get their late-stage hooks.
 *
 * @package rmaps-theme
 */
?>
</main>

<footer class="rmaps-theme-site-footer" role="contentinfo">
	<div class="rmaps-theme-container rmaps-theme-footer-inner">
		<div class="rmaps-theme-footer-brand">
			<span class="rmaps-theme-brand-mark rmaps-theme-brand-mark--logo" aria-hidden="true">
				<img src="<?php echo esc_url( get_theme_file_uri( 'assets/images/logo-mark.png' ) ); ?>" alt="" width="258" height="512" decoding="async" />
			</span>
			<p class="rmaps-theme-footer-tagline">
				<?php esc_html_e( 'Rocket Maps — fast, searchable maps for WordPress.', 'rmaps-theme' ); ?>
			</p>
		</div>

		<nav class="rmaps-theme-footer-nav" aria-label="<?php esc_attr_e( 'Footer', 'rmaps-theme' ); ?>">
			<?php
			// Flat footer rendering - parents + children appear side by
			// side, no dropdowns. Falls back to the header menu when no
			// separate Footer menu is assigned. See
			// `rmaps_theme_render_flat_menu()` in functions.php.
			if ( ! rmaps_theme_render_flat_menu( 'footer' ) ) {
				rmaps_theme_render_flat_menu( 'primary' );
			}
			?>
		</nav>

		<p class="rmaps-theme-colophon">
			&copy; <?php echo esc_html( date_i18n( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?>.
			<?php esc_html_e( 'All rights reserved.', 'rmaps-theme' ); ?>
		</p>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
