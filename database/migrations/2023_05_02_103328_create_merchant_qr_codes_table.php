<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('merchant_qr_codes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('slug')->unique();
            $table->string('sender_type')->comment('User,Agent')->nullable();
            $table->string('receiver_type')->nullable();
            $table->decimal('amount', 28, 8)->nullable();
            $table->text('qr_code')->nullable();
            $table->string('url')->nullable();
            $table->timestamps();


            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('merchant_qr_codes');
    }
};
