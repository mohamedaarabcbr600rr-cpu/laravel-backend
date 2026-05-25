<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateConversationsTable extends Migration
{
   public function up()
{
    Schema::create('conversations', function (Blueprint $table) {
        $table->id();

        $table->unsignedBigInteger('user_one');
        $table->unsignedBigInteger('user_two');

        $table->timestamps();

        $table->foreign('user_one')
            ->references('id')
            ->on('users')
            ->onDelete('cascade');

        $table->foreign('user_two')
            ->references('id')
            ->on('users')
            ->onDelete('cascade');

        // 🔥 PERFORMANCE
        $table->index(['user_one', 'user_two']);
    });
}

    public function down()
    {
        Schema::dropIfExists('conversations');
    }
}