<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Support\Facades\Vite;

abstract class BrowserTestCase extends TestCase
{
    /**
     * Browser tests drive a real browser and load the built Vite assets, so the
     * manifest must not be faked away.
     */
    protected bool $fakesVite = false;

    protected function setUp(): void
    {
        parent::setUp();

        Vite::useHotFile(base_path('tests/.vite-hot-file-that-never-exists'));
    }
}
