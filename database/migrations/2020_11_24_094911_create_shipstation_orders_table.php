<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateShipstationOrdersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('shipstation_orders', function (Blueprint $table) {
            $table->id();
            $table->integer('iconic_order_number')->length('11');
            $table->integer('iconic_order_id')->length('11');
            $table->enum('is_uploaded', ['0', '1'])->default('0')->comment('0 => NO, 1 => YES');
            $table->string('tracking_no',256)->nullable();
            $table->enum('tracking_no_updated', ['0', '1'])->default('0')->comment('0 => NO, 1 => YES');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('shipatation_orders');
    }
}
