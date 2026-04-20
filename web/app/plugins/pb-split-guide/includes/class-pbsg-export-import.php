<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PBSG Export / Import
 * Stretch Goal 4: package a tutorial as a portable JSON file and re-import it
 * on a different server.
 *
 * Export format (v1.1):
 *   - pbsg_version = "1.1"
 *   - title, post_content, header_note
 *   - cover_id as "att_<id>" token
 *   - steps[] with *_id fields tokenized:
 *       - tutorial_attachment_id, branch_tutorial_attachment_id → "att_<id>"
 *       - h5p_id (and any *_h5p_id) → "h5p_<id>"
 *   - attachments[] — every referenced upload, base64-encoded
 *   - h5p_contents[] — every referenced wp_h5p_contents row, keyed by library
 *     (name + major + minor) rather than numeric library_id, parameters passed
 *     through verbatim as a JSON string.
 *
 * Backward-compat: v1.0 packages (no h5p_contents key) still import cleanly —
 * steps' integer h5p_id survives unchanged, matching legacy behavior.
 */
class PBSG_Export_Import {

	const EXPORT_VERSION = '1.1';

	public static function init() {
		add_action( 'wp_ajax_pbsg_export_tutorial', [ __CLASS__, 'handle_export' ] );
		add_action( 'wp_ajax_pbsg_import_tutorial', [ __CLASS__, 'handle_import' ] );
	}

	// ── EXPORT ───────────────────────────────────────────────────────────────

	/**
	 * Triggered by a plain form POST (not XHR) — outputs a .json file download.
	 */
	public static function handle_export() {
		check_ajax_referer( 'pbsg_export_import', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( 'You do not have permission to export this tutorial.', 403 );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_die( 'Tutorial not found.' );
		}

		$steps_json  = get_post_meta( $post_id, '_pbsg_steps_json', true ) ?: '[]';
		$header_note = get_post_meta( $post_id, '_pbsg_header_note', true ) ?: '';
		$cover_id    = (int) get_post_meta( $post_id, '_pbsg_cover_image_id', true );
		$steps       = json_decode( $steps_json, true ) ?: [];

		// Collect all local attachment IDs referenced in steps + cover
		$att_ids = [];
		foreach ( $steps as $step ) {
			if ( ! empty( $step['tutorial_attachment_id'] ) ) {
				$att_ids[] = (int) $step['tutorial_attachment_id'];
			}
			if ( ! empty( $step['branch_tutorial_attachment_id'] ) ) {
				$att_ids[] = (int) $step['branch_tutorial_attachment_id'];
			}
		}
		if ( $cover_id ) $att_ids[] = $cover_id;
		$att_ids = array_unique( array_filter( $att_ids ) );

		// Collect all H5P content IDs referenced in steps + branches
		$h5p_ids = [];
		foreach ( $steps as $step ) {
			foreach ( $step as $key => $value ) {
				if ( is_string( $key )
					&& substr( $key, -strlen( 'h5p_id' ) ) === 'h5p_id'
					&& is_numeric( $value )
					&& (int) $value > 0
				) {
					$h5p_ids[] = (int) $value;
				}
			}
		}
		$h5p_ids = array_values( array_unique( $h5p_ids ) );

		// Fetch each referenced H5P content row joined to its library
		global $wpdb;
		$h5p_contents = [];
		foreach ( $h5p_ids as $hid ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT c.id, c.title, c.parameters, c.disable,
					        l.name AS library_name, l.major_version, l.minor_version
					   FROM {$wpdb->prefix}h5p_contents c
					   JOIN {$wpdb->prefix}h5p_libraries l ON l.id = c.library_id
					  WHERE c.id = %d",
					$hid
				),
				ARRAY_A
			);
			if ( ! $row ) continue;

			$h5p_contents[] = [
				'original_id' => (int) $row['id'],
				'title'       => (string) $row['title'],
				'library'     => [
					'name'          => (string) $row['library_name'],
					'major_version' => (int) $row['major_version'],
					'minor_version' => (int) $row['minor_version'],
				],
				'parameters'  => (string) $row['parameters'],
				'disable'     => (int) $row['disable'],
			];
		}

		// Encode each attachment as base64
		$attachments = [];
		foreach ( $att_ids as $aid ) {
			$path = get_attached_file( $aid );
			if ( ! $path || ! file_exists( $path ) ) continue;

			$attachments[ $aid ] = [
				'original_id' => $aid,
				'filename'    => basename( $path ),
				'mime_type'   => get_post_mime_type( $aid ) ?: 'application/octet-stream',
				'data'        => base64_encode( file_get_contents( $path ) ), // phpcs:ignore
			];
		}

		// Replace attachment IDs in steps with portable tokens
		foreach ( $steps as &$step ) {
			$taid = (int) ( $step['tutorial_attachment_id'] ?? 0 );
			if ( $taid && isset( $attachments[ $taid ] ) ) {
				$step['tutorial_attachment_id'] = 'att_' . $taid;
			}

			$baid = (int) ( $step['branch_tutorial_attachment_id'] ?? 0 );
			if ( $baid && isset( $attachments[ $baid ] ) ) {
				$step['branch_tutorial_attachment_id'] = 'att_' . $baid;
			}

			foreach ( $step as $key => $value ) {
				if ( is_string( $key )
					&& substr( $key, -strlen( 'h5p_id' ) ) === 'h5p_id'
					&& is_int( $value ) && $value > 0
				) {
					$step[ $key ] = 'h5p_' . $value;
				}
			}
		}
		unset( $step );

		$package = [
			'pbsg_version' => self::EXPORT_VERSION,
			'exported_at'  => gmdate( 'c' ),
			'title'        => $post->post_title,
			'post_content' => $post->post_content,
			'header_note'  => $header_note,
			'cover_id'     => $cover_id ? ( 'att_' . $cover_id ) : null,
			'steps'        => $steps,
			'h5p_contents' => $h5p_contents,
			'attachments'  => array_values( $attachments ),
		];

		$filename = sanitize_file_name( $post->post_title . '-guide-on-the-side.json' );
		$json     = wp_json_encode( $package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $json ) );
		echo $json; // phpcs:ignore
		wp_die( '' );
	}

	// ── IMPORT ───────────────────────────────────────────────────────────────

	/**
	 * Triggered by AJAX (XHR with FormData) — creates a new draft page and
	 * returns JSON with the edit URL.
	 */
	public static function handle_import() {
		check_ajax_referer( 'pbsg_export_import', 'nonce' );

		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_send_json_error( [ 'message' => 'You do not have permission to import tutorials.' ], 403 );
		}

		if ( empty( $_FILES['pbsg_import_file'] ) || $_FILES['pbsg_import_file']['error'] !== UPLOAD_ERR_OK ) {
			$code = $_FILES['pbsg_import_file']['error'] ?? -1;
			wp_send_json_error( [ 'message' => 'Upload failed (error code ' . $code . ').' ] );
		}

		$content = file_get_contents( $_FILES['pbsg_import_file']['tmp_name'] ); // phpcs:ignore
		if ( ! $content ) {
			wp_send_json_error( [ 'message' => 'Could not read the uploaded file.' ] );
		}

		$package = json_decode( $content, true );
		if ( ! is_array( $package ) || empty( $package['pbsg_version'] ) ) {
			wp_send_json_error( [ 'message' => 'Invalid export file. Make sure you are uploading a Guide on the Side .json export.' ] );
		}

		$title        = sanitize_text_field( $package['title'] ?? 'Imported Tutorial' );
		$header_note  = sanitize_text_field( $package['header_note'] ?? '' );
		$post_content = wp_kses_post( $package['post_content'] ?? '' );
		$steps        = is_array( $package['steps'] ) ? $package['steps'] : [];
		$cover_token  = $package['cover_id'] ?? null;

		$h5p_contents_in = is_array( $package['h5p_contents'] ?? null ) ? $package['h5p_contents'] : [];

		if ( ! empty( $h5p_contents_in ) && ! class_exists( 'H5P_Plugin' ) ) {
			wp_send_json_error( [
				'message' => 'This tutorial contains H5P quizzes. Install and activate the H5P plugin on this server before importing.',
			] );
			return;
		}

		$h5p_library_ids = []; // keyed by original_id
		$h5p_missing     = [];
		if ( ! empty( $h5p_contents_in ) ) {
			global $wpdb;
			foreach ( $h5p_contents_in as $entry ) {
				$lib  = $entry['library'] ?? [];
				$name = isset( $lib['name'] ) ? (string) $lib['name'] : '';
				$maj  = isset( $lib['major_version'] ) ? (int) $lib['major_version'] : -1;
				$min  = isset( $lib['minor_version'] ) ? (int) $lib['minor_version'] : -1;

				$row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT id FROM {$wpdb->prefix}h5p_libraries
						  WHERE name = %s AND major_version = %d AND minor_version = %d
						  LIMIT 1",
						$name, $maj, $min
					),
					ARRAY_A
				);
				if ( $row && ! empty( $row['id'] ) ) {
					$h5p_library_ids[ (int) $entry['original_id'] ] = (int) $row['id'];
				} else {
					$h5p_missing[] = "{$name} {$maj}.{$min}";
				}
			}

			if ( ! empty( $h5p_missing ) ) {
				$h5p_missing = array_values( array_unique( $h5p_missing ) );
				wp_send_json_error( [
					'message' => 'The target server is missing these H5P libraries required by this tutorial: '
						. implode( ', ', $h5p_missing )
						. '. Install them via H5P → Libraries and try again.',
				] );
				return;
			}
		}

		$h5p_id_map = []; // "h5p_<original_id>" => new int id
		if ( ! empty( $h5p_contents_in ) ) {
			$h5p_core = $GLOBALS['H5P_Plugin']->get_h5p_instance( 'core' );
			foreach ( $h5p_contents_in as $entry ) {
				$orig_id = (int) ( $entry['original_id'] ?? 0 );
				if ( $orig_id <= 0 ) continue;

				$params_str = (string) ( $entry['parameters'] ?? '' );
				if ( json_decode( $params_str, true ) === null && json_last_error() !== JSON_ERROR_NONE ) {
					wp_send_json_error( [
						'message' => "Quiz {$orig_id} has invalid parameters JSON: " . json_last_error_msg(),
					] );
					return;
				}

				$new_id = $h5p_core->saveContent( [
					'library'    => [
						'libraryId'    => $h5p_library_ids[ $orig_id ] ?? 0,
						'name'         => (string) $entry['library']['name'],
						'majorVersion' => (int) $entry['library']['major_version'],
						'minorVersion' => (int) $entry['library']['minor_version'],
					],
					'parameters' => $params_str,
					'disable'    => (int) ( $entry['disable'] ?? 0 ),
					'title'      => (string) ( $entry['title'] ?? '' ),
				] );

				if ( is_wp_error( $new_id ) ) {
					wp_send_json_error( [
						'message' => 'H5P saveContent failed for quiz '
							. $orig_id . ': ' . $new_id->get_error_message()
							. '. Earlier quizzes in this import may have been created — delete them via H5P admin.',
					] );
					return;
				}

				$h5p_id_map[ 'h5p_' . $orig_id ] = (int) $new_id;
			}
		}

		// Re-upload attachments, building token → new attachment ID map
		$id_map = [];

		if ( ! empty( $package['attachments'] ) && is_array( $package['attachments'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			foreach ( $package['attachments'] as $att ) {
				if ( empty( $att['data'] ) || empty( $att['filename'] ) ) continue;

				$decoded = base64_decode( $att['data'], true );
				if ( $decoded === false ) continue;

				$safe_name = sanitize_file_name( $att['filename'] );
				$upload    = wp_upload_bits( $safe_name, null, $decoded );

				if ( ! empty( $upload['error'] ) ) continue;

				$attachment_id = wp_insert_attachment( [
					'post_title'     => $safe_name,
					'post_content'   => '',
					'post_status'    => 'inherit',
					'post_mime_type' => $att['mime_type'] ?? 'application/octet-stream',
				], $upload['file'] );

				if ( is_wp_error( $attachment_id ) ) continue;

				wp_update_attachment_metadata(
					$attachment_id,
					wp_generate_attachment_metadata( $attachment_id, $upload['file'] )
				);

				$id_map[ 'att_' . $att['original_id'] ] = $attachment_id;
			}
		}

		// Remap portable tokens → new IDs in steps
		foreach ( $steps as &$step ) {
			$ttoken = $step['tutorial_attachment_id'] ?? '';
			if ( is_string( $ttoken ) && strpos( $ttoken, 'att_' ) === 0 ) {
				$new_id = $id_map[ $ttoken ] ?? 0;
				$step['tutorial_attachment_id'] = $new_id;
				if ( ! $new_id ) $step['tutorial_type'] = '';
			}

			$btoken = $step['branch_tutorial_attachment_id'] ?? '';
			if ( is_string( $btoken ) && strpos( $btoken, 'att_' ) === 0 ) {
				$new_id = $id_map[ $btoken ] ?? 0;
				$step['branch_tutorial_attachment_id'] = $new_id;
				if ( ! $new_id ) $step['branch_tutorial_type'] = '';
			}

			foreach ( $step as $key => $value ) {
				if ( is_string( $key )
					&& substr( $key, -strlen( 'h5p_id' ) ) === 'h5p_id'
					&& is_string( $value )
					&& strpos( $value, 'h5p_' ) === 0
				) {
					$step[ $key ] = $h5p_id_map[ $value ] ?? 0;
				}
			}
		}
		unset( $step );

		$clean_steps  = PBSG_Steps_Normalizer::normalize( $steps );
		$new_cover_id = ( $cover_token && isset( $id_map[ $cover_token ] ) ) ? $id_map[ $cover_token ] : 0;

		$meta = [
			'_wp_page_template' => PB_Split_Guide_Plugin::TEMPLATE_SLUG,
			'_pbsg_steps_json'  => wp_json_encode( $clean_steps ),
			'_pbsg_header_note' => $header_note,
		];
		if ( $new_cover_id ) {
			$meta['_pbsg_cover_image_id'] = $new_cover_id;
		}

		$post_id = wp_insert_post( [
			'post_title'   => $title,
			'post_content' => $post_content,
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_author'  => get_current_user_id(),
			'meta_input'   => $meta,
		], true );

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( [ 'message' => $post_id->get_error_message() ] );
		}

		wp_send_json_success( [
			'post_id'  => $post_id,
			'title'    => $title,
			'edit_url' => get_edit_post_link( $post_id, 'url' ),
		] );
	}
}
