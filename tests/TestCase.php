<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Indicates whether the default seeder should run before each test.
     *
     * @var bool
     */
    protected $seed = true;

    /**
     * Whether to fake the Vite manifest. Browser tests drive a real browser and
     * need the built assets, so they opt out via BrowserTestCase.
     */
    protected bool $fakesVite = true;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->fakesVite) {
            $this->withoutVite();
        }
    }
}
