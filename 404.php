<?php
/**
 * 404 — Not found.
 *
 * @package rmaps-theme
 */
get_header(); ?>

<section class="rmaps-section rmaps-section-404">
	<div class="rmaps-container">
		<h1 class="rmaps-404-code">404</h1>
		<h2 class="rmaps-page-title"><?php esc_html_e( 'Page not found', 'rmaps-theme' ); ?></h2>
		<p class="rmaps-404-body">
			<?php esc_html_e( 'The page you are looking for has moved or never existed.', 'rmaps-theme' ); ?>
		</p>
		<p>
			<a class="rmaps-button rmaps-button-primary" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<?php esc_html_e( 'Back to homepage', 'rmaps-theme' ); ?>
			</a>
		</p>
	</div>
</section>

<?php get_footer(); ?>
