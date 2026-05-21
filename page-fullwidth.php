<?php
/**
 * Template Name: Full width (100%)
 * Template Post Type: page
 *
 * Full-bleed page layout — no `rmaps-container` constraint on the
 * content area, so the map / engine switcher / hero blocks can run
 * edge to edge. Title + description still respect a `rmaps-prose`
 * wrapper so long-form text stays readable.
 *
 * @package rmaps-theme
 */
get_header(); ?>

<article id="page-<?php the_ID(); ?>" <?php post_class( 'rmaps-page rmaps-page-fullwidth' ); ?>>
	<?php while ( have_posts() ) : the_post(); ?>
		<?php if ( ! get_post_meta( get_the_ID(), '_rmaps_hide_title', true ) ) : ?>
			<header class="rmaps-page-header rmaps-container">
				<h1 class="rmaps-page-title"><?php the_title(); ?></h1>
			</header>
		<?php endif; ?>

		<div class="rmaps-page-content rmaps-page-content-full">
			<?php the_content(); ?>
		</div>
	<?php endwhile; ?>
</article>

<?php get_footer(); ?>
