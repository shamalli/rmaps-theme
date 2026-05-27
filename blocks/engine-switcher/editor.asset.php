<?php
/**
 * Dependency manifest for `editor.js`.
 *
 * `@wordpress/scripts` normally generates this file at build time; since
 * this theme is build-free we hand-write it. `register_block_type` reads
 * `editor.asset.php` (matching the `editor.js` basename) and uses its
 * `dependencies` array as the script's deps + `version` for cache-
 * busting — so the editor bundle loads only after the listed `wp-*`
 * packages are available on `window.wp`.
 */
return array(
	'dependencies' => array(
		'wp-blocks',
		'wp-block-editor',
		'wp-element',
		'wp-components',
		'wp-i18n',
	),
	'version' => '1.0.0',
);
