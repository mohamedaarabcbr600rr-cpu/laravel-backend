<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'referral_code')) {
                $table->string('referral_code', 12)->unique()->nullable();
            }
            if (! Schema::hasColumn('users', 'referred_by')) {
                $table->unsignedBigInteger('referred_by')->nullable();
                $table->foreign('referred_by')->references('id')->on('users')->onDelete('set null');
            }
            if (! Schema::hasColumn('users', 'referral_credited')) {
                $table->boolean('referral_credited')->default(false);
            }
            if (! Schema::hasColumn('users', 'referral_count')) {
                $table->unsignedInteger('referral_count')->default(0);
            }
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['referred_by']);
            $table->dropColumn(['referral_code', 'referred_by', 'referral_credited', 'referral_count']);
        });
    }
};