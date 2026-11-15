<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('karma_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('map_id')->constrained('maps')->cascadeOnDelete();
            $table->unsignedBigInteger('player_id');
            $table->foreign('player_id')->references('id')->on('players')->cascadeOnDelete();
            $table->tinyInteger('score');   // -3 .. +3, never 0
            $table->timestamps();
            $table->unique(['map_id', 'player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('karma_votes');
    }
};
