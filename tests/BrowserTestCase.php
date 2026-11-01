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

    /**
     * The invitation to connect an AI sits over the bottom right of every page
     * of a test user (who has app access and no AI), so it stays off unless a
     * test turns it on with `showMcpPrompt()`.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['trypost.mcp_prompt.enabled' => false]);
    }
}
