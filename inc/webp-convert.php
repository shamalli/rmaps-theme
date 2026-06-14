<?php
/**
 * WebP conversion — Tools → WebP Convert.
 *
 * Converts every JPEG/PNG Media Library attachment (the original, its
 * `-scaled` source, and every generated thumbnail size) to WebP IN PLACE
 * and rewrites the attachment so WordPress serves the `.webp` files:
 *   - `_wp_attached_file`      -> the .webp original
 *   - `_wp_attachment_metadata` -> .webp `file` / `original_image` / every
 *      size `file` + `mime-type` => image/webp
 *   - `post_mime_type` / `guid` -> image/webp / .webp URL
 *
 * Because listing images are referenced by ATTACHMENT ID
 * (`_rmaps_attached_image`, `_thumbnail_id`, `_rmaps_logo_image_id`), the
 * popup / sidebar / marker logo all follow automatically. Hardcoded
 * `/uploads/` image URLs in post_content (editorial pages) are swapped
 * jpg/png -> webp as well.
 *
 * REVERSIBLE, like Media Cleanup: the original jpg/png files are MOVED to a
 * quarantine folder (uploads/_webp_orig/, same relative path), NOT deleted,
 * and a per-attachment backup of the old DB pointers is kept in the
 * `_rmaps_webp_backup` postmeta. Disk space is only reclaimed once you
 * "Finalize" (empty the quarantine) — until then both copies exist.
 *
 *   Scan/estimate  ->  Convert (batched)  ->  verify the site  ->  Finalize
 *                                          \-> or Restore (revert everything)
 *
 * manage_options only. Conversion uses WordPress' own image editor
 * (GD/Imagick), so the server must support WebP output.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Quarantine folder for the replaced originals (under uploads/). */
const RMAPS_THEME_WEBP_QUARANTINE = '_webp_orig';
/** Postmeta holding the pre-conversion DB pointers (enables Restore). */
const RMAPS_THEME_WEBP_BACKUP_KEY = '_rmaps_webp_backup';
/** Postmeta flag on attachments whose source couldn't be read/encoded, so the
 *  queue skips them instead of retrying the same broken files forever. */
const RMAPS_THEME_WEBP_FAILED_KEY = '_rmaps_webp_failed';
/** Max attachments processed per request (also bounded by the time budget).
 *  Kept high so file-less/phantom records — which return instantly without
 *  encoding — are skipped quickly; real encodes are capped by the time budget. */
const RMAPS_THEME_WEBP_BATCH = 50;
/** Soft wall-clock budget per request, seconds. */
const RMAPS_THEME_WEBP_TIME_BUDGET = 15;
/** Cap the estimate preview table. */
const RMAPS_THEME_WEBP_MAX_ROWS = 300;

/** Default WebP encode quality (filterable, also chosen in the UI). */
function rmaps_theme_webp_default_quality() {
	return (int) apply_filters( 'rmaps_theme_webp_quality', 82 );
}

/** Attachment MIME types we convert. */
function rmaps_theme_webp_target_mimes() {
	return (array) apply_filters( 'rmaps_theme_webp_target_mimes', array( 'image/jpeg', 'image/png' ) );
}

/** True when the active image editor can output WebP. */
function rmaps_theme_webp_supported() {
	return (bool) wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
}

/** Swap a .jpg/.jpeg/.png extension for .webp (leaves anything else as-is). */
function rmaps_theme_webp_swap_ext( $path ) {
	return preg_replace( '/\.(jpe?g|png)$/i', '.webp', (string) $path );
}

/**
 * old=>new URL replacements for one upload-relative file, covering both the
 * absolute URL (https://host/wp-content/uploads/…) and the root-relative path
 * (/wp-content/uploads/…) forms that may appear in post_content.
 *
 * @return array<string,string>
 */
function rmaps_theme_webp_url_pairs( $baseurl, $basepath, $rel ) {
	$new   = rmaps_theme_webp_swap_ext( $rel );
	$pairs = array( $baseurl . '/' . $rel => $baseurl . '/' . $new );
	if ( '' !== $basepath ) {
		$pairs[ $basepath . '/' . $rel ] = $basepath . '/' . $new;
	}
	return $pairs;
}

/** Keep the Media Cleanup scanner from treating quarantined originals as orphans. */
add_filter( 'rmaps_theme_cleanup_exclude_prefixes', function ( $prefixes ) {
	$prefixes   = (array) $prefixes;
	$prefixes[] = RMAPS_THEME_WEBP_QUARANTINE . '/';
	return $prefixes;
} );

/**
 * Upload-relative jpg/png files belonging to one attachment: the main file,
 * the unscaled `original_image`, and every generated size. Non-image and
 * already-webp entries are dropped.
 *
 * @return string[] relative paths (YYYY/MM/file.ext)
 */
function rmaps_theme_webp_attachment_files( $file_rel, $meta ) {
	$file_rel = ltrim( str_replace( '\\', '/', (string) $file_rel ), '/' );
	$files    = array();
	if ( '' !== $file_rel ) {
		$files[ $file_rel ] = true;
	}

	$dir = ( false !== strpos( $file_rel, '/' ) ) ? trailingslashit( dirname( $file_rel ) ) : '';
	if ( is_array( $meta ) ) {
		if ( ! empty( $meta['file'] ) ) {
			$mf = ltrim( str_replace( '\\', '/', (string) $meta['file'] ), '/' );
			$files[ $mf ] = true;
			$dir = ( false !== strpos( $mf, '/' ) ) ? trailingslashit( dirname( $mf ) ) : $dir;
		}
		if ( ! empty( $meta['original_image'] ) ) {
			$files[ $dir . basename( (string) $meta['original_image'] ) ] = true;
		}
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$files[ $dir . basename( (string) $size['file'] ) ] = true;
				}
			}
		}
	}

	$out = array();
	foreach ( array_keys( $files ) as $rel ) {
		if ( preg_match( '/\.(jpe?g|png)$/i', $rel ) ) {
			$out[] = $rel;
		}
	}
	return $out;
}

/* ---------------------------------------------------------------
 * Counting / selection
 * ---------------------------------------------------------------*/

/** IDs of attachments still to convert (jpg/png MIME, not flagged failed), oldest first. */
function rmaps_theme_webp_pending_ids( $limit ) {
	global $wpdb;
	$mimes = rmaps_theme_webp_target_mimes();
	$ph    = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );
	$args  = array_merge( $mimes, array( RMAPS_THEME_WEBP_FAILED_KEY, (int) $limit ) );
	$sql   = "SELECT p.ID FROM {$wpdb->posts} p
		WHERE p.post_type = 'attachment' AND p.post_mime_type IN ($ph)
		AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} f WHERE f.post_id = p.ID AND f.meta_key = %s )
		ORDER BY p.ID ASC LIMIT %d";
	return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) );
}

/** How many attachments are still jpg/png and not flagged as failed. */
function rmaps_theme_webp_pending_count() {
	global $wpdb;
	$mimes = rmaps_theme_webp_target_mimes();
	$ph    = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );
	$args  = array_merge( $mimes, array( RMAPS_THEME_WEBP_FAILED_KEY ) );
	$sql   = "SELECT COUNT(*) FROM {$wpdb->posts} p
		WHERE p.post_type = 'attachment' AND p.post_mime_type IN ($ph)
		AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} f WHERE f.post_id = p.ID AND f.meta_key = %s )";
	return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
}

/** How many attachments were skipped as unreadable/broken. */
function rmaps_theme_webp_failed_count() {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
		RMAPS_THEME_WEBP_FAILED_KEY
	) );
}

/** How many attachments carry a restore backup (i.e. are revertible). */
function rmaps_theme_webp_backup_count() {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
		RMAPS_THEME_WEBP_BACKUP_KEY
	) );
}

/* ---------------------------------------------------------------
 * Estimate
 * ---------------------------------------------------------------*/

/**
 * Sum the on-disk bytes of every pending attachment's jpg/png files and
 * project the post-conversion size from measured ratios (q82: jpg≈0.527,
 * png≈0.203). On-demand only (button).
 *
 * Memory-light by design: ONE JOIN query pulls each attachment's file +
 * serialized metadata (no object-cache priming, which would load every post
 * + all meta and exhaust memory on large libraries), then a single query
 * fetches titles only for the displayed rows. Two queries total.
 */
function rmaps_theme_webp_estimate() {
	global $wpdb;
	$up      = wp_get_upload_dir();
	$basedir = trailingslashit( str_replace( '\\', '/', (string) $up['basedir'] ) );
	$mimes   = rmaps_theme_webp_target_mimes();
	$ph      = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );

	$sql = "SELECT p.ID AS id, fm.meta_value AS file, mm.meta_value AS metadata
		FROM {$wpdb->posts} p
		LEFT JOIN {$wpdb->postmeta} fm ON fm.post_id = p.ID AND fm.meta_key = '_wp_attached_file'
		LEFT JOIN {$wpdb->postmeta} mm ON mm.post_id = p.ID AND mm.meta_key = '_wp_attachment_metadata'
		WHERE p.post_type = 'attachment' AND p.post_mime_type IN ($ph)";
	$results = $wpdb->get_results( $wpdb->prepare( $sql, $mimes ) );

	$bytes_jpg = 0;
	$bytes_png = 0;
	$nfiles    = 0;
	$count     = 0;
	$rows      = array();

	foreach ( (array) $results as $r ) {
		$count++;
		$file_rel = ltrim( str_replace( '\\', '/', (string) $r->file ), '/' );
		if ( '' === $file_rel ) {
			continue;
		}
		$meta   = ! empty( $r->metadata ) ? maybe_unserialize( $r->metadata ) : '';
		$rels   = rmaps_theme_webp_attachment_files( $file_rel, $meta );
		$abytes = 0;
		$acount = 0;
		foreach ( $rels as $rel ) {
			$full = $basedir . $rel;
			if ( ! is_file( $full ) ) {
				continue;
			}
			$sz = (int) @filesize( $full );
			$acount++;
			$nfiles++;
			if ( preg_match( '/\.png$/i', $rel ) ) {
				$bytes_png += $sz;
			} else {
				$bytes_jpg += $sz;
			}
			$abytes += $sz;
		}
		if ( $acount > 0 ) {
			$rows[] = array( 'id' => (int) $r->id, 'bytes' => $abytes, 'nfiles' => $acount );
		}
	}
	unset( $results );

	usort( $rows, static function ( $a, $b ) {
		return $b['bytes'] <=> $a['bytes'];
	} );
	$nrows = count( $rows );
	$rows  = array_slice( $rows, 0, RMAPS_THEME_WEBP_MAX_ROWS );

	// Titles for the displayed rows only (one query).
	if ( $rows ) {
		$ids    = wp_list_pluck( $rows, 'id' );
		$tph    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$titles = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ($tph)",
			$ids
		), OBJECT_K );
		foreach ( $rows as $k => $row ) {
			$rows[ $k ]['title'] = isset( $titles[ $row['id'] ] ) ? $titles[ $row['id'] ]->post_title : '#' . $row['id'];
		}
	}

	$total = $bytes_jpg + $bytes_png;
	$proj  = (int) round( $bytes_jpg * 0.527 + $bytes_png * 0.203 );

	return array(
		'count'       => $count,
		'nfiles'      => $nfiles,
		'bytes_jpg'   => $bytes_jpg,
		'bytes_png'   => $bytes_png,
		'bytes_total' => $total,
		'proj'        => $proj,
		'nrows'       => $nrows,
		'rows'        => $rows,
	);
}

/* ---------------------------------------------------------------
 * Conversion
 * ---------------------------------------------------------------*/

/**
 * Encode one image file to WebP at $dst.
 *
 * @return true|WP_Error
 */
function rmaps_theme_webp_convert_file( $abs_src, $abs_dst, $quality ) {
	$editor = wp_get_image_editor( $abs_src );
	if ( is_wp_error( $editor ) ) {
		return $editor;
	}
	if ( $quality > 0 ) {
		$editor->set_quality( (int) $quality );
	}
	$saved = $editor->save( $abs_dst, 'image/webp' );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	return true;
}

/**
 * Convert a single attachment to WebP, move its originals to quarantine, and
 * rewrite its DB pointers (with a restore backup). Does NOT touch post_content
 * — the caller batches that via the returned URL map.
 *
 * @return array{ok:bool,error?:string,bytes_before?:int,bytes_after?:int,files?:int,map?:array<string,string>}
 */
function rmaps_theme_webp_convert_attachment( $att_id, $quality ) {
	global $wpdb;
	$att_id = (int) $att_id;

	$up       = wp_get_upload_dir();
	$basedir  = trailingslashit( str_replace( '\\', '/', (string) $up['basedir'] ) );
	$baseurl  = rtrim( (string) $up['baseurl'], '/' );
	$basepath = (string) parse_url( $baseurl, PHP_URL_PATH );
	$qbase    = $basedir . RMAPS_THEME_WEBP_QUARANTINE . '/';

	$file_rel_old = ltrim( str_replace( '\\', '/', (string) get_post_meta( $att_id, '_wp_attached_file', true ) ), '/' );
	if ( '' === $file_rel_old ) {
		return array( 'ok' => false, 'error' => 'no _wp_attached_file' );
	}
	$meta_old = wp_get_attachment_metadata( $att_id );
	$mime_old = (string) get_post_field( 'post_mime_type', $att_id );
	$guid_old = (string) get_post_field( 'guid', $att_id );

	$rels = rmaps_theme_webp_attachment_files( $file_rel_old, $meta_old );
	if ( empty( $rels ) ) {
		return array( 'ok' => false, 'error' => 'no jpg/png files on disk record' );
	}

	// The "main" image: prefer the metadata 'file' (the -scaled source WP serves
	// from), fall back to _wp_attached_file; require whichever actually exists on
	// disk. A precise message lets the operator verify a reported path by hand.
	$main_candidates = array();
	if ( is_array( $meta_old ) && ! empty( $meta_old['file'] ) ) {
		$main_candidates[] = ltrim( str_replace( '\\', '/', (string) $meta_old['file'] ), '/' );
	}
	$main_candidates[] = $file_rel_old;
	$main_rel = '';
	foreach ( $main_candidates as $cand ) {
		if ( is_file( $basedir . $cand ) ) {
			$main_rel = $cand;
			break;
		}
	}
	if ( '' === $main_rel ) {
		return array( 'ok' => false, 'error' => 'main file missing on disk: ' . $file_rel_old );
	}

	// 1) Encode every file to .webp next to the original. Main file is mandatory.
	$bytes_before = 0;
	$bytes_after  = 0;
	$converted    = array();
	$main_ok      = false;
	foreach ( $rels as $rel ) {
		$src = $basedir . $rel;
		if ( ! is_file( $src ) ) {
			continue;
		}
		$webp_rel = rmaps_theme_webp_swap_ext( $rel );
		$dst      = $basedir . $webp_rel;
		$before   = (int) @filesize( $src );
		$res      = rmaps_theme_webp_convert_file( $src, $dst, $quality );
		if ( is_wp_error( $res ) || ! is_file( $dst ) ) {
			if ( $rel === $main_rel ) {
				return array( 'ok' => false, 'error' => 'encode failed: ' . ( is_wp_error( $res ) ? $res->get_error_message() : 'no output' ) );
			}
			continue; // a thumbnail failed — skip it, keep going
		}
		$bytes_before += $before;
		$bytes_after  += (int) @filesize( $dst );
		$converted[]   = $rel;
		if ( $rel === $main_rel ) {
			$main_ok = true;
		}
	}
	if ( ! $main_ok ) {
		return array( 'ok' => false, 'error' => 'main image not converted' );
	}

	// 2) Rewrite the attachment to point at the .webp files.
	$meta_new = is_array( $meta_old ) ? $meta_old : array();
	if ( ! empty( $meta_new['file'] ) ) {
		$meta_new['file'] = rmaps_theme_webp_swap_ext( ltrim( str_replace( '\\', '/', (string) $meta_new['file'] ), '/' ) );
	} else {
		$meta_new['file'] = rmaps_theme_webp_swap_ext( $file_rel_old );
	}
	if ( ! empty( $meta_new['original_image'] ) ) {
		$meta_new['original_image'] = rmaps_theme_webp_swap_ext( basename( (string) $meta_new['original_image'] ) );
	}
	if ( ! empty( $meta_new['sizes'] ) && is_array( $meta_new['sizes'] ) ) {
		foreach ( $meta_new['sizes'] as $k => $size ) {
			if ( ! empty( $size['file'] ) ) {
				$meta_new['sizes'][ $k ]['file'] = rmaps_theme_webp_swap_ext( basename( (string) $size['file'] ) );
			}
			$meta_new['sizes'][ $k ]['mime-type'] = 'image/webp';
		}
	}

	// Backup the old pointers first so Restore can always undo.
	update_post_meta( $att_id, RMAPS_THEME_WEBP_BACKUP_KEY, array(
		'file' => $file_rel_old,
		'meta' => $meta_old,
		'mime' => $mime_old,
		'guid' => $guid_old,
		'q'    => (int) $quality,
	) );
	update_post_meta( $att_id, '_wp_attached_file', rmaps_theme_webp_swap_ext( $file_rel_old ) );
	wp_update_attachment_metadata( $att_id, $meta_new );

	$guid_new = preg_match( '/\.(jpe?g|png)$/i', $guid_old ) ? rmaps_theme_webp_swap_ext( $guid_old ) : $guid_old;
	$wpdb->update(
		$wpdb->posts,
		array( 'post_mime_type' => 'image/webp', 'guid' => $guid_new ),
		array( 'ID' => $att_id )
	);
	clean_post_cache( $att_id );

	// 3) Move originals to quarantine + build the old->new URL map.
	$map = array();
	foreach ( $converted as $rel ) {
		$src = $basedir . $rel;
		$dst = $qbase . $rel;
		if ( is_file( $src ) ) {
			wp_mkdir_p( dirname( $dst ) );
			@rename( $src, $dst );
		}
		$map += rmaps_theme_webp_url_pairs( $baseurl, $basepath, $rel );
	}

	return array(
		'ok'           => true,
		'bytes_before' => $bytes_before,
		'bytes_after'  => $bytes_after,
		'files'        => count( $converted ),
		'map'          => $map,
	);
}

/**
 * Replace upload image URLs across post_content. $map is old=>new (convert)
 * or new=>old (restore). Returns the number of posts edited.
 */
function rmaps_theme_webp_rewrite_content( $map ) {
	if ( empty( $map ) ) {
		return 0;
	}
	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT ID, post_content FROM {$wpdb->posts} WHERE post_content LIKE '%/uploads/%' AND post_content <> ''"
	);
	$edited = 0;
	foreach ( (array) $rows as $row ) {
		$content = (string) $row->post_content;
		$new     = strtr( $content, $map );
		if ( $new !== $content ) {
			$wpdb->update( $wpdb->posts, array( 'post_content' => $new ), array( 'ID' => (int) $row->ID ) );
			clean_post_cache( (int) $row->ID );
			$edited++;
		}
	}
	return $edited;
}

/**
 * Convert one batch (bounded by count AND wall-clock), then do a single
 * content-URL rewrite for the whole batch.
 */
function rmaps_theme_webp_convert_batch( $quality ) {
	$ids   = rmaps_theme_webp_pending_ids( RMAPS_THEME_WEBP_BATCH );
	$start = microtime( true );

	$processed = 0;
	$ok        = 0;
	$failed    = 0;
	$bb        = 0;
	$ba        = 0;
	$errors    = array();
	$map       = array();

	foreach ( $ids as $id ) {
		$r = rmaps_theme_webp_convert_attachment( $id, $quality );
		$processed++;
		if ( ! empty( $r['ok'] ) ) {
			$ok++;
			$bb += (int) $r['bytes_before'];
			$ba += (int) $r['bytes_after'];
			$map += (array) $r['map'];
		} else {
			$failed++;
			$msg = isset( $r['error'] ) ? $r['error'] : 'unknown';
			// Flag so the queue skips this broken/unreadable file next time
			// instead of re-selecting it (it stays jpg/png MIME on failure).
			update_post_meta( $id, RMAPS_THEME_WEBP_FAILED_KEY, $msg );
			if ( count( $errors ) < 20 ) {
				$errors[] = '#' . $id . ': ' . $msg;
			}
		}
		if ( ( microtime( true ) - $start ) > RMAPS_THEME_WEBP_TIME_BUDGET ) {
			break;
		}
	}

	$content_edited = rmaps_theme_webp_rewrite_content( $map );

	return array(
		'processed'      => $processed,
		'ok'             => $ok,
		'failed'         => $failed,
		'bytes_before'   => $bb,
		'bytes_after'    => $ba,
		'errors'         => $errors,
		'content_edited' => $content_edited,
		'remaining'      => rmaps_theme_webp_pending_count(),
	);
}

/* ---------------------------------------------------------------
 * Restore
 * ---------------------------------------------------------------*/

/**
 * Revert one batch of converted attachments: move originals back, delete the
 * generated webp files, restore the DB pointers, and swap content URLs back.
 */
function rmaps_theme_webp_restore_batch() {
	global $wpdb;
	$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
		"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id ASC LIMIT %d",
		RMAPS_THEME_WEBP_BACKUP_KEY,
		RMAPS_THEME_WEBP_BATCH
	) ) );

	$up       = wp_get_upload_dir();
	$basedir  = trailingslashit( str_replace( '\\', '/', (string) $up['basedir'] ) );
	$baseurl  = rtrim( (string) $up['baseurl'], '/' );
	$basepath = (string) parse_url( $baseurl, PHP_URL_PATH );
	$qbase    = $basedir . RMAPS_THEME_WEBP_QUARANTINE . '/';

	$start    = microtime( true );
	$restored = 0;
	$map      = array();

	foreach ( $ids as $id ) {
		$bak = get_post_meta( $id, RMAPS_THEME_WEBP_BACKUP_KEY, true );
		if ( ! is_array( $bak ) || empty( $bak['file'] ) ) {
			delete_post_meta( $id, RMAPS_THEME_WEBP_BACKUP_KEY );
			continue;
		}
		$file_rel_old = ltrim( str_replace( '\\', '/', (string) $bak['file'] ), '/' );
		$meta_old     = isset( $bak['meta'] ) ? $bak['meta'] : '';
		$mime_old     = ! empty( $bak['mime'] ) ? (string) $bak['mime'] : 'image/jpeg';
		$guid_old     = isset( $bak['guid'] ) ? (string) $bak['guid'] : '';

		foreach ( rmaps_theme_webp_attachment_files( $file_rel_old, $meta_old ) as $rel ) {
			$webp_rel = rmaps_theme_webp_swap_ext( $rel );
			$qsrc     = $qbase . $rel;
			$orig     = $basedir . $rel;
			if ( is_file( $qsrc ) ) {
				wp_mkdir_p( dirname( $orig ) );
				@rename( $qsrc, $orig );
			}
			$webp_abs = $basedir . $webp_rel;
			if ( is_file( $webp_abs ) ) {
				@unlink( $webp_abs );
			}
			$map += array_flip( rmaps_theme_webp_url_pairs( $baseurl, $basepath, $rel ) );
		}

		update_post_meta( $id, '_wp_attached_file', $file_rel_old );
		if ( is_array( $meta_old ) ) {
			wp_update_attachment_metadata( $id, $meta_old );
		}
		$wpdb->update(
			$wpdb->posts,
			array( 'post_mime_type' => $mime_old, 'guid' => $guid_old ),
			array( 'ID' => $id )
		);
		clean_post_cache( $id );
		delete_post_meta( $id, RMAPS_THEME_WEBP_BACKUP_KEY );
		$restored++;

		if ( ( microtime( true ) - $start ) > RMAPS_THEME_WEBP_TIME_BUDGET ) {
			break;
		}
	}

	$content_edited = rmaps_theme_webp_rewrite_content( $map );

	return array(
		'restored'       => $restored,
		'content_edited' => $content_edited,
		'remaining'      => rmaps_theme_webp_backup_count(),
	);
}

/* ---------------------------------------------------------------
 * Quarantine (uploads/_webp_orig/)
 * ---------------------------------------------------------------*/

function rmaps_theme_webp_quarantine_info() {
	$up    = wp_get_upload_dir();
	$base  = trailingslashit( str_replace( '\\', '/', (string) $up['basedir'] ) ) . RMAPS_THEME_WEBP_QUARANTINE;
	$count = 0;
	$bytes = 0;
	if ( is_dir( $base ) ) {
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
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
 * Finalize: permanently delete the quarantined originals AND drop the restore
 * backups (after this, conversion is no longer revertible). Reclaims disk.
 */
function rmaps_theme_webp_finalize() {
	$up   = wp_get_upload_dir();
	$base = trailingslashit( str_replace( '\\', '/', (string) $up['basedir'] ) ) . RMAPS_THEME_WEBP_QUARANTINE;

	$deleted = 0;
	$bytes   = 0;
	if ( is_dir( $base ) ) {
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
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
		@rmdir( $base );
	}

	global $wpdb;
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => RMAPS_THEME_WEBP_BACKUP_KEY ) );

	return array( 'deleted' => $deleted, 'bytes' => $bytes );
}

/* ---------------------------------------------------------------
 * Admin page (Tools → WebP Convert)
 * ---------------------------------------------------------------*/
add_action( 'admin_menu', function () {
	add_management_page(
		__( 'WebP Convert', 'rmaps-theme' ),
		__( 'WebP Convert', 'rmaps-theme' ),
		'manage_options',
		'rmaps-webp-convert',
		'rmaps_theme_webp_render_page'
	);
} );

function rmaps_theme_webp_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'rmaps-theme' ) );
	}

	$notice    = '';
	$ntype     = 'success';
	$estimate  = null;
	$auto_more = false; // emit the auto-continue script after a batch
	$auto_kind = '';    // 'convert' | 'restore'
	$quality   = rmaps_theme_webp_default_quality();

	if ( isset( $_POST['rmaps_webp_action'] ) ) {
		check_admin_referer( 'rmaps_webp' );
		$action = sanitize_key( wp_unslash( $_POST['rmaps_webp_action'] ) );
		if ( isset( $_POST['rmaps_webp_quality'] ) ) {
			$q = (int) $_POST['rmaps_webp_quality'];
			if ( $q >= 40 && $q <= 100 ) {
				$quality = $q;
			}
		}

		if ( ! rmaps_theme_webp_supported() && in_array( $action, array( 'convert' ), true ) ) {
			$notice = __( 'This server\'s image editor cannot output WebP (GD/Imagick WebP support missing). Conversion is unavailable.', 'rmaps-theme' );
			$ntype  = 'error';
		} elseif ( 'estimate' === $action ) {
			$estimate = rmaps_theme_webp_estimate();
		} elseif ( 'convert' === $action ) {
			$r = rmaps_theme_webp_convert_batch( $quality );
			$notice = sprintf(
				/* translators: 1: converted count, 2: skipped count, 3: saved size, 4: remaining */
				__( 'Converted %1$d attachment(s) this batch (%2$d skipped as unreadable). Saved %3$s in this batch. %4$d still pending.', 'rmaps-theme' ),
				$r['ok'],
				$r['failed'],
				size_format( max( 0, $r['bytes_before'] - $r['bytes_after'] ) ),
				$r['remaining']
			);
			if ( $r['content_edited'] > 0 ) {
				$notice .= ' ' . sprintf( __( 'Updated %d post(s) with embedded image URLs.', 'rmaps-theme' ), $r['content_edited'] );
			}
			if ( ! empty( $r['errors'] ) ) {
				$ntype   = 'warning';
				$notice .= ' — ' . implode( '; ', array_map( 'esc_html', $r['errors'] ) );
			}
			// Continue on ANY progress: skipped records get flagged and leave the
			// queue too, so processed>0 always means the remaining count dropped.
			$auto_more = $r['remaining'] > 0 && $r['processed'] > 0;
			$auto_kind = 'convert';
		} elseif ( 'restore' === $action ) {
			$r = rmaps_theme_webp_restore_batch();
			$notice = sprintf(
				/* translators: 1: restored count, 2: remaining */
				__( 'Restored %1$d attachment(s) this batch. %2$d still to restore.', 'rmaps-theme' ),
				$r['restored'],
				$r['remaining']
			);
			$auto_more = $r['remaining'] > 0 && $r['restored'] > 0;
			$auto_kind = 'restore';
		} elseif ( 'finalize' === $action ) {
			$r = rmaps_theme_webp_finalize();
			$notice = sprintf(
				/* translators: 1: deleted count, 2: size */
				__( 'Finalized: permanently deleted %1$d quarantined original(s) (%2$s reclaimed). Conversion is no longer revertible.', 'rmaps-theme' ),
				$r['deleted'],
				size_format( $r['bytes'] )
			);
		} elseif ( 'retry_failed' === $action ) {
			global $wpdb;
			$n = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", RMAPS_THEME_WEBP_FAILED_KEY ) );
			$notice = sprintf(
				/* translators: %d: count */
				__( 'Cleared %d skip marker(s) — those attachments re-enter the queue on the next conversion.', 'rmaps-theme' ),
				$n
			);
		}
	}

	$supported = rmaps_theme_webp_supported();
	$pending   = rmaps_theme_webp_pending_count();
	$backups   = rmaps_theme_webp_backup_count();
	$failed    = rmaps_theme_webp_failed_count();
	$quar      = rmaps_theme_webp_quarantine_info();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'WebP Convert', 'rmaps-theme' ); ?></h1>

		<?php if ( '' !== $notice ) : ?>
			<div class="notice notice-<?php echo esc_attr( $ntype ); ?> is-dismissible"><p><?php echo wp_kses_post( $notice ); ?></p></div>
		<?php endif; ?>

		<?php if ( ! $supported ) : ?>
			<div class="notice notice-error"><p><?php esc_html_e( 'The server image editor (GD/Imagick) does not support WebP output. Enable WebP in PHP-GD/Imagick before converting.', 'rmaps-theme' ); ?></p></div>
		<?php endif; ?>

		<p><?php esc_html_e( 'Converts JPEG/PNG Media Library images to WebP in place (original, -scaled source, and every thumbnail size) and repoints WordPress at the .webp files. Listing images follow automatically because they are referenced by attachment ID. The original files are moved to a quarantine folder (uploads/_webp_orig/), not deleted — disk space is only reclaimed when you Finalize.', 'rmaps-theme' ); ?></p>

		<p>
			<?php
			printf(
				/* translators: 1: pending, 2: revertible backups, 3: skipped */
				esc_html__( 'Pending (jpg/png): %1$s. Converted & revertible: %2$s. Skipped (unreadable): %3$s.', 'rmaps-theme' ),
				'<strong>' . esc_html( number_format_i18n( $pending ) ) . '</strong>',
				'<strong>' . esc_html( number_format_i18n( $backups ) ) . '</strong>',
				'<strong>' . esc_html( number_format_i18n( $failed ) ) . '</strong>'
			);
			?>
			<?php if ( $failed > 0 ) : ?>
				<form method="post" style="display:inline">
					<?php wp_nonce_field( 'rmaps_webp' ); ?>
					<input type="hidden" name="rmaps_webp_action" value="retry_failed">
					<button type="submit" class="button button-small"><?php esc_html_e( 'Retry skipped', 'rmaps-theme' ); ?></button>
				</form>
			<?php endif; ?>
		</p>

		<hr>
		<h2><?php esc_html_e( '1. Estimate', 'rmaps-theme' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'rmaps_webp' ); ?>
			<input type="hidden" name="rmaps_webp_action" value="estimate">
			<p><button type="submit" class="button"><?php esc_html_e( 'Scan & estimate savings', 'rmaps-theme' ); ?></button>
			<span class="description"><?php esc_html_e( 'Reads every pending image on disk — may take a few seconds.', 'rmaps-theme' ); ?></span></p>
		</form>

		<?php if ( is_array( $estimate ) ) : ?>
			<p>
				<?php
				printf(
					/* translators: 1: attachments, 2: files, 3: current size, 4: projected size, 5: saved, 6: percent */
					esc_html__( '%1$s attachment(s), %2$s files, %3$s now → ~%4$s as WebP (saves ~%5$s, −%6$s%%).', 'rmaps-theme' ),
					'<strong>' . esc_html( number_format_i18n( $estimate['count'] ) ) . '</strong>',
					'<strong>' . esc_html( number_format_i18n( $estimate['nfiles'] ) ) . '</strong>',
					'<strong>' . esc_html( size_format( $estimate['bytes_total'] ) ) . '</strong>',
					'<strong>' . esc_html( size_format( $estimate['proj'] ) ) . '</strong>',
					'<strong>' . esc_html( size_format( max( 0, $estimate['bytes_total'] - $estimate['proj'] ) ) ) . '</strong>',
					esc_html( $estimate['bytes_total'] > 0 ? round( 100 * ( $estimate['bytes_total'] - $estimate['proj'] ) / $estimate['bytes_total'] ) : 0 )
				);
				?>
			</p>
			<?php if ( $estimate['count'] > $estimate['nrows'] ) : ?>
				<p class="description"><strong><?php
					printf(
						/* translators: %s: count of attachment records with no file on disk */
						esc_html__( '%s of these attachment records have NO image file on disk — they will be skipped, not converted (broken/imported records).', 'rmaps-theme' ),
						esc_html( number_format_i18n( $estimate['count'] - $estimate['nrows'] ) )
					);
				?></strong></p>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'Projection uses measured ratios (q82: JPEG −47%, PNG −80%). Actual result is reported per batch as you convert.', 'rmaps-theme' ); ?></p>
			<?php if ( ! empty( $estimate['rows'] ) ) : ?>
				<table class="widefat striped" style="max-width:900px">
					<thead><tr>
						<th><?php esc_html_e( 'Attachment', 'rmaps-theme' ); ?></th>
						<th style="width:90px"><?php esc_html_e( 'Files', 'rmaps-theme' ); ?></th>
						<th style="width:120px"><?php esc_html_e( 'Size', 'rmaps-theme' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $estimate['rows'] as $row ) : ?>
						<tr>
							<td>#<?php echo (int) $row['id']; ?> — <?php echo esc_html( $row['title'] ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $row['nfiles'] ) ); ?></td>
							<td><?php echo esc_html( size_format( $row['bytes'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( $estimate['nrows'] > count( $estimate['rows'] ) ) : ?>
					<p class="description"><?php
						printf(
							esc_html__( 'Showing the %1$s largest of %2$s.', 'rmaps-theme' ),
							esc_html( number_format_i18n( count( $estimate['rows'] ) ) ),
							esc_html( number_format_i18n( $estimate['nrows'] ) )
						);
					?></p>
				<?php endif; ?>
			<?php endif; ?>
		<?php endif; ?>

		<hr>
		<h2><?php esc_html_e( '2. Convert', 'rmaps-theme' ); ?></h2>
		<?php if ( $pending > 0 ) : ?>
			<form method="post" id="rmaps-webp-convert" onsubmit="return (this.dataset.auto==='1')||confirm('<?php echo esc_js( __( 'Convert JPEG/PNG images to WebP? Originals are moved to quarantine (reversible until you Finalize).', 'rmaps-theme' ) ); ?>');">
				<?php wp_nonce_field( 'rmaps_webp' ); ?>
				<input type="hidden" name="rmaps_webp_action" value="convert">
				<p>
					<label for="rmaps_webp_quality"><?php esc_html_e( 'Quality:', 'rmaps-theme' ); ?></label>
					<select name="rmaps_webp_quality" id="rmaps_webp_quality">
						<option value="82" <?php selected( $quality, 82 ); ?>><?php esc_html_e( '82 — default, visually lossless', 'rmaps-theme' ); ?></option>
						<option value="80" <?php selected( $quality, 80 ); ?>>80</option>
						<option value="75" <?php selected( $quality, 75 ); ?>><?php esc_html_e( '75 — smaller, still good', 'rmaps-theme' ); ?></option>
					</select>
					<button type="submit" class="button button-primary" <?php disabled( ! $supported ); ?>><?php esc_html_e( 'Start / continue conversion', 'rmaps-theme' ); ?></button>
					<span class="description"><?php
						printf(
							esc_html__( 'Processes ~%d at a time, then auto-continues until done.', 'rmaps-theme' ),
							(int) RMAPS_THEME_WEBP_BATCH
						);
					?></span>
				</p>
			</form>
		<?php else : ?>
			<p><em><?php esc_html_e( 'No JPEG/PNG attachments pending — all converted.', 'rmaps-theme' ); ?></em></p>
		<?php endif; ?>

		<?php if ( $auto_more && 'convert' === $auto_kind ) : ?>
			<p id="rmaps-webp-progress" class="description"><?php esc_html_e( 'Auto-continuing…', 'rmaps-theme' ); ?>
				<button type="button" class="button button-small" onclick="window.__rmapsWebpStop=1;document.getElementById('rmaps-webp-progress').lastChild.replaceWith(' — stopped.');"><?php esc_html_e( 'Stop', 'rmaps-theme' ); ?></button>
				<span></span>
			</p>
			<script>
			window.__rmapsWebpStop = 0;
			setTimeout(function () {
				if ( window.__rmapsWebpStop ) { return; }
				var f = document.getElementById('rmaps-webp-convert');
				if ( f ) { f.dataset.auto = '1'; f.submit(); }
			}, 600);
			</script>
		<?php endif; ?>

		<hr>
		<h2><?php esc_html_e( '3. Finalize or restore', 'rmaps-theme' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: 1: quarantine count, 2: size */
				esc_html__( 'Quarantine (uploads/_webp_orig/): %1$s original file(s), %2$s. These are the replaced JPEG/PNG files, kept so conversion can be reverted.', 'rmaps-theme' ),
				'<strong>' . esc_html( number_format_i18n( $quar['count'] ) ) . '</strong>',
				'<strong>' . esc_html( size_format( $quar['bytes'] ) ) . '</strong>'
			);
			?>
		</p>

		<?php if ( $backups > 0 ) : ?>
			<form method="post" id="rmaps-webp-restore" style="display:inline-block;margin-right:1em" onsubmit="return (this.dataset.auto==='1')||confirm('<?php echo esc_js( __( 'Revert all converted images back to the originals? This restores the jpg/png files and DB pointers.', 'rmaps-theme' ) ); ?>');">
				<?php wp_nonce_field( 'rmaps_webp' ); ?>
				<input type="hidden" name="rmaps_webp_action" value="restore">
				<button type="submit" class="button"><?php esc_html_e( 'Restore originals (revert)', 'rmaps-theme' ); ?></button>
			</form>
		<?php endif; ?>

		<?php if ( $quar['count'] > 0 ) : ?>
			<form method="post" style="display:inline-block" onsubmit="return confirm('<?php echo esc_js( __( 'Permanently delete the quarantined originals and reclaim the disk space? Conversion can no longer be reverted after this.', 'rmaps-theme' ) ); ?>');">
				<?php wp_nonce_field( 'rmaps_webp' ); ?>
				<input type="hidden" name="rmaps_webp_action" value="finalize">
				<button type="submit" class="button button-secondary"><?php esc_html_e( 'Finalize — delete originals (reclaim disk)', 'rmaps-theme' ); ?></button>
			</form>
		<?php endif; ?>

		<?php if ( $auto_more && 'restore' === $auto_kind ) : ?>
			<p id="rmaps-webp-progress2" class="description"><?php esc_html_e( 'Auto-continuing restore…', 'rmaps-theme' ); ?>
				<button type="button" class="button button-small" onclick="window.__rmapsWebpStop=1;document.getElementById('rmaps-webp-progress2').lastChild.replaceWith(' — stopped.');"><?php esc_html_e( 'Stop', 'rmaps-theme' ); ?></button>
				<span></span>
			</p>
			<script>
			window.__rmapsWebpStop = 0;
			setTimeout(function () {
				if ( window.__rmapsWebpStop ) { return; }
				var f = document.getElementById('rmaps-webp-restore');
				if ( f ) { f.dataset.auto = '1'; f.submit(); }
			}, 600);
			</script>
		<?php endif; ?>
	</div>
	<?php
}
