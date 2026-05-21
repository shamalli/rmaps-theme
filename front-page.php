<?php
/**
 * Front page — same shape as a full-width page but always renders
 * the feature grid below the content. Falls back to a hero when
 * there's no editor content yet.
 *
 * @package rmaps-theme
 */
get_header(); ?>

<article id="page-<?php the_ID(); ?>" <?php post_class( 'rmaps-page rmaps-page-fullwidth rmaps-front-page' ); ?>>
	<?php if ( have_posts() ) : while ( have_posts() ) : the_post();
		$has_content = trim( wp_strip_all_tags( get_the_content() ) ) !== '';
		?>
		<?php if ( $has_content ) : ?>
			<div class="rmaps-page-content rmaps-page-content-full">
				<?php the_content(); ?>
			</div>
		<?php else : ?>
			<section class="rmaps-hero">
				<div class="rmaps-container">
					<h1 class="rmaps-hero-title"><?php bloginfo( 'name' ); ?></h1>
					<p class="rmaps-hero-tagline"><?php bloginfo( 'description' ); ?></p>
					<p class="rmaps-hero-cta">
						<a class="rmaps-button rmaps-button-primary" href="<?php
							$buy = get_theme_mod( 'rmaps_buy_url', 'https://www.salephpscripts.com/wordpress_maps/' );
							echo esc_url( $buy );
						?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Buy plugin', 'rmaps-theme' ); ?>
						</a>
						<a class="rmaps-button rmaps-button-ghost" href="#features">
							<?php esc_html_e( 'See features', 'rmaps-theme' ); ?>
						</a>
					</p>
				</div>
			</section>
		<?php endif; ?>
	<?php endwhile; endif; ?>

	<div id="features"></div>
	<?php get_template_part( 'template-parts/feature-grid' ); ?>
</article>

<?php get_footer(); ?>
