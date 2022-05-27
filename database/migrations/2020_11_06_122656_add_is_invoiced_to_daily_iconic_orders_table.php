<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIsInvoicedToDailyIconicOrdersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('daily_iconic_orders', function (Blueprint $table) {
            $table->enum('is_invoiced', ['0', '1'])->default('0')->comment('0 => NO, 1 => YES')
                ->after('webhook_response');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('daily_iconic_orders', function (Blueprint $table) {
            //
        });
    }
}
