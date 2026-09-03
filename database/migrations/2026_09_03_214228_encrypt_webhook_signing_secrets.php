<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhooks', function (Blueprint $table) {
            $table->text('signing_secret')->change();
        });

        DB::table('webhooks')->orderBy('id')->each(function (object $webhook): void {
            $secret = $webhook->signing_secret;

            if (! is_string($secret) || ! str_starts_with($secret, 'whsec_')) {
                return;
            }

            DB::table('webhooks')->where('id', $webhook->id)->update([
                'signing_secret' => Crypt::encryptString($secret),
            ]);
        });
    }

    public function down(): void
    {
        DB::table('webhooks')->orderBy('id')->each(function (object $webhook): void {
            $secret = $webhook->signing_secret;

            if (! is_string($secret) || str_starts_with($secret, 'whsec_')) {
                return;
            }

            DB::table('webhooks')->where('id', $webhook->id)->update([
                'signing_secret' => Crypt::decryptString($secret),
            ]);
        });

        Schema::table('webhooks', function (Blueprint $table) {
            $table->string('signing_secret')->change();
        });
    }
};
