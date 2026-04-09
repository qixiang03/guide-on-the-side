<?php
declare(strict_types=1);

/**
 * Transient-cached inverted index: h5p_id → tutorial_post_id[].
 *
 * Builds by scanning all _pbsg_steps_json post meta. Cached as a single
 * transient with no expiry — invalidated on save_post_page.
 */
final class PBSG_H5P_Usage_Map
{
    private const TRANSIENT_KEY = 'pbsg_h5p_usage_map';
    private const META_KEY      = '_pbsg_steps_json';

    /**
     * Get the full usage map. Returns cached version if available,
     * otherwise builds from postmeta and caches.
     *
     * @return array<int, list<int>> h5p_id => [tutorial_post_id, ...]
     */
    public static function get_map(): array
    {
        $cached = get_transient(self::TRANSIENT_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $map = self::build();
        set_transient(self::TRANSIENT_KEY, $map);
        return $map;
    }

    /**
     * Get the usage count for a single H5P content ID.
     */
    public static function count(int $h5p_id): int
    {
        $map = self::get_map();
        return count($map[$h5p_id] ?? []);
    }

    /**
     * Delete the cached transient. Called on save_post_page and after
     * H5P duplication to force a rebuild on next request.
     */
    public static function invalidate(): void
    {
        delete_transient(self::TRANSIENT_KEY);
    }

    /**
     * Build the inverted index from all _pbsg_steps_json post meta.
     *
     * @return array<int, list<int>>
     */
    private static function build(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '" . self::META_KEY . "'",
            ARRAY_A
        );

        $map = [];

        foreach ($rows ?: [] as $row) {
            $post_id = (int) $row['post_id'];
            $steps = json_decode($row['meta_value'] ?? '', true);

            if (!is_array($steps)) {
                continue;
            }

            foreach ($steps as $step) {
                $h5p_id = (int) ($step['h5p_id'] ?? 0);
                if ($h5p_id > 0) {
                    if (!isset($map[$h5p_id])) {
                        $map[$h5p_id] = [];
                    }
                    if (!in_array($post_id, $map[$h5p_id], true)) {
                        $map[$h5p_id][] = $post_id;
                    }
                }
            }
        }

        return $map;
    }
}
