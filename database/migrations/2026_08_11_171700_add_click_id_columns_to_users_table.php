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
            $table->string('gclid')->nullable()->after('utm_content');
            $table->string('fbclid')->nullable()->after('gclid');
            $table->string('li_fat_id')->nullable()->after('fbclid');
            $table->string('ttclid')->nullable()->after('li_fat_id');
            $table->string('rdt_cid')->nullable()->after('ttclid');
            $table->string('epik')->nullable()->after('rdt_cid');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['gclid', 'fbclid', 'li_fat_id', 'ttclid', 'rdt_cid', 'epik']);
        });
    }
};
