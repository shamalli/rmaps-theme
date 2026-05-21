<?php
/**
 * Single blog post.
 *
 * @package rmaps-theme
 */
get_header(); ?>

<article id="post-<?php the_ID(); ?>" <?php post_class( 'rmaps-page rmaps-page-standard' ); ?>>
	<?php while ( have_posts() ) : the_post(); ?>
		<header class="rmaps-page-header rmaps-container">
			<h1 class="rmaps-page-title"><?php the_title(); ?></h1>
			<div class="rmaps-post-meta">
				<time datetime="<?php echo esc_attr( get_the_date( DATE_W3C ) ); ?>">
					<?php echo esc_html( get_the_date() ); ?>
				</time>
				<span class="rmaps-post-author">
					<?php
					/* translators: %s: author display name. */
					printf( esc_html__( 'by %s', 'rmaps-theme' ), esc_html( get_the_author() ) );
					?>
				</span>
			</div>
		</header>

		<div class="rmaps-page-content rmaps-container">
			<?php the_content(); ?>
		</div>

		<?php if ( comments_open() || get_comments_number() ) : ?>
			<div class="rmaps-container rmaps-comments">
				<?php comments_template(); ?>
			</div>
		<?php endif; ?>
	<?php endwhile; ?>
</article>

<?php get_footer(); ?>
