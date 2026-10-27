<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A generic trial now grants app access, and only an offer starts one.
     * Clears any `trial_ends_at` left from the no-card signup trial, which
     * Cloud never ran, so no old row grants access after this deploy.
     */
    public function up(): void
    {
        DB::table('accounts')->whereNotNull('trial_ends_at')->update(['trial_ends_at' => null]);
    }

    public function down(): void
    {
        //
    }
};
