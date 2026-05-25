// database/migrations/xxxx_create_student_progress_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStudentProgressTable extends Migration
{
    public function up()
    {
        Schema::create('student_progress', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('question');
            $table->string('user_answer');
            $table->string('correct_answer');
            $table->boolean('is_correct');
            $table->string('topic')->nullable();
            $table->string('difficulty')->nullable();
            $table->integer('score')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'topic', 'is_correct']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('student_progress');
    }
}