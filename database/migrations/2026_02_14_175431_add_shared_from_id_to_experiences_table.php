<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * ✅ Ajoute la colonne shared_from avec clé étrangère
     */
    public function up(): void
    {
        Schema::table('experiences', function (Blueprint $table) {
            // ✅ Ajoute la colonne si elle n'existe pas déjà
            if (!Schema::hasColumn('experiences', 'shared_from')) {
                $table->unsignedBigInteger('shared_from')->nullable()->after('media_type');

                // ✅ Ajoute la clé étrangère vers la table experiences (auto-référence)
                $table->foreign('shared_from')
                      ->references('id')
                      ->on('experiences')
                      ->onDelete('cascade');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('experiences', function (Blueprint $table) {
            // ✅ Supprime la clé étrangère d'abord
            $table->dropForeign(['shared_from']);

            // ✅ Supprime la colonne
            $table->dropColumn('shared_from');
        });
    }
};
