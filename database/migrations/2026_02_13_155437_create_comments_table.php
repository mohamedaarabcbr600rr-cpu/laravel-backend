<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();

            // ✅ Utilisateur qui a écrit le commentaire
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // ✅ Expérience/Publication sur laquelle on commente
            $table->foreignId('experience_id')->constrained()->onDelete('cascade');

            // ✅ Contenu du commentaire
            $table->text('content');

            $table->timestamps();

            // ✅ Index pour récupérer les commentaires d'une expérience
            $table->index('experience_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
