<?php
/**
 * Duplicate media — Tools → Duplicate Media.
 *
 * Finds the same image uploaded more than once (IMPACT / IMPACT1 / IMPACT2 …,
 * foo_1024x768 / foo_1024x7681 …) and removes the extras, re-pointing references
 * to one kept canonical copy.
 *
 * The work lives in a persistent index table ({prefix}rmaps_dupe_index), so a
 * scan that was interrupted is NOT redone - the page just shows whatever is
 * already there. Nothing resets unless you press "Rescan from scratch".
 *
 *   Scan (build/continue the index, never auto-reset):
 *     index  attachment-direct query; record (id, file, stem, size). size =
 *            width*height from metadata (DB, no filesystem stat).
 *     hash   md5 only files that have a same (stem,size) sibling; unique ones
 *            are skipped (hash='').
 *   Show: groups = GROUP BY hash HAVING COUNT(*)>1 from the existing table.
 *   Remove (Move duplicates to trash): per duplicate -> re-point id-meta to the
 *     canonical, invalidate the affected listings' marker cache, move the dup
 *     files to a reversible trash folder, delete the dup attachment.
 *
 * Driven by plain form submits (the only request shape this host doesn't block);
 * every step is small and idempotent, and progress lives in the table, so an
 * interrupted run is resumed just by pressing the button again.
 */

defined( 'ABSPATH' ) || exit;

/** Seconds of work per request (this host tolerated ~15s in the WebP tools). */
const RMAPS_THEME_DUPE_BUDGET = 15;
/** Listings scanned per image-repair request. Mostly fast stat()s, so the count
 *  cap (not the time budget) limits throughput - a big number here is safe. */
const RMAPS_THEME_DUPE_REPAIR_BATCH = 600;
/** Cap the preview table; totals are always the full figures. */
const RMAPS_THEME_DUPE_MAX_GROUPS = 200;
/** Reversible trash folder (under uploads) for removed duplicate files. */
const RMAPS_THEME_DUPE_TRASH = '_dedupe_trash';

function rmaps_theme_dupe_table() {
	global $wpdb;
	return $wpdb->prefix . 'rmaps_dupe_index';
}

/** Postmeta keys that reference an attachment by ID (core + rmaps plugin). */
function rmaps_theme_dupe_id_meta_keys() {
	return array( '_thumbnail_id', '_rmaps_logo_image_id', '_rmaps_attached_image' );
}

/** Grouping stem: basename, no extension, trailing digit-run removed. */
function rmaps_theme_dupe_stem( $file ) {
	$base = pathinfo( (string) $file, PATHINFO_FILENAME );
	return preg_replace( '/\d+$/', '', $base );
}

/** Keep the dedupe trash out of the Media Cleanup orphan scan. */
add_filter( 'rmaps_theme_cleanup_exclude_prefixes', function ( $prefixes ) {
	$prefixes   = (array) $prefixes;
	$prefixes[] = RMAPS_THEME_DUPE_TRASH . '/';
	return $prefixes;
} );

/** CREATE TABLE IF NOT EXISTS (does NOT drop - progress is preserved). */
function rmaps_theme_dupe_ensure_table() {
	global $wpdb;
	$t = rmaps_theme_dupe_table();
	$charset = $wpdb->get_charset_collate();
	$wpdb->query(
		"CREATE TABLE IF NOT EXISTS {$t} (
			att_id BIGINT UNSIGNED NOT NULL,
			file VARCHAR(255) NOT NULL,
			stem VARCHAR(255) NOT NULL,
			sz BIGINT NOT NULL,
			hash CHAR(32) NULL DEFAULT NULL,
			PRIMARY KEY (att_id),
			KEY stem_sz (stem(120), sz),
			KEY hash (hash)
		) {$charset}"
	);
}

/** Drop + recreate empty (only on an explicit "Rescan from scratch"). */
function rmaps_theme_dupe_reset_table() {
	global $wpdb;
	$t = rmaps_theme_dupe_table();
	$wpdb->query( "DROP TABLE IF EXISTS {$t}" );
	rmaps_theme_dupe_ensure_table();
}

/** True when the index table exists. */
function rmaps_theme_dupe_table_exists() {
	global $wpdb;
	$t = rmaps_theme_dupe_table();
	return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
}

/** Rows indexed and rows still needing a hash (for the status line / controls). */
function rmaps_theme_dupe_progress() {
	global $wpdb;
	if ( ! rmaps_theme_dupe_table_exists() ) {
		return array( 'indexed' => 0, 'unhashed' => 0 );
	}
	$t = rmaps_theme_dupe_table();
	return array(
		'indexed'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ),
		'unhashed' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE hash IS NULL" ),
	);
}

/**
 * Phase "index": record (id, file, stem, size) for a chunk of attachments.
 * @return array{more:bool, after:int, count:int}
 */
function rmaps_theme_dupe_index_batch( $after ) {
	global $wpdb;
	if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 120 ); }
	rmaps_theme_dupe_ensure_table();
	$t = rmaps_theme_dupe_table();

	$start = microtime( true );
	$last  = (int) $after;
	$count = 0;
	$more  = true;

	while ( ( microtime( true ) - $start ) < RMAPS_THEME_DUPE_BUDGET ) {
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID AS id, fm.meta_value AS file, mm.meta_value AS meta
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} fm ON fm.post_id = p.ID AND fm.meta_key = '_wp_attached_file'
			 LEFT JOIN {$wpdb->postmeta} mm ON mm.post_id = p.ID AND mm.meta_key = '_wp_attachment_metadata'
			 WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%%' AND p.ID > %d
			 ORDER BY p.ID ASC LIMIT 200",
			$last
		) );
		if ( empty( $rows ) ) { $more = false; break; }

		$values = array();
		$args   = array();
		foreach ( $rows as $r ) {
			$last = (int) $r->id;
			$file = ltrim( str_replace( '\\', '/', (string) $r->file ), '/' );
			if ( '' === $file ) { continue; }
			$m  = $r->meta ? maybe_unserialize( $r->meta ) : null;
			$w  = ( is_array( $m ) && ! empty( $m['width'] ) )  ? (int) $m['width']  : 0;
			$h  = ( is_array( $m ) && ! empty( $m['height'] ) ) ? (int) $m['height'] : 0;
			$sz = $w * $h;
			if ( $sz <= 0 ) { continue; }
			$values[] = '(%d,%s,%s,%d)';
			array_push( $args, (int) $r->id, $file, rmaps_theme_dupe_stem( $file ), $sz );
			$count++;
		}
		if ( ! empty( $values ) ) {
			$sql = "INSERT IGNORE INTO {$t} (att_id, file, stem, sz) VALUES " . implode( ',', $values );
			$wpdb->query( $wpdb->prepare( $sql, $args ) );
		}
		if ( count( $rows ) < 200 ) { $more = false; break; }
	}
	return array( 'more' => $more, 'after' => $last, 'count' => $count );
}

/**
 * Phase "hash": md5 only candidate files (same stem+size sibling), by cursor.
 * @return array{more:bool, after:int, count:int}
 */
function rmaps_theme_dupe_hash_batch( $after ) {
	global $wpdb;
	if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 120 ); }
	$up      = wp_get_upload_dir();
	$basedir = trailingslashit( str_replace( '\\', '/', (string) $up['basedir'] ) );
	$t       = rmaps_theme_dupe_table();

	$start = microtime( true );
	$done  = 0;
	$more  = true;

	while ( ( microtime( true ) - $start ) < RMAPS_THEME_DUPE_BUDGET ) {
		// No att_id cursor - always grab the NEXT rows that are still NULL, so
		// nothing can be skipped. A row processed below becomes a real hash or
		// '' (unique, no sibling) and drops out of the result; the '' rows are
		// DONE, not "un-hashed". The phase is finished only when no NULL is left.
		$rows = $wpdb->get_results(
			"SELECT att_id, file, stem, sz FROM {$t} WHERE hash IS NULL ORDER BY att_id ASC LIMIT 40"
		);
		if ( empty( $rows ) ) { $more = false; break; }

		foreach ( $rows as $r ) {
			if ( ( microtime( true ) - $start ) >= RMAPS_THEME_DUPE_BUDGET ) {
				return array( 'more' => true, 'after' => 0, 'count' => $done );
			}
			$sib = $wpdb->get_var( $wpdb->prepare(
				"SELECT 1 FROM {$t} WHERE stem = %s AND sz = %d AND att_id <> %d LIMIT 1",
				(string) $r->stem, (int) $r->sz, (int) $r->att_id
			) );
			if ( ! $sib ) {
				$wpdb->update( $t, array( 'hash' => '' ), array( 'att_id' => (int) $r->att_id ) );
				continue;
			}
			$h = @md5_file( $basedir . ltrim( (string) $r->file, '/' ) );
			$wpdb->update( $t, array( 'hash' => ( $h ? $h : '' ) ), array( 'att_id' => (int) $r->att_id ) );
			if ( $h ) { $done++; }
		}
	}
	return array( 'more' => $more, 'after' => 0, 'count' => $done );
}

/** Duplicate totals from the existing table. One indexed GROUP BY. */
function rmaps_theme_dupe_totals() {
	global $wpdb;
	if ( ! rmaps_theme_dupe_table_exists() ) { return array( 'groups' => 0, 'dupes' => 0 ); }
	$t = rmaps_theme_dupe_table();
	$row = $wpdb->get_row(
		"SELECT COUNT(*) AS groups, COALESCE(SUM(n - 1),0) AS dupes
		 FROM ( SELECT hash, COUNT(*) AS n FROM {$t}
		        WHERE hash IS NOT NULL AND hash <> '' GROUP BY hash HAVING n > 1 ) g"
	);
	return array(
		'groups' => $row ? (int) $row->groups : 0,
		'dupes'  => $row ? (int) $row->dupes : 0,
	);
}

/** Duplicate groups for the preview (capped). */
function rmaps_theme_dupe_groups( $limit ) {
	global $wpdb;
	if ( ! rmaps_theme_dupe_table_exists() ) { return array(); }
	$t = rmaps_theme_dupe_table();
	$hashes = $wpdb->get_results( $wpdb->prepare(
		"SELECT hash, COUNT(*) AS n FROM {$t}
		 WHERE hash IS NOT NULL AND hash <> '' GROUP BY hash HAVING n > 1
		 ORDER BY n DESC LIMIT %d",
		(int) $limit
	) );
	$out = array();
	foreach ( (array) $hashes as $h ) {
		$members = $wpdb->get_results( $wpdb->prepare(
			"SELECT att_id, file FROM {$t} WHERE hash = %s ORDER BY att_id ASC",
			$h->hash
		) );
		if ( count( (array) $members ) < 2 ) { continue; }
		$canonical = array_shift( $members );
		$dupes     = array();
		foreach ( $members as $m ) {
			$dupes[] = array( 'id' => (int) $m->att_id, 'file' => (string) $m->file );
		}
		$out[] = array(
			'canonical' => array( 'id' => (int) $canonical->att_id, 'file' => (string) $canonical->file ),
			'dupes'     => $dupes,
		);
	}
	return $out;
}

/**
 * Count listing image references that point at an attachment which no longer
 * exists (its record was deleted). Pure SQL - instant, host-safe. This is the
 * exact damage a removal leaves when it deleted an attachment without first
 * re-pointing a reference to it: the listing keeps pointing at a dead id.
 */
function rmaps_theme_dupe_broken_refs_count() {
	global $wpdb;
	$keys = rmaps_theme_dupe_id_meta_keys();
	$kph  = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*)
		 FROM {$wpdb->postmeta} pm
		 LEFT JOIN {$wpdb->posts} a ON a.ID = pm.meta_value AND a.post_type = 'attachment'
		 WHERE pm.meta_key IN ($kph)
		   AND pm.meta_value REGEXP '^[0-9]+$'
		   AND a.ID IS NULL",
		$keys
	) );
}

/**
 * The broken references themselves (capped for display): which listing, which
 * key, and the dead attachment id it still points at. LEFT JOINs the listing
 * post so an orphaned meta row (the listing itself also gone) still shows up and
 * the list always matches rmaps_theme_dupe_broken_refs_count().
 * @return array<object{post_id:int,post_title:string,post_type:string,meta_key:string,att_id:int}>
 */
function rmaps_theme_dupe_broken_refs( $limit = 500 ) {
	global $wpdb;
	$keys = rmaps_theme_dupe_id_meta_keys();
	$kph  = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
	$args = array_merge( $keys, array( (int) $limit ) );
	return (array) $wpdb->get_results( $wpdb->prepare(
		"SELECT pm.post_id, p.post_title, p.post_type, pm.meta_key, pm.meta_value AS att_id
		 FROM {$wpdb->postmeta} pm
		 LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		 LEFT JOIN {$wpdb->posts} a ON a.ID = pm.meta_value AND a.post_type = 'attachment'
		 WHERE pm.meta_key IN ($kph)
		   AND pm.meta_value REGEXP '^[0-9]+$'
		   AND a.ID IS NULL
		 ORDER BY pm.post_id ASC
		 LIMIT %d",
		$args
	) );
}

/* ===============================================================
 *  Repair broken listing images — find every listing image reference
 *  (featured / gallery / logo id-meta + `_rmaps_location_markers` image
 *  URL snapshots) whose target is gone (deleted attachment, or file missing
 *  on disk) and, in apply mode, swap it for a random INTACT attachment so the
 *  front never shows a broken image. A "scan" pass changes nothing.
 * ===============================================================*/

/** Uploads basedir, normalised + trailing-slashed. */
function rmaps_theme_dupe_uploads_base() {
	$up = wp_get_upload_dir();
	return trailingslashit( str_replace( '\\', '/', (string) $up['basedir'] ) );
}

/** A pool of attachment IDs whose primary file really exists on disk. */
function rmaps_theme_dupe_valid_pool() {
	global $wpdb;
	static $pool = null;
	if ( null !== $pool ) { return $pool; }
	$base = rmaps_theme_dupe_uploads_base();
	$rows = (array) $wpdb->get_results(
		"SELECT p.ID, pm.meta_value AS file
		 FROM {$wpdb->posts} p
		 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
		 WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'
		 ORDER BY p.ID DESC LIMIT 400"
	);
	$pool = array();
	foreach ( $rows as $r ) {
		$f = ltrim( str_replace( '\\', '/', (string) $r->file ), '/' );
		if ( '' !== $f && rmaps_theme_dupe_image_usable( $base . $f ) ) { $pool[] = (int) $r->ID; }
		if ( count( $pool ) >= 60 ) { break; }
	}
	return $pool;
}

/**
 * is_file AND the bytes decode as an image. WebP is trusted (the converter wrote
 * those, and getimagesize webp support varies across PHP builds); jpg/png/gif are
 * decode-checked, but only when small — the broken files here are tiny truncated
 * stubs (~680 B that still return HTTP 200), so a size gate keeps the scan fast
 * while still catching them. A big file is assumed intact.
 */
function rmaps_theme_dupe_image_usable( $abs ) {
	if ( ! is_file( $abs ) ) { return false; }
	if ( preg_match( '/\.webp$/i', $abs ) ) { return true; }
	if ( (int) @filesize( $abs ) >= 4096 ) { return true; }
	$info = @getimagesize( $abs );
	return ( is_array( $info ) && ! empty( $info[0] ) && ! empty( $info[1] ) );
}

/** A USABLE image (present AND decodable) exists at $rel or its jpg<->webp twin. */
function rmaps_theme_dupe_file_usable( $rel, $base ) {
	$rel = ltrim( str_replace( '\\', '/', (string) $rel ), '/' );
	if ( '' === $rel ) { return false; }
	if ( rmaps_theme_dupe_image_usable( $base . $rel ) ) { return true; }
	if ( preg_match( '/\.webp$/i', $rel ) ) {
		foreach ( array( '.jpg', '.jpeg', '.png' ) as $e ) {
			if ( rmaps_theme_dupe_image_usable( $base . preg_replace( '/\.webp$/i', $e, $rel ) ) ) { return true; }
		}
	} elseif ( preg_match( '/\.(jpe?g|png)$/i', $rel ) ) {
		if ( rmaps_theme_dupe_image_usable( $base . preg_replace( '/\.(jpe?g|png)$/i', '.webp', $rel ) ) ) { return true; }
	}
	return false;
}

/** An id-meta value points at a usable image (attachment exists AND its file decodes). */
function rmaps_theme_dupe_attachment_present( $att_id, $base ) {
	$att_id = (int) $att_id;
	if ( $att_id <= 0 || 'attachment' !== get_post_type( $att_id ) ) { return false; }
	$f = ltrim( str_replace( '\\', '/', (string) get_post_meta( $att_id, '_wp_attached_file', true ) ), '/' );
	if ( '' === $f ) { return false; }
	// The WebP converter flags attachments whose source it could not read
	// (`_rmaps_webp_failed`, mirrors webp-convert's RMAPS_THEME_WEBP_FAILED_KEY) -
	// i.e. corrupt/unreadable images. Decode-check those in FULL (no size gate, so
	// large corrupt files are caught too); a real failure means broken.
	if ( metadata_exists( 'post', $att_id, '_rmaps_webp_failed' ) ) {
		$abs = $base . $f;
		if ( ! is_file( $abs ) ) { return false; }
		if ( preg_match( '/\.webp$/i', $abs ) ) { return true; }
		$info = @getimagesize( $abs );
		return ( is_array( $info ) && ! empty( $info[0] ) && ! empty( $info[1] ) );
	}
	return rmaps_theme_dupe_file_usable( $f, $base );
}

/** Upload-relative path of an uploads image URL, or '' if it isn't one. */
function rmaps_theme_dupe_url_to_rel( $url ) {
	$s   = preg_replace( '/[?#].*$/', '', str_replace( '\\/', '/', (string) $url ) );
	$pos = stripos( $s, '/uploads/' );
	return ( false !== $pos ) ? ltrim( substr( $s, $pos + 9 ), '/' ) : '';
}

/**
 * One repair batch over rmaps_listing posts (cursor by ID, wall-clock bounded).
 * @param int  $after  ID cursor
 * @param bool $apply  false = scan only (no changes); true = replace
 * @return array{more:bool, after:int, scanned:int, broken:int, fixed:int, nopool:bool}
 */
function rmaps_theme_dupe_repair_images_batch( $after, $apply ) {
	global $wpdb;
	if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 120 ); }
	$base = rmaps_theme_dupe_uploads_base();
	$pool = rmaps_theme_dupe_valid_pool();
	if ( $apply && empty( $pool ) ) {
		return array( 'more' => false, 'after' => 0, 'scanned' => 0, 'broken' => 0, 'fixed' => 0, 'nopool' => true );
	}

	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'rmaps_listing' AND ID > %d ORDER BY ID ASC LIMIT %d",
		(int) $after,
		RMAPS_THEME_DUPE_REPAIR_BATCH
	) );
	if ( empty( $ids ) ) {
		return array( 'more' => false, 'after' => 0, 'scanned' => 0, 'broken' => 0, 'fixed' => 0, 'nopool' => false );
	}

	$keys    = rmaps_theme_dupe_id_meta_keys();
	$start   = microtime( true );
	$scanned = 0;
	$broken  = 0;
	$fixed   = 0;
	$last    = (int) $after;
	$pick    = static function () use ( $pool ) { return $pool[ array_rand( $pool ) ]; };

	foreach ( $ids as $pid ) {
		$pid = (int) $pid;
		$last = $pid;
		$scanned++;

		// id-meta references (featured / logo single, gallery multi).
		foreach ( $keys as $key ) {
			foreach ( (array) get_post_meta( $pid, $key, false ) as $val ) {
				if ( ! is_numeric( $val ) || rmaps_theme_dupe_attachment_present( $val, $base ) ) { continue; }
				$broken++;
				if ( $apply ) {
					update_post_meta( $pid, $key, $pick(), $val );
				}
			}
		}

		// marker image-URL snapshots (`_rmaps_location_markers`).
		$markers = get_post_meta( $pid, '_rmaps_location_markers', true );
		if ( is_array( $markers ) ) {
			$changed = false;
			foreach ( $markers as $rid => $mk ) {
				if ( ! is_array( $mk ) || ! isset( $mk['kind'] ) || 'image' !== $mk['kind'] ) { continue; }
				$rel = rmaps_theme_dupe_url_to_rel( isset( $mk['value'] ) ? $mk['value'] : '' );
				if ( '' === $rel || rmaps_theme_dupe_file_usable( $rel, $base ) ) { continue; }
				$broken++;
				if ( $apply ) {
					$markers[ $rid ]['value'] = (string) wp_get_attachment_url( $pick() );
					$changed = true;
				}
			}
			if ( $apply && $changed ) {
				update_post_meta( $pid, '_rmaps_location_markers', $markers );
			}
		}

		if ( ( microtime( true ) - $start ) >= RMAPS_THEME_DUPE_BUDGET ) {
			return array( 'more' => true, 'after' => $last, 'scanned' => $scanned, 'broken' => $broken, 'fixed' => ( $apply ? $broken : 0 ), 'nopool' => false );
		}
	}

	return array(
		'more'    => ( count( $ids ) >= RMAPS_THEME_DUPE_REPAIR_BATCH ),
		'after'   => $last,
		'scanned' => $scanned,
		'broken'  => $broken,
		'fixed'   => ( $apply ? $broken : 0 ),
		'nopool'  => false,
	);
}

/**
 * Move one attachment's files (original, -scaled, every size) to the trash
 * folder. Returns TRUE only when every file that existed was successfully
 * secured in the trash (so the caller may safely delete the record). Returns
 * FALSE if any original file is still sitting at its source path (e.g. the host
 * refused the rename) - the caller MUST then keep the attachment, because
 * deleting it would permanently destroy that file with no reversible copy.
 */
function rmaps_theme_dupe_trash_attachment_files( $att_id ) {
	$file = (string) get_post_meta( $att_id, '_wp_attached_file', true );
	if ( '' === $file ) { return true; } // nothing on disk to protect
	$up      = wp_get_upload_dir();
	$basedir = trailingslashit( str_replace( '\\', '/', (string) $up['basedir'] ) );
	$qbase   = $basedir . RMAPS_THEME_DUPE_TRASH . '/';

	$rels = array( ltrim( str_replace( '\\', '/', $file ), '/' ) );
	$meta = wp_get_attachment_metadata( $att_id );
	if ( is_array( $meta ) ) {
		$dir = ( false !== strpos( $rels[0], '/' ) ) ? trailingslashit( dirname( $rels[0] ) ) : '';
		if ( ! empty( $meta['file'] ) ) {
			$rels[] = ltrim( str_replace( '\\', '/', (string) $meta['file'] ), '/' );
			$dir    = ( false !== strpos( $meta['file'], '/' ) ) ? trailingslashit( dirname( $meta['file'] ) ) : $dir;
		}
		if ( ! empty( $meta['original_image'] ) ) { $rels[] = $dir . basename( (string) $meta['original_image'] ); }
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $s ) { if ( ! empty( $s['file'] ) ) { $rels[] = $dir . basename( (string) $s['file'] ); } }
		}
	}
	$ok = true;
	foreach ( array_unique( $rels ) as $rel ) {
		$src = $basedir . $rel;
		$dst = $qbase . $rel;
		if ( is_file( $src ) ) {
			wp_mkdir_p( dirname( $dst ) );
			// A failed rename (or a source that survives it) means no reversible
			// copy exists - flag it so the caller spares the attachment.
			if ( ! @rename( $src, $dst ) || is_file( $src ) ) { $ok = false; }
		}
	}
	return $ok;
}

/** Files currently sitting in the dedupe trash folder (count). */
function rmaps_theme_dupe_trash_count() {
	$up      = wp_get_upload_dir();
	$basedir = trailingslashit( str_replace( '\\', '/', (string) $up['basedir'] ) );
	$trash   = $basedir . RMAPS_THEME_DUPE_TRASH . '/';
	if ( ! is_dir( $trash ) ) { return 0; }
	$n  = 0;
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $trash, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::LEAVES_ONLY
	);
	foreach ( $it as $fi ) { if ( $fi->isFile() ) { $n++; } }
	return $n;
}

/**
 * Move files from the dedupe trash back to their original uploads path.
 * Batched + resumable. NOTE: this restores the FILES only - the attachment
 * records that the removal deleted are gone, so restored files come back as
 * orphans (the references were already re-pointed to the kept canonical).
 * @return array{more:bool, moved:int}
 */
function rmaps_theme_dupe_restore_trash() {
	if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 120 ); }
	$up      = wp_get_upload_dir();
	$basedir = trailingslashit( str_replace( '\\', '/', (string) $up['basedir'] ) );
	$trash   = $basedir . RMAPS_THEME_DUPE_TRASH . '/';
	if ( ! is_dir( $trash ) ) { return array( 'more' => false, 'moved' => 0 ); }

	// Collect a bounded batch first (don't mutate the dir while iterating).
	$files = array();
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $trash, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::LEAVES_ONLY
	);
	foreach ( $it as $fi ) {
		if ( $fi->isFile() ) {
			$files[] = str_replace( '\\', '/', $fi->getPathname() );
			if ( count( $files ) >= 2000 ) { break; }
		}
	}
	if ( empty( $files ) ) { return array( 'more' => false, 'moved' => 0 ); }

	$start = microtime( true );
	$moved = 0;
	foreach ( $files as $full ) {
		if ( ( microtime( true ) - $start ) >= RMAPS_THEME_DUPE_BUDGET ) {
			return array( 'more' => true, 'moved' => $moved );
		}
		$rel = ltrim( substr( $full, strlen( $trash ) ), '/' );
		$dst = $basedir . $rel;
		if ( file_exists( $dst ) ) {
			@unlink( $full ); // original already back -> drop the trash copy
		} else {
			wp_mkdir_p( dirname( $dst ) );
			@rename( $full, $dst );
		}
		$moved++;
	}
	return array( 'more' => ( count( $files ) >= 2000 ), 'moved' => $moved );
}

/**
 * Pre-flight: can we actually create the trash folder and write into it? The
 * removal must never start unless the answer is yes - otherwise a failed move
 * would (on an older build) permanently delete the file. Writes + deletes a
 * tiny probe file so the check reflects real permissions, not just mkdir.
 */
function rmaps_theme_dupe_trash_writable() {
	$up      = wp_get_upload_dir();
	$basedir = trailingslashit( str_replace( '\\', '/', (string) $up['basedir'] ) );
	$dir     = $basedir . RMAPS_THEME_DUPE_TRASH . '/';
	if ( ! wp_mkdir_p( $dir ) ) { return false; }
	$probe = $dir . '.writetest';
	$ok    = ( false !== @file_put_contents( $probe, 'ok' ) );
	if ( $ok ) { @unlink( $probe ); }
	return $ok;
}

/**
 * Remove a batch of duplicates: re-point the listing-photo id-meta keys
 * (`_thumbnail_id` / `_rmaps_logo_image_id` / `_rmaps_attached_image`) from the
 * dup to the canonical, trash the dup files, delete the dup attachment, and drop
 * it from the index. Does NOT touch `_rmaps_location_markers` (that holds the
 * per-location marker icon, not the photo). Idempotent + resumable (table state
 * IS the progress).
 * @return array{more:bool, removed:int, skipped:int, blocked?:bool}
 */
function rmaps_theme_dupe_remove_batch() {
	global $wpdb;
	if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 120 ); }
	if ( ! rmaps_theme_dupe_table_exists() ) { return array( 'more' => false, 'removed' => 0, 'skipped' => 0 ); }
	// Refuse to start if the trash folder is not writable - nothing must be
	// deleted when there is nowhere reversible to move it to.
	if ( ! rmaps_theme_dupe_trash_writable() ) {
		return array( 'more' => false, 'removed' => 0, 'skipped' => 0, 'blocked' => true );
	}
	$t = rmaps_theme_dupe_table();

	// Drop any index row whose attachment no longer exists. A stale row must never
	// be eligible as a group's canonical (MIN att_id) - that would re-point a live
	// listing onto a deleted attachment. Fast, and every surviving row keeps its
	// hash, so this does NOT trigger a re-hash.
	$wpdb->query(
		"DELETE i FROM {$t} i
		 LEFT JOIN {$wpdb->posts} p ON p.ID = i.att_id AND p.post_type = 'attachment'
		 WHERE p.ID IS NULL"
	);

	$dups = $wpdb->get_results(
		"SELECT i.att_id, c.keep
		 FROM {$t} i
		 JOIN ( SELECT hash, MIN(att_id) AS keep FROM {$t}
		        WHERE hash IS NOT NULL AND hash <> '' GROUP BY hash HAVING COUNT(*) > 1 ) c
		   ON c.hash = i.hash
		 WHERE i.att_id <> c.keep
		 LIMIT 150"
	);
	if ( empty( $dups ) ) { return array( 'more' => false, 'removed' => 0, 'skipped' => 0 ); }

	$map = array();
	foreach ( $dups as $d ) { $map[ (int) $d->att_id ] = (int) $d->keep; }
	$dup_ids = array_keys( $map );
	$in      = implode( ',', array_map( 'intval', $dup_ids ) );
	$keys    = rmaps_theme_dupe_id_meta_keys();

	// Re-point each id-meta key dup -> canonical, one UPDATE per key (CASE).
	// These are the keys that hold the LISTING PHOTO (featured / gallery / logo).
	// We deliberately do NOT touch `_rmaps_location_markers` - that is the
	// per-location MARKER ICON (fa class / png, or a custom marker image), not
	// the listing photo, so the photo dedup must leave it alone.
	$cases = '';
	foreach ( $map as $dup => $canon ) { $cases .= ' WHEN ' . (int) $dup . ' THEN ' . (int) $canon; }
	foreach ( $keys as $key ) {
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->postmeta} SET meta_value = CASE meta_value{$cases} ELSE meta_value END
			 WHERE meta_key = %s AND meta_value IN ($in)",
			$key
		) );
	}

	// Trash files + delete each dup attachment, within the time budget.
	$start   = microtime( true );
	$removed = 0;
	$skipped = 0;
	foreach ( $dup_ids as $dup ) {
		if ( ( microtime( true ) - $start ) >= RMAPS_THEME_DUPE_BUDGET ) {
			return array( 'more' => true, 'removed' => $removed, 'skipped' => $skipped );
		}
		// Drop from the index FIRST. If this batch is interrupted (503) mid-way,
		// the att must never linger in the index with its file already trashed -
		// that is the one state that lets a LATER pass pick it as a group's
		// canonical (MIN att_id) and re-point live listings onto a file that is
		// already in the trash. Worst case with this order: a dup survives
		// untrashed (a later re-index catches it), never a broken reference.
		$wpdb->delete( $t, array( 'att_id' => $dup ) );
		if ( rmaps_theme_dupe_trash_attachment_files( $dup ) ) {
			wp_delete_attachment( $dup, true ); // every file is safely in the trash -> delete the record
			$removed++;
		} else {
			// The trash move failed (host blocked the rename / unwritable folder).
			// Do NOT delete the attachment - that would permanently destroy the
			// in-place file with no reversible copy. Leave record + file intact;
			// a "Rescan from scratch" resurfaces it once the trash works.
			$skipped++;
		}
	}
	return array( 'more' => true, 'removed' => $removed, 'skipped' => $skipped );
}

/** The duplicate-group table (from the existing index). */
function rmaps_theme_dupe_render_results() {
	$totals = rmaps_theme_dupe_totals();
	$groups = rmaps_theme_dupe_groups( RMAPS_THEME_DUPE_MAX_GROUPS );
	?>
	<p>
		<?php
		printf(
			/* translators: 1: duplicate groups, 2: duplicate files */
			esc_html__( '%1$s duplicate group(s), %2$s duplicate file(s) to remove.', 'rmaps-theme' ),
			'<strong>' . esc_html( number_format_i18n( $totals['groups'] ) ) . '</strong>',
			'<strong>' . esc_html( number_format_i18n( $totals['dupes'] ) ) . '</strong>'
		);
		?>
	</p>
	<?php if ( ! empty( $groups ) ) : ?>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Keep (canonical)', 'rmaps-theme' ); ?></th>
				<th><?php esc_html_e( 'Duplicates to remove', 'rmaps-theme' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $groups as $g ) : ?>
				<tr>
					<td><code>#<?php echo (int) $g['canonical']['id']; ?></code> <?php echo esc_html( basename( $g['canonical']['file'] ) ); ?></td>
					<td>
						<?php foreach ( $g['dupes'] as $d ) : ?>
							<div><code>#<?php echo (int) $d['id']; ?></code> <?php echo esc_html( basename( $d['file'] ) ); ?></div>
						<?php endforeach; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $totals['groups'] > count( $groups ) ) : ?>
			<p class="description"><?php printf( esc_html__( 'Showing the first %s groups; the count above is complete.', 'rmaps-theme' ), esc_html( number_format_i18n( RMAPS_THEME_DUPE_MAX_GROUPS ) ) ); ?></p>
		<?php endif;
	endif;
}

/* ---------------------------------------------------------------
 * Admin page (Tools → Duplicate Media)
 * ---------------------------------------------------------------*/
add_action( 'admin_menu', function () {
	add_management_page(
		__( 'Duplicate Media', 'rmaps-theme' ),
		__( 'Duplicate Media', 'rmaps-theme' ),
		'manage_options',
		'rmaps-duplicate-media',
		'rmaps_theme_dupe_render_page'
	);
} );

function rmaps_theme_dupe_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'rmaps-theme' ) );
	}

	$notice    = '';
	$auto_kind = '';     // '' | 'scan' | 'remove' | 'imgscan' | 'imgfix'
	$phase     = '';
	$after     = 0;
	$audit     = null;   // set to the broken-reference list when the audit runs
	$rep_after = 0;      // image-repair cursor carried across auto-submits
	$rep_broken = 0;
	$rep_fixed = 0;

	if ( isset( $_POST['rmaps_dupe_action'] ) ) {
		check_admin_referer( 'rmaps_dupe' );
		$action = sanitize_key( wp_unslash( $_POST['rmaps_dupe_action'] ) );
		$phase  = isset( $_POST['rmaps_dupe_phase'] ) ? sanitize_key( wp_unslash( $_POST['rmaps_dupe_phase'] ) ) : 'index';
		$after  = isset( $_POST['rmaps_dupe_after'] ) ? max( 0, (int) $_POST['rmaps_dupe_after'] ) : 0;

		if ( 'rescan' === $action ) {
			rmaps_theme_dupe_reset_table();
			$phase = 'index'; $after = 0; $action = 'scan';
		}

		if ( 'scan' === $action ) {
			if ( 'hash' === $phase ) {
				$r = rmaps_theme_dupe_hash_batch( $after );
				if ( $r['more'] ) { $after = (int) $r['after']; $notice = sprintf( __( 'Hashing… (up to id %s)', 'rmaps-theme' ), number_format_i18n( $after ) ); $auto_kind = 'scan'; }
				else { $notice = __( 'Scan complete.', 'rmaps-theme' ); }
			} else {
				$r = rmaps_theme_dupe_index_batch( $after );
				if ( $r['more'] ) { $phase = 'index'; $after = (int) $r['after']; $notice = sprintf( __( 'Indexing… (up to id %s)', 'rmaps-theme' ), number_format_i18n( $after ) ); }
				else { $phase = 'hash'; $after = 0; $notice = __( 'Indexing done. Hashing…', 'rmaps-theme' ); }
				$auto_kind = 'scan';
			}
		} elseif ( 'remove' === $action ) {
			$r       = rmaps_theme_dupe_remove_batch();
			$skipped = isset( $r['skipped'] ) ? (int) $r['skipped'] : 0;
			if ( ! empty( $r['blocked'] ) ) {
				$notice    = __( 'STOPPED — nothing was changed. The trash folder uploads/_dedupe_trash/ is not writable on this host, so duplicates cannot be moved there reversibly. Fix that folder\'s permissions (create it writable) and press the button again.', 'rmaps-theme' );
				$auto_kind = '';
			} elseif ( $r['more'] ) { $notice = sprintf( __( 'Removed %s duplicate(s)… continuing.', 'rmaps-theme' ), number_format_i18n( (int) $r['removed'] ) ); $auto_kind = 'remove'; }
			else { $notice = __( 'All duplicates removed. Files are in uploads/_dedupe_trash/ (reversible).', 'rmaps-theme' ); }
			if ( $skipped > 0 ) {
				$notice .= ' ' . sprintf(
					/* translators: %s: count of attachments left intact */
					__( '%s attachment(s) were left intact because their files could NOT be moved to the trash folder (the host blocked it) — uploads/_dedupe_trash/ is not writable, so nothing was deleted for those.', 'rmaps-theme' ),
					number_format_i18n( $skipped )
				);
				$auto_kind = ''; // stop the auto-continue: the trash folder is broken, surface it
			}
		} elseif ( 'restore' === $action ) {
			$r = rmaps_theme_dupe_restore_trash();
			if ( $r['more'] ) { $notice = sprintf( __( 'Restored %s file(s) from trash… continuing.', 'rmaps-theme' ), number_format_i18n( (int) $r['moved'] ) ); $auto_kind = 'restore'; }
			else { $notice = __( 'Dedupe trash emptied — all files restored to uploads.', 'rmaps-theme' ); }
		} elseif ( 'audit' === $action ) {
			$audit = rmaps_theme_dupe_broken_refs( 500 );
			$cnt   = rmaps_theme_dupe_broken_refs_count();
			$notice = ( 0 === $cnt )
				? __( 'No broken references: every listing image points at an attachment that still exists.', 'rmaps-theme' )
				: sprintf(
					/* translators: %s: broken-reference count */
					_n( '%s listing image reference points at a deleted attachment.', '%s listing image references point at a deleted attachment.', $cnt, 'rmaps-theme' ),
					number_format_i18n( $cnt )
				);
		} elseif ( 'imgscan' === $action || 'imgfix' === $action ) {
			$apply  = ( 'imgfix' === $action );
			$rafter = isset( $_POST['rmaps_dupe_rep_after'] ) ? max( 0, (int) $_POST['rmaps_dupe_rep_after'] ) : 0;
			$rtotb  = isset( $_POST['rmaps_dupe_rep_broken'] ) ? max( 0, (int) $_POST['rmaps_dupe_rep_broken'] ) : 0;
			$rtotf  = isset( $_POST['rmaps_dupe_rep_fixed'] ) ? max( 0, (int) $_POST['rmaps_dupe_rep_fixed'] ) : 0;

			$r = rmaps_theme_dupe_repair_images_batch( $rafter, $apply );
			if ( ! empty( $r['nopool'] ) ) {
				$notice    = __( 'No intact image attachments to use as replacements — nothing changed.', 'rmaps-theme' );
				$auto_kind = '';
			} else {
				$rtotb     += (int) $r['broken'];
				$rtotf     += (int) $r['fixed'];
				$rep_after  = (int) $r['after'];
				$rep_broken = $rtotb;
				$rep_fixed  = $rtotf;
				$notice = $apply
					? sprintf(
						/* translators: 1: listings scanned this batch, 2: running total replaced */
						__( 'Repair: scanned %1$s listing(s) this batch — %2$s broken image reference(s) replaced with a valid attachment so far.', 'rmaps-theme' ),
						number_format_i18n( (int) $r['scanned'] ),
						number_format_i18n( $rtotf )
					)
					: sprintf(
						/* translators: 1: listings scanned this batch, 2: running total broken */
						__( 'Scan (no changes): %1$s listing(s) this batch — %2$s broken image reference(s) found so far.', 'rmaps-theme' ),
						number_format_i18n( (int) $r['scanned'] ),
						number_format_i18n( $rtotb )
					);
				if ( empty( $r['more'] ) ) { $notice .= ' ' . __( 'Done.', 'rmaps-theme' ); }
				$auto_kind = empty( $r['more'] ) ? '' : ( $apply ? 'imgfix' : 'imgscan' );
			}
		}
	}

	$prog    = rmaps_theme_dupe_progress();
	$totals  = rmaps_theme_dupe_totals();
	$has_tbl = rmaps_theme_dupe_table_exists();
	$trash_n = rmaps_theme_dupe_trash_count();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Duplicate Media', 'rmaps-theme' ); ?></h1>
		<p class="description"><?php esc_html_e( 'Finds the same image uploaded more than once (IMPACT / IMPACT1 / IMPACT2 …) and removes the extras, re-pointing references to one kept copy. The index is kept between visits — nothing is recomputed unless you press "Rescan from scratch". Removed files go to uploads/_dedupe_trash/ (reversible).', 'rmaps-theme' ); ?></p>

		<?php if ( '' !== $notice ) : ?>
			<div class="notice notice-info"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<?php if ( $totals['dupes'] > 0 ) : ?>
			<h2><?php esc_html_e( 'Duplicates found', 'rmaps-theme' ); ?></h2>
			<?php rmaps_theme_dupe_render_results(); ?>

			<?php if ( (int) $prog['unhashed'] > 0 ) : ?>
				<div class="notice notice-warning inline"><p><?php
					printf(
						/* translators: %s: count still NULL (un-hashed) */
						esc_html__( 'Note: %s attachment(s) are still un-hashed (hash = NULL). Removing now handles the duplicates already found; run "Continue / finish scan" to catch any remaining ones, then remove again. (Rows with an EMPTY hash are unique files — already processed, not pending.)', 'rmaps-theme' ),
						esc_html( number_format_i18n( (int) $prog['unhashed'] ) )
					);
				?></p></div>
			<?php endif; ?>
			<form method="post" id="rmaps-dupe-remove" style="display:inline-block;margin-right:1em" onsubmit="return (this.dataset.auto==='1')||confirm('<?php echo esc_js( __( 'Re-point references and move the duplicate files to the trash folder?', 'rmaps-theme' ) ); ?>');">
				<?php wp_nonce_field( 'rmaps_dupe' ); ?>
				<input type="hidden" name="rmaps_dupe_action" value="remove">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Move duplicates to trash', 'rmaps-theme' ); ?></button>
			</form>
		<?php elseif ( $has_tbl && 0 === (int) $prog['unhashed'] ) : ?>
			<p><em><?php esc_html_e( 'No duplicates found in the index.', 'rmaps-theme' ); ?></em></p>
		<?php endif; ?>

		<hr>
		<h2><?php esc_html_e( 'Index', 'rmaps-theme' ); ?></h2>
		<p class="description">
			<?php
			printf(
				/* translators: 1: indexed count, 2: still-to-hash count */
				esc_html__( 'Indexed %1$s attachment(s); %2$s still to hash.', 'rmaps-theme' ),
				esc_html( number_format_i18n( (int) $prog['indexed'] ) ),
				esc_html( number_format_i18n( (int) $prog['unhashed'] ) )
			);
			?>
		</p>

		<form method="post" id="rmaps-dupe-scan" style="display:inline-block;margin-right:1em" onsubmit="return (this.dataset.auto==='1')||true;">
			<?php wp_nonce_field( 'rmaps_dupe' ); ?>
			<input type="hidden" name="rmaps_dupe_action" value="scan">
			<input type="hidden" name="rmaps_dupe_phase" value="<?php echo esc_attr( $auto_kind === 'scan' ? $phase : ( $has_tbl ? 'hash' : 'index' ) ); ?>">
			<input type="hidden" name="rmaps_dupe_after" value="<?php echo esc_attr( (string) ( $auto_kind === 'scan' ? $after : 0 ) ); ?>">
			<button type="submit" class="button"><?php echo $has_tbl ? esc_html__( 'Continue / finish scan', 'rmaps-theme' ) : esc_html__( 'Scan for duplicates', 'rmaps-theme' ); ?></button>
		</form>

		<?php if ( $has_tbl ) : ?>
			<form method="post" style="display:inline-block" onsubmit="return confirm('<?php echo esc_js( __( 'Rebuild the index from scratch? (re-indexes and re-hashes everything)', 'rmaps-theme' ) ); ?>');">
				<?php wp_nonce_field( 'rmaps_dupe' ); ?>
				<input type="hidden" name="rmaps_dupe_action" value="rescan">
				<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Rescan from scratch', 'rmaps-theme' ); ?></button>
			</form>
		<?php endif; ?>

		<hr>
		<h2><?php esc_html_e( 'Reference check', 'rmaps-theme' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Read-only. Lists every listing whose image (featured / gallery / logo) still points at an attachment that no longer exists — i.e. a removal deleted the image without re-pointing the reference. Nothing is changed.', 'rmaps-theme' ); ?></p>
		<form method="post" style="margin-bottom:1em">
			<?php wp_nonce_field( 'rmaps_dupe' ); ?>
			<input type="hidden" name="rmaps_dupe_action" value="audit">
			<button type="submit" class="button"><?php esc_html_e( 'Find broken references', 'rmaps-theme' ); ?></button>
		</form>
		<?php if ( is_array( $audit ) && ! empty( $audit ) ) : ?>
			<table class="widefat striped" style="max-width:60em">
				<thead><tr>
					<th><?php esc_html_e( 'Post', 'rmaps-theme' ); ?></th>
					<th><?php esc_html_e( 'Type', 'rmaps-theme' ); ?></th>
					<th><?php esc_html_e( 'Reference key', 'rmaps-theme' ); ?></th>
					<th><?php esc_html_e( 'Dead attachment id', 'rmaps-theme' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $audit as $row ) :
					$pid   = (int) $row->post_id;
					$ptype = (string) $row->post_type;
					$gone  = ( '' === $ptype ); // listing post itself no longer exists (orphan meta)
					$title = '' !== (string) $row->post_title ? (string) $row->post_title : sprintf( '#%d', $pid );
					$edit  = $gone ? '' : (string) get_edit_post_link( $pid );
					?>
					<tr>
						<td>
							<?php if ( $edit ) : ?>
								<a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $title ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $title ); ?>
							<?php endif; ?>
							<span class="description">#<?php echo (int) $pid; ?></span>
						</td>
						<td><?php echo $gone ? '<em>' . esc_html__( '(deleted)', 'rmaps-theme' ) . '</em>' : esc_html( $ptype ); ?></td>
						<td><code><?php echo esc_html( (string) $row->meta_key ); ?></code></td>
						<td><?php echo (int) $row->att_id; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php printf( esc_html__( 'Showing up to %s. Fix: re-attach each listing to a surviving copy of the image (the duplicate that was deleted has a kept twin in the Media Library). Rows whose post Type is "(deleted)" are orphaned meta — the listing itself is gone, so they are safe to ignore.', 'rmaps-theme' ), esc_html( number_format_i18n( 500 ) ) ); ?></p>
		<?php endif; ?>

		<hr>
		<h2><?php esc_html_e( 'Repair broken images', 'rmaps-theme' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Walks every listing and finds image references whose target is gone — featured / gallery / logo ids pointing at a deleted attachment OR an attachment whose file is missing on disk, plus _rmaps_location_markers image-URL snapshots whose file is missing. "Scan" only counts. "Replace" swaps each broken reference for a random INTACT attachment so the front never shows a broken image. Batched + auto-continues; re-press on a 503.', 'rmaps-theme' ); ?></p>
		<form method="post" id="rmaps-dupe-imgscan" style="display:inline-block;margin-right:1em" onsubmit="return (this.dataset.auto==='1')||true;">
			<?php wp_nonce_field( 'rmaps_dupe' ); ?>
			<input type="hidden" name="rmaps_dupe_action" value="imgscan">
			<input type="hidden" name="rmaps_dupe_rep_after" value="<?php echo esc_attr( (string) ( $auto_kind === 'imgscan' ? $rep_after : 0 ) ); ?>">
			<input type="hidden" name="rmaps_dupe_rep_broken" value="<?php echo esc_attr( (string) ( $auto_kind === 'imgscan' ? $rep_broken : 0 ) ); ?>">
			<input type="hidden" name="rmaps_dupe_rep_fixed" value="0">
			<button type="submit" class="button"><?php esc_html_e( 'Scan (no changes)', 'rmaps-theme' ); ?></button>
		</form>
		<form method="post" id="rmaps-dupe-imgfix" style="display:inline-block" onsubmit="return (this.dataset.auto==='1')||confirm('<?php echo esc_js( __( 'Replace every broken listing image reference with a random intact attachment? This rewrites listing meta and is not automatically reversible.', 'rmaps-theme' ) ); ?>');">
			<?php wp_nonce_field( 'rmaps_dupe' ); ?>
			<input type="hidden" name="rmaps_dupe_action" value="imgfix">
			<input type="hidden" name="rmaps_dupe_rep_after" value="<?php echo esc_attr( (string) ( $auto_kind === 'imgfix' ? $rep_after : 0 ) ); ?>">
			<input type="hidden" name="rmaps_dupe_rep_broken" value="0">
			<input type="hidden" name="rmaps_dupe_rep_fixed" value="<?php echo esc_attr( (string) ( $auto_kind === 'imgfix' ? $rep_fixed : 0 ) ); ?>">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Replace broken with a valid image', 'rmaps-theme' ); ?></button>
		</form>

		<hr>
		<h2><?php esc_html_e( 'Dedupe trash', 'rmaps-theme' ); ?></h2>
		<?php if ( $trash_n > 0 ) : ?>
			<p class="description"><?php
				printf(
					/* translators: %s: file count in the dedupe trash */
					esc_html__( '%s file(s) in uploads/_dedupe_trash/. Restore moves them back to their original paths. (The attachment records the removal deleted are NOT recreated, so restored files return as orphans — their references already point to the kept copy.)', 'rmaps-theme' ),
					esc_html( number_format_i18n( (int) $trash_n ) )
				);
			?></p>
			<form method="post" id="rmaps-dupe-restore" onsubmit="return (this.dataset.auto==='1')||confirm('<?php echo esc_js( __( 'Move all files from the dedupe trash back to uploads?', 'rmaps-theme' ) ); ?>');">
				<?php wp_nonce_field( 'rmaps_dupe' ); ?>
				<input type="hidden" name="rmaps_dupe_action" value="restore">
				<button type="submit" class="button"><?php esc_html_e( 'Restore files from trash', 'rmaps-theme' ); ?></button>
			</form>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'The dedupe trash (uploads/_dedupe_trash/) is empty — there are no files to restore. If duplicates were removed but their files are missing, an older build deleted them without trashing (or the host blocked the trash folder); recover those files from a hosting backup.', 'rmaps-theme' ); ?></p>
		<?php endif; ?>

		<?php if ( '' !== $auto_kind ) : ?>
			<p id="rmaps-dupe-progress" class="description" style="margin-top:1em"><?php esc_html_e( 'Auto-continuing… (if the host stops it with a 503, just reload this page and press the same button — progress is saved)', 'rmaps-theme' ); ?>
				<button type="button" class="button button-small" onclick="window.__rmapsDupeStop=1;this.parentNode.appendChild(document.createTextNode(' — stopped'));"><?php esc_html_e( 'Stop', 'rmaps-theme' ); ?></button>
			</p>
			<script>
			window.__rmapsDupeStop = 0;
			setTimeout(function () {
				if ( window.__rmapsDupeStop ) { return; }
				var f = document.getElementById(<?php echo wp_json_encode( 'rmaps-dupe-' . $auto_kind ); ?>);
				if ( f ) { f.dataset.auto = '1'; f.submit(); }
			}, 5000);
			</script>
		<?php endif; ?>
	</div>
	<?php
}
