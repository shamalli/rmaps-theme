<?php
/**
 * Template Name: Full width (100%)
 * Template Post Type: page
 *
 * Full-bleed page layout — no `rmaps-theme-container` constraint on the
 * content area, so the map / engine switcher / hero blocks can run
 * edge to edge. Title + description still respect a `rmaps-theme-prose`
 * wrapper so long-form text stays readable.
 *
 * @package rmaps-theme
 */
get_header(); ?>

<article id="page-<?php the_ID(); ?>" <?php post_class( 'rmaps-theme-page rmaps-theme-page-fullwidth' ); ?>>
	<?php while ( have_posts() ) : the_post(); ?>
		<?php if ( ! get_post_meta( get_the_ID(), '_rmaps_theme_hide_title', true ) ) : ?>
			<header class="rmaps-theme-page-header rmaps-theme-container">
				<h1 class="rmaps-theme-page-title"><?php the_title(); ?></h1>
			</header>
		<?php endif; ?>

		<div class="rmaps-theme-page-content rmaps-theme-page-content-full">
			<?php the_content(); ?>
		</div>
	<?php endwhile; ?>
</article>

<?php get_footer(); ?>
