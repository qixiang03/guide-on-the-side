<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Helpers/MockWpdb.php';

/**
 * Unit tests for PBSG_Template_Manager CRUD and migration gate.
 */
final class PBSGTemplateManagerTest extends TestCase
{
    private MockWpdb $wpdb;

    protected function setUp(): void
    {
        WPStubs::reset();
        $this->wpdb = new MockWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void
    {
        WPStubs::reset();
        unset($GLOBALS['wpdb']);
    }

    /**
     * @covers PBSG_Template_Manager::maybe_create_tables
     */
    public function test_maybe_create_tables_no_ops_when_option_matches_version(): void
    {
        WPStubs::$returns['get_option_' . PBSG_Template_Manager::OPT_VER] = PBSG_Template_Manager::DB_VER;

        PBSG_Template_Manager::maybe_create_tables();

        $this->assertSame(0, $this->wpdb->countQueriesContaining('pbsg_tutorial_templates'));
        $this->assertFalse(WPStubs::wasCalled('update_option'));
    }

    /**
     * @covers PBSG_Template_Manager::get_templates
     */
    public function test_get_templates_returns_rows_from_db(): void
    {
        $rows = [
            ['id' => '1', 'name' => 'Alpha', 'description' => '', 'category' => 'G', 'is_system' => '1', 'created_at' => '2020-01-01'],
        ];
        $this->wpdb->returns['get_results'] = $rows;

        $out = PBSG_Template_Manager::get_templates();

        $this->assertSame($rows, $out);
        $this->assertGreaterThan(0, $this->wpdb->countQueriesContaining('pbsg_tutorial_templates'));
    }

    /**
     * @covers PBSG_Template_Manager::get_template
     */
    public function test_get_template_returns_single_row(): void
    {
        $row = ['id' => 2, 'name' => 'T', 'steps_json' => '[]', 'header_note' => '', 'is_system' => 0];
        $this->wpdb->returns['get_row'] = $row;

        $out = PBSG_Template_Manager::get_template(2);

        $this->assertSame($row, $out);
    }

    /**
     * @covers PBSG_Template_Manager::save_as_template
     */
    public function test_save_as_template_inserts_with_sanitized_fields(): void
    {
        WPStubs::$returns['get_current_user_id'] = 99;

        $id = PBSG_Template_Manager::save_as_template(10, ' My Name ', 'Desc', 'Cat', '["x"]', 'Note');

        $this->assertSame(1, $id);
        $insert = null;
        foreach ($this->wpdb->calls as $c) {
            if ($c['method'] === 'insert') {
                $insert = $c['args'];
                break;
            }
        }
        $this->assertNotNull($insert);
        $this->assertStringContainsString('pbsg_tutorial_templates', $insert[0]);
        $data = $insert[1];
        $this->assertSame('My Name', $data['name']);
        $this->assertSame('Desc', $data['description']);
        $this->assertSame('Cat', $data['category']);
        $this->assertSame(0, $data['is_system']);
        $this->assertSame('["x"]', $data['steps_json']);
        $this->assertSame('Note', $data['header_note']);
        $this->assertSame(99, $data['created_by']);
    }

    /**
     * @covers PBSG_Template_Manager::delete_template
     */
    public function test_delete_template_returns_false_for_system_template(): void
    {
        $this->wpdb->returns['get_row'] = ['id' => 1, 'is_system' => 1, 'name' => 'Split Guide (Default)'];

        $this->assertFalse(PBSG_Template_Manager::delete_template(1));
        $deleted = false;
        foreach ($this->wpdb->calls as $c) {
            if ($c['method'] === 'delete') {
                $deleted = true;
                break;
            }
        }
        $this->assertFalse($deleted);
    }

    /**
     * @covers PBSG_Template_Manager::delete_template
     */
    public function test_delete_template_deletes_user_template(): void
    {
        $this->wpdb->returns['get_row'] = ['id' => 5, 'is_system' => 0, 'name' => 'Mine'];

        $ok = PBSG_Template_Manager::delete_template(5);

        $this->assertTrue($ok);
        $deleted = false;
        foreach ($this->wpdb->calls as $c) {
            if ($c['method'] === 'delete') {
                $deleted = true;
                $this->assertSame(['id' => 5], $c['args'][1]);
                break;
            }
        }
        $this->assertTrue($deleted);
    }

    /**
     * @covers PBSG_Template_Manager::create_from_template
     */
    public function test_create_from_template_blank_inserts_draft_page(): void
    {
        WPStubs::$returns['get_current_user_id'] = 3;
        WPStubs::$returns['get_userdata'] = (object) ['display_name' => 'Pat'];
        WPStubs::$returns['get_option_date_format'] = 'Y-m-d';
        WPStubs::$returns['get_option_pbsg_library_catalog_url'] = 'https://lib.example/';
        WPStubs::$returns['date_i18n'] = '2026-03-30';
        WPStubs::$returns['wp_insert_post'] = 500;

        $postId = PBSG_Template_Manager::create_from_template(0, 'Hello {{TUTORIAL_TITLE}}');

        $this->assertSame(500, $postId);
        $args = WPStubs::callArgs('wp_insert_post', 0);
        $postarr = $args[0];
        $this->assertSame('Hello {{TUTORIAL_TITLE}}', $postarr['post_title']);
        $this->assertSame('page', $postarr['post_type']);
        $this->assertSame('draft', $postarr['post_status']);
        $this->assertSame(3, $postarr['post_author']);
        $meta = $postarr['meta_input'];
        $this->assertSame(PB_Split_Guide_Plugin::TEMPLATE_SLUG, $meta['_wp_page_template']);
        $this->assertSame('[]', $meta['_pbsg_steps_json']);
    }

    /**
     * @covers PBSG_Template_Manager::create_from_template
     */
    public function test_create_from_template_unknown_id_returns_wp_error(): void
    {
        WPStubs::$returns['get_current_user_id'] = 1;
        WPStubs::$returns['get_userdata'] = null;
        WPStubs::$returns['get_option_date_format'] = 'Y-m-d';
        WPStubs::$returns['get_option_pbsg_library_catalog_url'] = '';
        $this->wpdb->returns['get_row'] = null;

        $result = PBSG_Template_Manager::create_from_template(999, 'T');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('not_found', $result->get_error_code());
    }
}
