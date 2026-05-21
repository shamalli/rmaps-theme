<?php
/**
 * Default page template — standard centered layout, content wrapped
 * in a container. The 100%-width variant lives in
 * `page-fullwidth.php`. Both share the same header/footer.
 *
 * @package rmaps-theme
 */
get_header(); ?>

<article id="page-<?php the_ID(); ?>" <?php post_class( 'rmaps-page rmaps-page-standard' ); ?>>
	<?php while ( have_posts() ) : the_post(); ?>
		<header class="rmaps-page-header rmaps-container">
			<h1 class="rmaps-page-title"><?php the_title(); ?></h1>
		</header>

		<div class="rmaps-page-content rmaps-container">
			<?php the_content(); ?>
		</div>
	<?php endwhile; ?>
</article>

<?php get_footer(); ?>
