// database/migrations/xxxx_create_ai_interactions_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAiInteractionsTable extends Migration
{
    public function up()
    {
        Schema::create('ai_interactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->enum('type', ['chat', 'summary', 'qcm', 'flashcards', 'explain']);
            $table->text('input_text')->nullable();
            $table->text('ai_response')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'type', 'created_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('ai_interactions');
    }
}