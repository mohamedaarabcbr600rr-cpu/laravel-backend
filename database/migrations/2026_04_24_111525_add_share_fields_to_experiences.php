<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   public function up()
{
    Schema::table('experiences', function (Blueprint $table) {

        if (!Schema::hasColumn('experiences', 'shared_from')) {
            $table->unsignedBigInteger('shared_from')->nullable();
        }

        if (!Schema::hasColumn('experiences', 'media_path')) {
            $table->string('media_path')->nullable();
        }

        if (!Schema::hasColumn('experiences', 'media_type')) {
            $table->string('media_type')->nullable();
        }

    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
{
    Schema::table('experiences', function (Blueprint $table) {

        if (Schema::hasColumn('experiences', 'shared_from')) {
            $table->dropColumn('shared_from');
        }

        if (Schema::hasColumn('experiences', 'media_path')) {
            $table->dropColumn('media_path');
        }

        if (Schema::hasColumn('experiences', 'media_type')) {
            $table->dropColumn('media_type');
        }

    });
}
};
