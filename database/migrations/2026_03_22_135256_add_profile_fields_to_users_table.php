<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddProfileFieldsToUsersTable extends Migration
{
    public function up()
{
    Schema::table('users', function (Blueprint $table) {

        if (!Schema::hasColumn('users', 'username')) {
            $table->string('username')->unique()->after('id');
        }

        if (!Schema::hasColumn('users', 'bio')) {
            $table->string('bio', 150)->nullable()->after('name');
        }

        if (!Schema::hasColumn('users', 'link')) {
            $table->string('link')->nullable()->after('bio');
        }

        if (!Schema::hasColumn('users', 'profile_pic')) {
            $table->string('profile_pic')->nullable()->after('link');
        }

    });
}
    
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username', 'bio', 'link', 'profile_pic']);
        });
    }
}