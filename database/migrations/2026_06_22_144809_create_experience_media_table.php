<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateExperienceMediaTable extends Migration
{
    public function up()
    {
        Schema::create('experience_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('experience_id')->constrained('experiences')->onDelete('cascade');
            $table->string('path');
            $table->enum('type', ['image', 'video'])->default('image');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('experience_media');
    }
}