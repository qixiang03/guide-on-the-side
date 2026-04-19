<?php
declare(strict_types=1);

/**
 * Test double for the H5P plugin's core saveContent() entry point.
 * Records every saveContent() call and returns a preset new content ID.
 */
final class FakeH5PCore
{
    /** @var list<array> */
    public array $saveContentCalls = [];

    /** @var list<int> */
    public array $saveContentReturns = [];

    /** When true, saveContent() returns a WP_Error instead of an int. */
    public bool $failNext = false;

    public function saveContent(array $content): int|WP_Error
    {
        $this->saveContentCalls[] = $content;
        if ($this->failNext) {
            $this->failNext = false;
            return new WP_Error('pbsg_fake_h5p_error', 'simulated saveContent failure');
        }
        return (int) array_shift($this->saveContentReturns) ?: 0;
    }
}

/**
 * Test double for the global H5P_Plugin singleton.
 * handle_import() resolves the core via $GLOBALS['H5P_Plugin']->get_h5p_instance('core').
 */
final class FakeH5PPlugin
{
    public function __construct(private FakeH5PCore $core) {}

    public function get_h5p_instance(string $type): FakeH5PCore
    {
        return $this->core;
    }
}
