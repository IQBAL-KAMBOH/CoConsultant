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
        Schema::table('users', function (Blueprint $table) {
            $table->text('onedrive_token')->nullable();
            $table->text('onedrive_refresh_token')->nullable();
            $table->timestamp('onedrive_token_expires_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('onedrive_token');
            $table->dropColumn('onedrive_refresh_token');
            $table->dropColumn('onedrive_token_expires_at');
        });
    }
};
