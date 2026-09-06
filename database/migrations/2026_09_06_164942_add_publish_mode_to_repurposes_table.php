<?php

declare(strict_types=1);

use App\Enums\Repurpose\PublishMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repurposes', function (Blueprint $table): void {
            $table->string('publish_mode')->default(PublishMode::Publish->value)->after('source_format');
        });
    }

    public function down(): void
    {
        Schema::table('repurposes', function (Blueprint $table): void {
            $table->dropColumn('publish_mode');
        });
    }
};
