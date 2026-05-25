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
        Schema::create('shares', function (Blueprint $table) {
            $table->id();

            // ✅ Publication qui a été partagée
            $table->foreignId('experience_id')->constrained()->onDelete('cascade');

            // ✅ Utilisateur qui a partagé
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            $table->timestamps();

            // ✅ Index pour récupérer qui a partagé une publication
            $table->index(['experience_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shares');
    }
};
