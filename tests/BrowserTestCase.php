<?php

declare(strict_types=1);

namespace Tests;

abstract class BrowserTestCase extends TestCase
{
    /**
     * Browser tests drive a real browser and load the built Vite assets, so the
     * manifest must not be faked away.
     */
    protected bool $fakesVite = false;
}
