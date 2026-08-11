<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ad platforms explicitly warn against assuming a fixed max length for
        // click IDs (Google: gclid has already grown from 26 to 100+ chars and
        // says never truncate/validate against a fixed length) — text avoids
        // ever silently corrupting one via truncation, unlike our own UTM
        // params where we control the length.
        Schema::table('users', function (Blueprint $table): void {
            $table->text('gclid')->nullable()->after('utm_content');
            $table->text('fbclid')->nullable()->after('gclid');
            $table->text('li_fat_id')->nullable()->after('fbclid');
            $table->text('ttclid')->nullable()->after('li_fat_id');
            $table->text('rdt_cid')->nullable()->after('ttclid');
            $table->text('epik')->nullable()->after('rdt_cid');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['gclid', 'fbclid', 'li_fat_id', 'ttclid', 'rdt_cid', 'epik']);
        });
    }
};
