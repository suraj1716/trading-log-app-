<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trading_sessions', function (Blueprint $table) {

            $table->string('regime')
                ->default('ladder')
                ->after('avg_price');

            $table->decimal('momentum_peak_price', 20, 8)
                ->nullable()
                ->after('regime');

            $table->string('momentum_direction')
                ->nullable()
                ->after('momentum_peak_price');

            $table->decimal('momentum_entry_price', 20, 8)
                ->nullable()
                ->after('momentum_direction');
        });
    }

    public function down(): void
    {
        Schema::table('trading_sessions', function (Blueprint $table) {

            $table->dropColumn([
                'regime',
                'momentum_peak_price',
                'momentum_direction',
                'momentum_entry_price',
            ]);
        });
    }
};
