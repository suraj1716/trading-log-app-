<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_logs', function (Blueprint $table) {

            $table->string('regime')
                ->nullable()
                ->after('action');

            $table->string('mom_direction')
                ->nullable()
                ->after('regime');

            $table->decimal('trail_stop', 20, 8)
                ->nullable()
                ->after('mom_direction');

            $table->decimal('mom_peak_price', 20, 8)
                ->nullable()
                ->after('trail_stop');
        });
    }

    public function down(): void
    {
        Schema::table('trade_logs', function (Blueprint $table) {

            $table->dropColumn([
                'regime',
                'mom_direction',
                'trail_stop',
                'mom_peak_price',
            ]);
        });
    }
};
