<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            // Пресет отображения даты/времени (см. UpdateWorkspaceRequest).
            // Null = «авто»: локализованный формат dayjs, прежнее поведение.
            $table->string('datetime_format', 20)->nullable()->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('datetime_format');
        });
    }
};
