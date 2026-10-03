<?php

declare(strict_types=1);

use App\Enums\Post\Origin;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->string('origin', 16)->default(Origin::DEFAULT->value)->after('created_via');
            $table->index(['workspace_id', 'origin']);
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'origin']);
            $table->dropColumn('origin');
        });
    }
};
