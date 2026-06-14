<?php
/**
 * Uploads cleanup — Tools → Media Cleanup.
 *
 * Finds ORPHANED media files: files on disk under wp-content/uploads/
 * that have NO Media Library attachment (original, generated size, or
 * `-scaled` original). These are leftovers from imports, deleted
 * attachments, partial CSV runs, etc. — WordPress no longer references
 * them through the Media Library.
 *
 * The Media Library is NEVER touched: a real attachment (and every
 * registered thumbnail size it produced) is treated as "known" and kept.
 * Only files WordPress doesn't know about are candidates.
 *
 * Action is REVERSIBLE: orphans are MOVED to a quarantine folder
 * (uploads/_trash/, same relative path) rather than deleted. Review the
 * preview first; restore by moving files back, or empty the quarantine to
 * delete permanently. manage_options only.
 *
 * Scope is limited to image extensions so plugin DATA files in uploads
 * (.json / .log / .php …) are never moved. Extend via the
 * `rmaps_theme_cleanup_extensions` / `rmaps_theme_cleanup_exclude_prefixes`
 * filters.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const RMAPS_THEME_CLEANUP_TRASH = '_trash';
const RMAPS_THEME_CLEANUP_MAX_ROWS = 500; // cap the preview table; counts/sizes are always full.

/** Image extensions considered orphan candidates. */
function rmaps_theme_cleanup_extensions() {
	return (array) apply_filters( 'rmaps_theme_cleanup_extensions', array(
		'jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'avif', 'bmp', 'svg', 'ico', 'tiff', 'tif', 'heic',
	) );
}

/** Relative-path prefixes (under uploads/) to skip entirely. The quarantine
 *  folder is always skipped; this filter lets a site keep e.g. a plugin's
 *  own uploads subfolder out of the scan. */
function rmaps_theme_cleanup_exclude_prefixes() {
	$default = array( RMAPS_THEME_CLEANUP_TRASH . '/' );
	return (array) apply_filters( 'rmaps_theme_cleanup_exclude_prefixes', $default );
}

/**
 * Set of upload-relative file paths WordPress knows about: every
 * attachment original, its `-scaled` source (`original_image`), and every
 * generated size. Keys are normalised `YYYY/MM/file.ext` paths.
 *
 * @return array<string,true>
 */
function rmaps_theme_cleanup_known_files() {
	global $wpdb;
	$known = array();

	// Originals (the file each attachment points at).
	$origs = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'" );
	foreach ( (array) $origs as $rel ) {
		$rel = ltrim( str_replace( '\\', '/', (string) $rel ), '/' );
		if ( '' !== $rel ) {
			$known[ $rel ] = true;
		}
	}

	// Metadata: the `-scaled` original + every generated size live in the
	// SAME directory as the metadata's `file`.
	$metas = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata'" );
	foreach ( (array) $metas as $blob ) {
		$m = maybe_unserialize( $blob );
		if ( ! is_array( $m ) ) {
			continue;
		}
		$file = isset( $m['file'] ) ? ltrim( str_replace( '\\', '/', (string) $m['file'] ), '/' ) : '';
		if ( '' !== $file ) {
			$known[ $file ] = true;
		}
		$dir = ( '' !== $file ) ? dirname( $file ) : '.';
		$dir = ( '.' === $dir ) ? '' : trailingslashit( $dir );

		if ( ! empty( $m['original_image'] ) ) {
			$known[ $dir . basename( (string) $m['original_image'] ) ] = true;
		}
		if ( ! empty( $m['sizes'] ) && is_array( $m['sizes'] ) ) {
			foreach ( $m['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$known[ $dir . basename( (string) $size['file'] ) ] = true;
				}
			}
		}
	}

	return $known;
}

/**
 * Scan uploads/ for orphan image files (image type, not known to WP, not
 * under an excluded prefix).
 *
 * @return array<int,array{rel:string,size:int}>
 */
function rmaps_theme_cleanup_scan() {
	$up   = wp_get_upload_dir();
	$base = trailingslashit( str_replace( '\\', '/', (string) $up['basedir'] ) );
	if ( ! is_dir( $base ) ) {
		return array();
	}

	$known    = rmaps_theme_cleanup_known_files();
	$exts     = array_flip( array_map( 'strtolower', rmaps_theme_cleanup_extensions() ) );
	$excludes = rmaps_theme_cleanup_exclude_prefixes();
	$orphans  = array();

	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS ),
		RecursiveIteratorIterator::LEAVES_ONLY
	);
	foreach ( $it as $fileinfo ) {
		if ( ! $fileinfo->isFile() ) {
			continue;
		}
		$full = str_replace( '\\', '/', $fileinfo->getPathname() );
		$rel  = ltrim( substr( $full, strlen( $base ) ), '/' );
		if ( '' === $rel ) {
			continue;
		}

		$skip = false;
		foreach ( $excludes as $prefix ) {
			if ( '' !== $prefix && 0 === strpos( $rel, $prefix ) ) {
				$skip = true;
				break;
			}
		}
		if ( $skip ) {
			continue;
		}

		$ext = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
		if ( ! isset( $exts[ $ext ] ) ) {
			continue; // only image types — never touch plugin data files
		}
		if ( isset( $known[ $rel ] ) ) {
			continue; // a real attachment / size — keep
		}

		$orphans[] = array( 'rel' => $rel, 'size' => (int) $fileinfo->getSize() );
	}

	usort( $orphans, static function ( $a, $b ) {
		return $b['size'] <=> $a['size']; // biggest first
	} );
	return $orphans;
}

/**
 * Move the current orphans into uploads/_trash/, preserving relative path.
 * Re-scans internally so only genuine orphans at action time are moved.
 *
 * @return array{moved:int,bytes:int,failed:int}
 */
function rmaps_theme_cleanup_move_to_trash() {
	$up        = wp_get_upload_dir();
	$base      = trailingslashit( str_replace( '\\', '/', (string) $up['basedir'] ) );
	$trashBase = $base . RMAPS_THEME_CLEANUP_TRASH . '/';

	$moved  = 0;
	$bytes  = 0;
	$failed = 0;

	foreach ( rmaps_theme_cleanup_scan() as $orphan ) {
		$rel = $orphan['rel'];
		if ( false !== strpos( $rel, '..' ) ) {
			continue; // paranoia — scan never produces this
		}
		$src = $base . $rel;
		if ( ! is_file( $src ) ) {
			continue;
		}
		$dst = $trashBase . $rel;
		wp_mkdir_p( dirname( $dst ) );
		$size = (int) @filesize( $src );
		if ( @rename( $src, $dst ) ) {
			$moved++;
			$bytes += $size;
		} else {
			$failed++;
		}
	}

	return array( 'moved' => $moved, 'bytes' => $bytes, 'failed' => $failed );
}

/** Count + total bytes currently sitting in the quarantine folder. */
function rmaps_theme_cleanup_trash_info() {
	$up        = wp_get_upload_dir();
	$trashBase = trailingslashit( str_replace( '\\', '/', (string) $up['basedir'] ) ) . RMAPS_THEME_CLEANUP_TRASH;
	$count     = 0;
	$bytes     = 0;
	if ( is_dir( $trashBase ) ) {
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $trashBase, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ( $it as $fi ) {
			if ( $fi->isFile() ) {
				$count++;
				$bytes += (int) $fi->getSize();
			}
		}
	}
	return array( 'count' => $count, 'bytes' => $bytes );
}

/**
 * Permanently delete everything in the quarantine folder (files + emptied
 * subdirs).
 *
 * @return array{deleted:int,bytes:int}
 */
function rmaps_theme_cleanup_empty_trash() {
	$up        = wp_get_upload_dir();
	$trashBase = trailingslashit( str_replace( '\\', '/', (string) $up['basedir'] ) ) . RMAPS_THEME_CLEANUP_TRASH;
	$deleted   = 0;
	$bytes     = 0;
	if ( ! is_dir( $trashBase ) ) {
		return array( 'deleted' => 0, 'bytes' => 0 );
	}
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $trashBase, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $it as $fi ) {
		if ( $fi->isFile() || $fi->isLink() ) {
			$size = (int) @filesize( $fi->getPathname() );
			if ( @unlink( $fi->getPathname() ) ) {
				$deleted++;
				$bytes += $size;
			}
		} elseif ( $fi->isDir() ) {
			@rmdir( $fi->getPathname() );
		}
	}
	return array( 'deleted' => $deleted, 'bytes' => $bytes );
}

/* ---------------------------------------------------------------
 * Admin page (Tools → Media Cleanup)
 * ---------------------------------------------------------------*/
add_action( 'admin_menu', function () {
	add_management_page(
		__( 'Media Cleanup', 'rmaps-theme' ),
		__( 'Media Cleanup', 'rmaps-theme' ),
		'manage_options',
		'rmaps-media-cleanup',
		'rmaps_theme_cleanup_render_page'
	);
} );

function rmaps_theme_cleanup_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'rmaps-theme' ) );
	}

	$notice = '';
	$ntype  = 'success';
	if ( isset( $_POST['rmaps_cleanup_action'] ) ) {
		check_admin_referer( 'rmaps_cleanup' );
		$action = sanitize_key( wp_unslash( $_POST['rmaps_cleanup_action'] ) );
		if ( 'trash' === $action ) {
			$r = rmaps_theme_cleanup_move_to_trash();
			$notice = sprintf(
				/* translators: 1: file count, 2: human size, 3: failed count */
				__( 'Moved %1$d orphan file(s) (%2$s) to the quarantine folder.', 'rmaps-theme' ),
				$r['moved'],
				size_format( $r['bytes'] )
			);
			if ( $r['failed'] > 0 ) {
				$notice .= ' ' . sprintf( __( '%d file(s) could not be moved (permissions?).', 'rmaps-theme' ), $r['failed'] );
				$ntype   = 'warning';
			}
		} elseif ( 'empty' === $action ) {
			$r = rmaps_theme_cleanup_empty_trash();
			$notice = sprintf(
				/* translators: 1: file count, 2: human size */
				__( 'Permanently deleted %1$d quarantined file(s) (%2$s).', 'rmaps-theme' ),
				$r['deleted'],
				size_format( $r['bytes'] )
			);
		}
	}

	$orphans = rmaps_theme_cleanup_scan();
	$total   = array_sum( wp_list_pluck( $orphans, 'size' ) );
	$trash   = rmaps_theme_cleanup_trash_info();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Media Cleanup', 'rmaps-theme' ); ?></h1>

		<?php if ( '' !== $notice ) : ?>
			<div class="notice notice-<?php echo esc_attr( $ntype ); ?> is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<p><?php esc_html_e( 'Orphaned media files are image files on disk under wp-content/uploads/ that have NO entry in the Media Library (leftovers from imports, deleted attachments, etc.). The Media Library is never changed — only files WordPress no longer tracks are listed.', 'rmaps-theme' ); ?></p>
		<p><?php
			printf(
				/* translators: 1: count, 2: size */
				esc_html__( 'Found %1$s orphan file(s), %2$s total.', 'rmaps-theme' ),
				'<strong>' . esc_html( number_format_i18n( count( $orphans ) ) ) . '</strong>',
				'<strong>' . esc_html( size_format( $total ) ) . '</strong>'
			);
		?></p>

		<?php if ( $orphans ) : ?>
			<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Move all listed orphan files to the quarantine folder (uploads/_trash/)? This is reversible.', 'rmaps-theme' ) ); ?>');">
				<?php wp_nonce_field( 'rmaps_cleanup' ); ?>
				<input type="hidden" name="rmaps_cleanup_action" value="trash">
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Move all orphans to quarantine', 'rmaps-theme' ); ?></button>
				<span class="description"><?php esc_html_e( 'Reversible — files are moved to uploads/_trash/, not deleted.', 'rmaps-theme' ); ?></span></p>
			</form>

			<table class="widefat striped" style="max-width:900px">
				<thead><tr>
					<th><?php esc_html_e( 'File (relative to uploads/)', 'rmaps-theme' ); ?></th>
					<th style="width:120px"><?php esc_html_e( 'Size', 'rmaps-theme' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( array_slice( $orphans, 0, RMAPS_THEME_CLEANUP_MAX_ROWS ) as $o ) : ?>
					<tr><td><code><?php echo esc_html( $o['rel'] ); ?></code></td><td><?php echo esc_html( size_format( $o['size'] ) ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( count( $orphans ) > RMAPS_THEME_CLEANUP_MAX_ROWS ) : ?>
				<p class="description"><?php
					printf(
						esc_html__( 'Showing the %1$s largest of %2$s files.', 'rmaps-theme' ),
						esc_html( number_format_i18n( RMAPS_THEME_CLEANUP_MAX_ROWS ) ),
						esc_html( number_format_i18n( count( $orphans ) ) )
					);
				?></p>
			<?php endif; ?>
		<?php else : ?>
			<p><em><?php esc_html_e( 'No orphan files found — uploads/ is clean.', 'rmaps-theme' ); ?></em></p>
		<?php endif; ?>

		<hr>
		<h2><?php esc_html_e( 'Quarantine (uploads/_trash/)', 'rmaps-theme' ); ?></h2>
		<p><?php
			printf(
				/* translators: 1: count, 2: size */
				esc_html__( '%1$s file(s), %2$s. Restore anything by moving it back from uploads/_trash/ (same path), or empty the quarantine to delete permanently.', 'rmaps-theme' ),
				'<strong>' . esc_html( number_format_i18n( $trash['count'] ) ) . '</strong>',
				'<strong>' . esc_html( size_format( $trash['bytes'] ) ) . '</strong>'
			);
		?></p>
		<?php if ( $trash['count'] > 0 ) : ?>
			<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Permanently delete everything in the quarantine folder? This cannot be undone.', 'rmaps-theme' ) ); ?>');">
				<?php wp_nonce_field( 'rmaps_cleanup' ); ?>
				<input type="hidden" name="rmaps_cleanup_action" value="empty">
				<p><button type="submit" class="button button-secondary"><?php esc_html_e( 'Empty quarantine (permanent)', 'rmaps-theme' ); ?></button></p>
			</form>
		<?php endif; ?>
	</div>
	<?php
}
