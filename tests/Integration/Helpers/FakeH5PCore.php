<?php
declare(strict_types=1);

/**
 * Minimal test double for the H5P plugin's H5PCore object.
 *
 * Used by PBSGExportImportH5PTest to exercise H5P content
 * export/import logic without a real WordPress + H5P installation.
 */
class FakeH5PCore
{
    /** @var array<int, array<string, mixed>> Simulates saved H5P content rows */
    public array $savedContent = [];

    /** Next auto-increment ID returned by saveContent() */
    private int $nextId = 1;

    /**
     * @param array<string, mixed> $content
     * @return int The new content ID
     */
    public function saveContent(array $content): int
    {
        $id = $this->nextId++;
        $this->savedContent[$id] = $content;
        return $id;
    }
}

/**
 * Minimal test double for the top-level H5P_Plugin WordPress object.
 * Wraps a FakeH5PCore so the plugin code can call
 * $GLOBALS['H5P_Plugin']->get_h5p_instance('core').
 */
class FakeH5PPlugin
{
    private FakeH5PCore $core;

    public function __construct(FakeH5PCore $core)
    {
        $this->core = $core;
    }

    public function get_h5p_instance(string $type): FakeH5PCore
    {
        return $this->core;
    }
}
