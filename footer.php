<?php
/**
 * Footer — closes the <main>, renders the footer nav + colophon,
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
			<span class="rmaps-theme-brand-mark" aria-hidden="true">
				<svg viewBox="0 0 32 32" focusable="false">
					<path fill="currentColor" d="M16 2c5 0 9 4 9 9 0 6.5-7.7 18-8.1 18.6a1 1 0 0 1-1.8 0C14.7 29 7 17.5 7 11c0-5 4-9 9-9zm0 12.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z"/>
				</svg>
			</span>
			<p class="rmaps-theme-footer-tagline">
				<?php esc_html_e( 'Rocket Maps — fast, searchable maps for WordPress.', 'rmaps-theme' ); ?>
			</p>
		</div>

		<nav class="rmaps-theme-footer-nav" aria-label="<?php esc_attr_e( 'Footer', 'rmaps-theme' ); ?>">
			<?php
			// Flat footer rendering — parents + children appear side by
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
