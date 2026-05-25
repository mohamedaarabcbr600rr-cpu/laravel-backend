<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMediaColumnsToExperiencesTable extends Migration
{
    public function up()
    {
        Schema::table('experiences', function (Blueprint $table) {

            if (!Schema::hasColumn('experiences', 'media_path')) {
                $table->string('media_path')->nullable();
            }

            if (!Schema::hasColumn('experiences', 'media_type')) {
                $table->enum('media_type', ['image', 'video'])->nullable();
            }

        });
    }

    public function down()
    {
        Schema::table('experiences', function (Blueprint $table) {
            $table->dropColumn(['media_path', 'media_type']);
        });
    }
}