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
        Schema::create('likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('experience_id')->constrained()->onDelete('cascade');

            // ✅ Type de réaction : like, love, haha
            $table->enum('reaction_type', [
    'like',
    'love',
    'haha',
    'wow',
    'sad',
    'angry'
])->default('like');

            $table->timestamps();

            // ✅ Index unique : un utilisateur ne peut avoir qu'une seule réaction par expérience
            $table->unique(['user_id', 'experience_id']);

            // ✅ Index pour compter les réactions par type
            $table->index(['experience_id', 'reaction_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('likes');
    }
};
