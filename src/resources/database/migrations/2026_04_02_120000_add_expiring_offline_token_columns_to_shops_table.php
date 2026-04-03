<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Osiset\ShopifyApp\Util;

class AddExpiringOfflineTokenColumnsToShopsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table(Util::getShopsTable(), function (Blueprint $table) {
            $table->text('shopify_offline_refresh_token')->nullable();
            $table->timestamp('shopify_offline_access_token_expires_at')->nullable();
            $table->timestamp('shopify_offline_refresh_token_expires_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table(Util::getShopsTable(), function (Blueprint $table) {
            $table->dropColumn([
                'shopify_offline_refresh_token',
                'shopify_offline_access_token_expires_at',
                'shopify_offline_refresh_token_expires_at',
            ]);
        });
    }
}
