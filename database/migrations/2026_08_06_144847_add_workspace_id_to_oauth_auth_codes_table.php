<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_auth_codes', function (Blueprint $table) {
            $table->foreignUuid('workspace_id')
                ->nullable()
                ->after('client_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('oauth_auth_codes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workspace_id');
        });
    }
};
