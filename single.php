<?php
/**
 * Single blog post.
 *
 * @package rmaps-theme
 */
get_header(); ?>

<article id="post-<?php the_ID(); ?>" <?php post_class( 'rmaps-theme-page rmaps-theme-page-standard' ); ?>>
	<?php while ( have_posts() ) : the_post(); ?>
		<header class="rmaps-theme-page-header rmaps-theme-container">
			<h1 class="rmaps-theme-page-title"><?php the_title(); ?></h1>
			<div class="rmaps-theme-post-meta">
				<time datetime="<?php echo esc_attr( get_the_date( DATE_W3C ) ); ?>">
					<?php echo esc_html( get_the_date() ); ?>
				</time>
				<span class="rmaps-theme-post-author">
					<?php
					/* translators: %s: author display name. */
					printf( esc_html__( 'by %s', 'rmaps-theme' ), esc_html( get_the_author() ) );
					?>
				</span>
			</div>
		</header>

		<div class="rmaps-theme-page-content rmaps-theme-container">
			<?php the_content(); ?>
		</div>

		<?php if ( comments_open() || get_comments_number() ) : ?>
			<div class="rmaps-theme-container rmaps-theme-comments">
				<?php comments_template(); ?>
			</div>
		<?php endif; ?>
	<?php endwhile; ?>
</article>

<?php get_footer(); ?>
