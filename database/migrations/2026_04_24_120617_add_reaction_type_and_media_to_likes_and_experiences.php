<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ajouter reaction_type à likes
        Schema::table('likes', function (Blueprint $table) {
            if (!Schema::hasColumn('likes', 'reaction_type')) {
                $table->enum('reaction_type', ['like', 'love', 'haha'])->default('like')->after('experience_id');
            }
        });

        // Ajouter media_path et media_type à experiences
        Schema::table('experiences', function (Blueprint $table) {
            if (!Schema::hasColumn('experiences', 'media_path')) {
                $table->string('media_path')->nullable()->after('content');
            }
            if (!Schema::hasColumn('experiences', 'media_type')) {
                $table->enum('media_type', ['image', 'video'])->nullable()->after('media_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('likes', function (Blueprint $table) {
            $table->dropColumn('reaction_type');
        });
        Schema::table('experiences', function (Blueprint $table) {
            $table->dropColumn(['media_path', 'media_type']);
        });
    }
};