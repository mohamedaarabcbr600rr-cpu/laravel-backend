// database/migrations/xxxx_create_student_profiles_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStudentProfilesTable extends Migration
{
    public function up()
    {
        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->enum('niveau', ['debutant', 'intermediaire', 'avance'])->default('intermediaire');
            $table->json('points_faibles')->nullable();
            $table->json('points_forts')->nullable();
            $table->float('score_moyen')->default(0);
            $table->integer('total_qcm')->default(0);
            $table->integer('total_questions_repondues')->default(0);
            $table->integer('bonnes_reponses')->default(0);
            $table->json('matieres_preferees')->nullable();
            $table->timestamps();
            
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('student_profiles');
    }
}