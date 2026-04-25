<?php
declare(strict_types=1);

/**
 * Lightweight $wpdb mock for analytics unit tests.
 *
 * Records all queries and allows tests to preset return values
 * for get_results(), get_row(), query(), and update() calls.
 */
class MockWpdb
{
    /** @var string WordPress table prefix */
    public string $prefix = 'wp_';

    /** @var string Posts table name */
    public string $posts = 'wp_posts';

    /** @var string Postmeta table name */
    public string $postmeta = 'wp_postmeta';

    /** @var string Users table name */
    public string $users = 'wp_users';

    /** @var list<array{method: string, args: array}> All recorded calls */
    public array $calls = [];

    /** @var list<string> All SQL queries passed to query() or prepare() */
    public array $queries = [];

    /** @var array<string, mixed> Preset return values keyed by method name or SQL pattern */
    public array $returns = [];

    /** @var string|null Last error message */
    public ?string $last_error = null;

    /** @var int Rows affected by last query */
    public int $rows_affected = 0;

    /** @var int Last insert ID */
    public int $insert_id = 0;

    /**
     * Reset all recorded state.
     */
    public function reset(): void
    {
        $this->calls        = [];
        $this->queries      = [];
        $this->returns      = [];
        $this->last_error   = null;
        $this->rows_affected = 0;
        $this->insert_id    = 0;
    }

    /**
     * Mock prepare() — performs basic sprintf-like substitution.
     */
    public function prepare(string $query, ...$args): string
    {
        $this->calls[] = ['method' => 'prepare', 'args' => func_get_args()];

        // Flatten array args (WordPress passes args as separate params or single array)
        $flat_args = [];
        foreach ($args as $arg) {
            if (is_array($arg)) {
                $flat_args = array_merge($flat_args, $arg);
            } else {
                $flat_args[] = $arg;
            }
        }

        // Simple placeholder replacement for testing
        $i = 0;
        $result = preg_replace_callback('/%[sdfi]/', function ($match) use ($flat_args, &$i) {
            $val = $flat_args[$i++] ?? '';
            if ($match[0] === '%d' || $match[0] === '%i') {
                return (string) (int) $val;
            }
            if ($match[0] === '%f') {
                return (string) (float) $val;
            }
            return "'" . addslashes((string) $val) . "'";
        }, $query);

        $this->queries[] = $result;
        return $result;
    }

    /**
     * Mock query() — records the SQL and returns preset value.
     */
    public function query(string $query)
    {
        $this->calls[] = ['method' => 'query', 'args' => [$query]];
        $this->queries[] = $query;
        $this->rows_affected = 1;
        return $this->findReturn('query', $query, true);
    }

    /**
     * Mock get_results() — returns preset array of rows.
     */
    public function get_results(string $query, string $output = 'OBJECT')
    {
        $this->calls[] = ['method' => 'get_results', 'args' => [$query, $output]];
        $this->queries[] = $query;
        return $this->findReturn('get_results', $query, []);
    }

    /**
     * Mock get_row() — returns preset single row.
     */
    public function get_row(string $query, string $output = 'OBJECT', int $y = 0)
    {
        $this->calls[] = ['method' => 'get_row', 'args' => [$query, $output]];
        $this->queries[] = $query;

        // H5P content row lookup — keyed by content id extracted from query
        if (isset($this->returns['h5p_content_rows'])
            && preg_match('/wp_h5p_contents/i', $query)
            && preg_match('/= (\d+)/', $query, $m)
        ) {
            $id = (int) $m[1];
            return $this->returns['h5p_content_rows'][$id] ?? null;
        }

        // H5P library resolution — keyed by name|major|minor
        if (isset($this->returns['h5p_library_resolutions'])
            && preg_match('/wp_h5p_libraries/i', $query)
            && preg_match("/name = '([^']+)'/", $query, $mn)
            && preg_match('/major_version = (\d+)/', $query, $mmaj)
            && preg_match('/minor_version = (\d+)/', $query, $mmin)
        ) {
            $key = "{$mn[1]}|{$mmaj[1]}|{$mmin[1]}";
            if (isset($this->returns['h5p_library_resolutions'][$key])) {
                return ['id' => (int) $this->returns['h5p_library_resolutions'][$key]];
            }
            return null;
        }

        return $this->findReturn('get_row', $query, null);
    }

    /**
     * Mock get_var() — returns preset single value (e.g. for SHOW TABLES LIKE).
     */
    public function get_var(string $query = null, int $x = 0, int $y = 0)
    {
        $this->calls[] = ['method' => 'get_var', 'args' => [$query, $x, $y]];
        if ($query !== null) {
            $this->queries[] = $query;
        }
        return $this->findReturn('get_var', $query ?? '', null);
    }

    /**
     * Mock insert() — records insert call.
     */
    public function insert(string $table, array $data, $format = null)
    {
        $this->calls[] = ['method' => 'insert', 'args' => [$table, $data, $format]];
        $this->rows_affected = 1;
        $this->insert_id = $this->insert_id + 1;
        return $this->findReturn('insert', $table, 1);
    }

    /**
     * Mock update() — records update call.
     */
    public function update(string $table, array $data, array $where, $format = null, $where_format = null)
    {
        $this->calls[] = ['method' => 'update', 'args' => [$table, $data, $where, $format, $where_format]];
        $this->rows_affected = 1;
        return $this->findReturn('update', $table, 1);
    }

    /**
     * Mock delete() — records delete call.
     */
    public function delete(string $table, array $where, $where_format = null)
    {
        $this->calls[] = ['method' => 'delete', 'args' => [$table, $where, $where_format]];
        $this->rows_affected = 1;
        return $this->findReturn('delete', $table, 1);
    }

    /**
     * Mock get_charset_collate().
     */
    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    /**
     * Find a preset return value by method and optional SQL pattern match.
     */
    private function findReturn(string $method, string $context, $default)
    {
        // Check for pattern-specific returns first
        foreach ($this->returns as $key => $value) {
            if (str_starts_with($key, $method . ':') && str_contains($context, substr($key, strlen($method) + 1))) {
                return $value;
            }
        }
        // Fall back to method-level return
        return $this->returns[$method] ?? $default;
    }

    /**
     * Get all queries containing a specific substring.
     */
    public function getQueriesContaining(string $substring): array
    {
        return array_values(array_filter($this->queries, fn($q) => str_contains($q, $substring)));
    }

    /**
     * Get count of queries containing a specific substring.
     */
    public function countQueriesContaining(string $substring): int
    {
        return count($this->getQueriesContaining($substring));
    }
}
