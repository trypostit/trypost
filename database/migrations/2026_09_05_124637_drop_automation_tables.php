<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('posts')->where('created_via', 'automation')->update(['created_via' => 'web']);

        Schema::dropIfExists('automation_node_states');
        Schema::dropIfExists('automation_node_runs');
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_trigger_items');
        Schema::dropIfExists('automations');
    }

    public function down(): void
    {
        // Irreversible: the automations module and its data are gone for good.
    }
};
