<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // already handled in create_messages_table
    }

    public function down()
    {
        // nothing to rollback
    }
};
