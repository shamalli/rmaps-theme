<?php
/**
 * Blog / archive / fallback. Almost any non-page request lands here.
 *
 * @package rmaps-theme
 */
get_header(); ?>

<section class="rmaps-theme-section rmaps-theme-section-archive">
	<div class="rmaps-theme-container">
		<?php if ( is_home() && ! is_front_page() ) : ?>
			<h1 class="rmaps-theme-page-title"><?php single_post_title(); ?></h1>
		<?php elseif ( is_archive() ) : ?>
			<h1 class="rmaps-theme-page-title"><?php the_archive_title(); ?></h1>
			<?php
			$description = get_the_archive_description();
			if ( $description ) {
				echo '<div class="rmaps-theme-archive-description">' . wp_kses_post( wpautop( $description ) ) . '</div>';
			}
			?>
		<?php elseif ( is_search() ) : ?>
			<h1 class="rmaps-theme-page-title">
				<?php
				/* translators: %s: search query. */
				printf( esc_html__( 'Search results for: %s', 'rmaps-theme' ), '<span>' . esc_html( get_search_query() ) . '</span>' );
				?>
			</h1>
		<?php endif; ?>

		<?php if ( have_posts() ) : ?>
			<div class="rmaps-theme-post-list">
				<?php while ( have_posts() ) : the_post(); ?>
					<article id="post-<?php the_ID(); ?>" <?php post_class( 'rmaps-theme-post-card' ); ?>>
						<?php if ( has_post_thumbnail() ) : ?>
							<a class="rmaps-theme-post-thumb" href="<?php the_permalink(); ?>">
								<?php the_post_thumbnail( 'medium_large', array( 'loading' => 'lazy', 'alt' => the_title_attribute( array( 'echo' => false ) ) ) ); ?>
							</a>
						<?php endif; ?>
						<h2 class="rmaps-theme-post-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
						<div class="rmaps-theme-post-meta">
							<time datetime="<?php echo esc_attr( get_the_date( DATE_W3C ) ); ?>">
								<?php echo esc_html( get_the_date() ); ?>
							</time>
						</div>
						<div class="rmaps-theme-post-excerpt"><?php the_excerpt(); ?></div>
					</article>
				<?php endwhile; ?>
			</div>

			<nav class="rmaps-theme-pagination" aria-label="<?php esc_attr_e( 'Posts navigation', 'rmaps-theme' ); ?>">
				<?php
				the_posts_pagination( array(
					'prev_text' => esc_html__( 'Previous', 'rmaps-theme' ),
					'next_text' => esc_html__( 'Next', 'rmaps-theme' ),
				) );
				?>
			</nav>
		<?php else : ?>
			<p class="rmaps-theme-empty">
				<?php esc_html_e( 'Nothing here yet.', 'rmaps-theme' ); ?>
			</p>
		<?php endif; ?>
	</div>
</section>

<?php get_footer(); ?>
