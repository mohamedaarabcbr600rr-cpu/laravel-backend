<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_messages', function (Blueprint $table) {
            $table->foreignId('reply_to_id')->nullable()->after('id')
                  ->constrained('group_messages')->nullOnDelete();
            $table->timestamp('deleted_for_everyone_at')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('group_messages', function (Blueprint $table) {
            $table->dropForeign(['reply_to_id']);
            $table->dropColumn(['reply_to_id', 'deleted_for_everyone_at']);
        });
    }
};