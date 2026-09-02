<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Osiset\ShopifyApp\Util;

class AddCurrencyColumnsToPlansTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table(Util::getShopifyConfig('table_names.plans', 'plans'), function (Blueprint $table) {
            $table->string('currency', 10)->default('USD')->after('price');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table(Util::getShopifyConfig('table_names.plans', 'plans'), function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
}
