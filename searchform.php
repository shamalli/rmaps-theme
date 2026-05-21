<?php
/**
 * Search form — used by `get_search_form()` and the WP search block.
 *
 * @package rmaps-theme
 */
?>
<form role="search" method="get" class="rmaps-search-form" action="<?php echo esc_url( home_url( '/' ) ); ?>">
	<label for="rmaps-s">
		<span class="screen-reader-text"><?php esc_html_e( 'Search for:', 'rmaps-theme' ); ?></span>
		<input type="search" id="rmaps-s" class="rmaps-search-field" name="s"
				value="<?php echo esc_attr( get_search_query() ); ?>"
				placeholder="<?php esc_attr_e( 'Search…', 'rmaps-theme' ); ?>">
	</label>
	<button type="submit" class="rmaps-search-submit">
		<?php esc_html_e( 'Search', 'rmaps-theme' ); ?>
	</button>
</form>
