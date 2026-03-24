<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PBSG Template Manager
 * Handles CRUD for the wp_pbsg_tutorial_templates table.
 */
class PBSG_Template_Manager {

	const TABLE   = 'pbsg_tutorial_templates';
	const DB_VER  = '1.0';
	const OPT_VER = 'pbsg_templates_db_version';

	// ── Schema ──────────────────────────────────────────────────────────────

	public static function create_tables() {
		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name        VARCHAR(200)    NOT NULL,
			description TEXT,
			category    VARCHAR(100)    DEFAULT '',
			is_system   TINYINT(1)      DEFAULT 0,
			steps_json  LONGTEXT        NOT NULL DEFAULT '[]',
			header_note VARCHAR(500)    DEFAULT '',
			created_by  BIGINT UNSIGNED DEFAULT 0,
			created_at  DATETIME        DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::OPT_VER, self::DB_VER );

		self::seed_defaults();
	}

	/**
	 * Called on admin_init — creates table on first run after activation.
	 */
	public static function maybe_create_tables() {
		if ( get_option( self::OPT_VER ) === self::DB_VER ) return;
		self::create_tables();
	}

	// ── Seeding ─────────────────────────────────────────────────────────────

	private static function seed_defaults() {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$exists = $wpdb->get_var(
			"SELECT id FROM {$table} WHERE is_system = 1 AND name = 'Split Guide (Default)' LIMIT 1"
		);
		if ( $exists ) return;

		$empty_step = [
			'title'                         => '',
			'h5p_id'                        => 0,
			'tutorial_type'                 => '',
			'tutorial_url'                  => '',
			'tutorial_attachment_id'        => 0,
			'tutorial_file_name'            => '',
			'tutorial_file_url'             => '',
			'url'                           => '',
			'branch_mode'                   => 'none',
			'branch_trigger_attempts'       => 1,
			'branch_title'                  => '',
			'branch_intro'                  => '',
			'branch_tutorial_type'          => '',
			'branch_tutorial_url'           => '',
			'branch_tutorial_attachment_id' => 0,
			'branch_tutorial_file_name'     => '',
			'branch_tutorial_file_url'      => '',
		];

		$wpdb->insert( $table, [
			'name'        => 'Split Guide (Default)',
			'description' => 'A blank tutorial with one empty step — the best starting point.',
			'category'    => 'General',
			'is_system'   => 1,
			'steps_json'  => wp_json_encode( [ $empty_step ] ),
			'header_note' => '',
			'created_by'  => 0,
		] );
	}

	// ── CRUD ────────────────────────────────────────────────────────────────

	public static function get_templates() {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		return $wpdb->get_results(
			"SELECT id, name, description, category, is_system, created_at
			 FROM {$table}
			 ORDER BY is_system DESC, name ASC",
			ARRAY_A
		) ?: [];
	}

	public static function get_template( $id ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ),
			ARRAY_A
		);
	}

	/**
	 * Save the current steps of a post as a named template.
	 *
	 * @return int|false  New template ID on success, false on failure.
	 */
	public static function save_as_template( $post_id, $name, $description = '', $category = '', $steps_json = null, $header_note = null ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		// Prefer live data passed from the editor DOM; fall back to DB
		$steps_json  = ( $steps_json !== null ) ? $steps_json : ( get_post_meta( $post_id, '_pbsg_steps_json', true ) ?: '[]' );
		$header_note = ( $header_note !== null ) ? $header_note : ( get_post_meta( $post_id, '_pbsg_header_note', true ) ?: '' );

		$wpdb->insert( $table, [
			'name'        => sanitize_text_field( $name ),
			'description' => sanitize_textarea_field( $description ),
			'category'    => sanitize_text_field( $category ),
			'is_system'   => 0,
			'steps_json'  => $steps_json,
			'header_note' => $header_note,
			'created_by'  => get_current_user_id(),
		] );

		return $wpdb->insert_id ?: false;
	}

	/**
	 * Create a new draft page from a template.
	 * Pass $template_id = 0 for a completely blank page.
	 *
	 * @return int|WP_Error  Post ID on success.
	 */
	public static function create_from_template( $template_id, $title ) {
		$title = sanitize_text_field( $title ) ?: 'New Tutorial';

		$author_id    = get_current_user_id();
		$author       = get_userdata( $author_id );
		$author_name  = $author ? $author->display_name : '';
		$current_date = date_i18n( get_option( 'date_format' ) );
		$catalog_url  = get_option( 'pbsg_library_catalog_url', '' );

		if ( (int) $template_id > 0 ) {
			$tpl = self::get_template( $template_id );
			if ( ! $tpl ) {
				return new WP_Error( 'not_found', 'Template not found.' );
			}
			$steps_json  = $tpl['steps_json'];
			$header_note = $tpl['header_note'];
		} else {
			$steps_json  = '[]';
			$header_note = '';
		}

		// Replace placeholder tokens in step data
		$tokens = [
			'{{TUTORIAL_TITLE}}'      => $title,
			'{{AUTHOR_NAME}}'         => $author_name,
			'{{CURRENT_DATE}}'        => $current_date,
			'{{LIBRARY_CATALOG_URL}}' => $catalog_url,
		];
		$steps_json  = str_replace( array_keys( $tokens ), array_values( $tokens ), $steps_json );
		$header_note = str_replace( array_keys( $tokens ), array_values( $tokens ), $header_note );

		$post_id = wp_insert_post( [
			'post_title'  => $title,
			'post_type'   => 'page',
			'post_status' => 'draft',
			'post_author' => $author_id,
			'meta_input'  => [
				'_wp_page_template' => PB_Split_Guide_Plugin::TEMPLATE_SLUG,
				'_pbsg_steps_json'  => $steps_json,
				'_pbsg_header_note' => sanitize_text_field( $header_note ),
			],
		], true );

		return $post_id; // WP_Error or int
	}

	/**
	 * Delete a user-created template (system templates cannot be deleted).
	 */
	public static function delete_template( $id ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$tpl = self::get_template( (int) $id );
		if ( ! $tpl || $tpl['is_system'] ) return false;

		return (bool) $wpdb->delete( $table, [ 'id' => (int) $id ] );
	}
}
