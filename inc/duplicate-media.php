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
	$last  = (int) $after;
	$more  = true;

	while ( ( microtime( true ) - $start ) < RMAPS_THEME_DUPE_BUDGET ) {
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT att_id, file, stem, sz FROM {$t} WHERE att_id > %d ORDER BY att_id ASC LIMIT 40",
			$last
		) );
		if ( empty( $rows ) ) { $more = false; break; }

		foreach ( $rows as $r ) {
			if ( ( microtime( true ) - $start ) >= RMAPS_THEME_DUPE_BUDGET ) {
				return array( 'more' => true, 'after' => $last, 'count' => $done );
			}
			$last = (int) $r->att_id;
			$sib  = $wpdb->get_var( $wpdb->prepare(
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
		if ( count( $rows ) < 40 ) { $more = false; break; }
	}
	return array( 'more' => $more, 'after' => $last, 'count' => $done );
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

/** Move one attachment's files (original, -scaled, every size) to the trash folder. */
function rmaps_theme_dupe_trash_attachment_files( $att_id ) {
	$file = (string) get_post_meta( $att_id, '_wp_attached_file', true );
	if ( '' === $file ) { return; }
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
	foreach ( array_unique( $rels ) as $rel ) {
		$src = $basedir . $rel;
		$dst = $qbase . $rel;
		if ( is_file( $src ) ) {
			wp_mkdir_p( dirname( $dst ) );
			@rename( $src, $dst );
		}
	}
}

/**
 * Remove a batch of duplicates: re-point id-meta to the canonical, invalidate
 * the affected listings' marker cache, trash the dup files, delete the dup
 * attachment, and drop it from the index. Idempotent + resumable (table state
 * IS the progress).
 * @return array{more:bool, removed:int}
 */
function rmaps_theme_dupe_remove_batch() {
	global $wpdb;
	if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 120 ); }
	if ( ! rmaps_theme_dupe_table_exists() ) { return array( 'more' => false, 'removed' => 0 ); }
	$t = rmaps_theme_dupe_table();

	$dups = $wpdb->get_results(
		"SELECT i.att_id, c.keep
		 FROM {$t} i
		 JOIN ( SELECT hash, MIN(att_id) AS keep FROM {$t}
		        WHERE hash IS NOT NULL AND hash <> '' GROUP BY hash HAVING COUNT(*) > 1 ) c
		   ON c.hash = i.hash
		 WHERE i.att_id <> c.keep
		 LIMIT 150"
	);
	if ( empty( $dups ) ) { return array( 'more' => false, 'removed' => 0 ); }

	$map = array();
	foreach ( $dups as $d ) { $map[ (int) $d->att_id ] = (int) $d->keep; }
	$dup_ids = array_keys( $map );
	$in      = implode( ',', array_map( 'intval', $dup_ids ) );
	$keys    = rmaps_theme_dupe_id_meta_keys();
	$kph     = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

	// 1. Listings that referenced a dup - their resolved-marker cache must be
	//    dropped so they re-resolve to the canonical image on next view.
	$affected = $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ($kph) AND meta_value IN ($in)",
		$keys
	) );

	// 2. Re-point each id-meta key dup -> canonical, one UPDATE per key (CASE).
	$cases = '';
	foreach ( $map as $dup => $canon ) { $cases .= ' WHEN ' . (int) $dup . ' THEN ' . (int) $canon; }
	foreach ( $keys as $key ) {
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->postmeta} SET meta_value = CASE meta_value{$cases} ELSE meta_value END
			 WHERE meta_key = %s AND meta_value IN ($in)",
			$key
		) );
	}

	// 3. Invalidate the marker cache for the affected listings.
	if ( ! empty( $affected ) ) {
		$ain = implode( ',', array_map( 'intval', $affected ) );
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_rmaps_location_markers' AND post_id IN ($ain)" );
	}

	// 4. Trash files + delete each dup attachment, within the time budget.
	$start   = microtime( true );
	$removed = 0;
	foreach ( $dup_ids as $dup ) {
		if ( ( microtime( true ) - $start ) >= RMAPS_THEME_DUPE_BUDGET ) {
			return array( 'more' => true, 'removed' => $removed );
		}
		rmaps_theme_dupe_trash_attachment_files( $dup );
		wp_delete_attachment( $dup, true ); // files already moved -> deletes the record
		$wpdb->delete( $t, array( 'att_id' => $dup ) );
		$removed++;
	}
	return array( 'more' => true, 'removed' => $removed );
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
	$auto_kind = '';     // '' | 'scan' | 'remove'
	$phase     = '';
	$after     = 0;

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
			$r = rmaps_theme_dupe_remove_batch();
			if ( $r['more'] ) { $notice = sprintf( __( 'Removed %s duplicate(s)… continuing.', 'rmaps-theme' ), number_format_i18n( (int) $r['removed'] ) ); $auto_kind = 'remove'; }
			else { $notice = __( 'All duplicates removed. Files are in uploads/_dedupe_trash/ (reversible).', 'rmaps-theme' ); }
		}
	}

	$prog    = rmaps_theme_dupe_progress();
	$totals  = rmaps_theme_dupe_totals();
	$has_tbl = rmaps_theme_dupe_table_exists();
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
