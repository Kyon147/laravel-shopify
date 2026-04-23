<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Osiset\ShopifyApp\Util;

/**
 * Adds columns required for Shopify expiring offline tokens.
 *
 * As of December 2025, Shopify deprecated non-expiring offline tokens.
 * Every OAuth exchange now returns:
 *   - access_token   (expires in 1 hour)
 *   - refresh_token  (expires in 90 days, one-time use)
 *
 * These three columns let the application track expiry and perform
 * automatic token refresh before making Shopify API calls.
 */
class AddExpiringTokenFieldsToShopsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table(Util::getShopsTable(), function (Blueprint $table) {
            if (! Schema::hasColumn(Util::getShopsTable(), 'refresh_token')) {
                $table->text('refresh_token')->nullable()->after('password');
            }

            if (! Schema::hasColumn(Util::getShopsTable(), 'token_expires_at')) {
                $table->timestamp('token_expires_at')->nullable()->after('refresh_token');
            }

            if (! Schema::hasColumn(Util::getShopsTable(), 'refresh_token_expires_at')) {
                $table->timestamp('refresh_token_expires_at')->nullable()->after('token_expires_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table(Util::getShopsTable(), function (Blueprint $table) {
            $table->dropColumn([
                'refresh_token',
                'token_expires_at',
                'refresh_token_expires_at',
            ]);
        });
    }
}
