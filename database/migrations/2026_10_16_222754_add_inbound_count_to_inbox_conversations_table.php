<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_conversations', function (Blueprint $table) {
            $table->unsignedInteger('inbound_count')->default(0)->after('message_count');
        });

        DB::table('inbox_messages')
            ->where('direction', 'inbound')
            ->whereNull('removed_at')
            ->select('inbox_conversation_id')
            ->selectRaw('count(*) as total')
            ->groupBy('inbox_conversation_id')
            ->orderBy('inbox_conversation_id')
            ->get()
            ->each(fn (object $row) => DB::table('inbox_conversations')
                ->where('id', $row->inbox_conversation_id)
                ->update(['inbound_count' => (int) $row->total]));
    }

    public function down(): void
    {
        Schema::table('inbox_conversations', function (Blueprint $table) {
            $table->dropColumn('inbound_count');
        });
    }
};
