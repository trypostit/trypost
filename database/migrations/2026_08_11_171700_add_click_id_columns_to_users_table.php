<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
