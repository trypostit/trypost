<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

/**
 * Flashes the toast the next page shows (`Toast.vue`). Any controller can use
 * it; a message without a link still goes through `->with('flash.success', …)`.
 */
trait FlashesToast
{
    /** A success toast with a link after its message, like "View post". */
    protected function flashSuccessWithLink(string $message, string $label, string $url): void
    {
        session()->flash('flash.success', $message);
        session()->flash('flash.link', ['label' => $label, 'url' => $url]);
    }
}
