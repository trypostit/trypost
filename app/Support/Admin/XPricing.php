<?php

declare(strict_types=1);

namespace App\Support\Admin;

/**
 * X's public pay-per-use prices, in USD, used only to estimate the admin's
 * daily X cost. X marks them as subject to change; the console is the source
 * of what was actually charged.
 *
 * @see https://docs.x.com/x-api/getting-started/pricing
 */
final class XPricing
{
    /** "Posts: Read", per Post returned. */
    public const float POST_READ = 0.005;

    /** "Post: Create", per request. */
    public const float POST_CREATE = 0.015;

    /** "Post: Create (with URL)", per request. */
    public const float POST_CREATE_WITH_URL = 0.20;
}
