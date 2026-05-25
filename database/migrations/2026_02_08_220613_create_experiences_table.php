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
        Schema::create('experiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title')->nullable();
            $table->text('content')->nullable();

            // ✅ Colonnes pour les médias (image/vidéo)
            $table->string('media_path')->nullable();
            $table->enum('media_type', ['image', 'video'])->nullable();

            // ✅ Colonne pour le partage (référence au post original)
            $table->unsignedBigInteger('shared_from')->nullable();

            $table->timestamps();

            // ✅ Index pour les partages
            $table->index('shared_from');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('experiences');
    }
};
