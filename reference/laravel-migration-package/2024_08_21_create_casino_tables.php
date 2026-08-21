<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCasinoTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Games catalog
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('provider')->default('mzizibet'); // 'mzizibet' or third-party
            $table->text('description')->nullable();
            $table->text('rules')->nullable();
            $table->decimal('min_bet', 10, 2)->default(1.00);
            $table->decimal('max_bet', 10, 2)->default(10000.00);
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->string('badge')->nullable(); // 'HOT', 'POPULAR', etc.
            $table->string('image_url')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->timestamps();
            
            $table->index('is_active');
            $table->index('display_order');
            $table->foreign('category_id')->references('id')->on('game_categories');
        });

        // Game categories
        Schema::create('game_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->integer('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index('is_active');
        });

        // HTTP/API game rounds (Dice, Roulette, Mines, Slots)
        Schema::create('casino_rounds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('game_id');
            $table->decimal('stake', 10, 2);
            $table->decimal('payout', 10, 2)->default(0);
            $table->enum('status', ['pending', 'settled', 'void'])->default('pending');
            $table->string('provider_round_id')->unique(); // Round ID from game engine
            $table->json('provider_result')->nullable(); // Seeds, results, etc.
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            
            $table->index('user_id');
            $table->index('game_id');
            $table->index('status');
            $table->index('created_at');
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('game_id')->references('id')->on('games');
        });

        // Real-time game rounds (Crash, JetX, Aviator, etc.)
        Schema::create('real_time_game_rounds', function (Blueprint $table) {
            $table->id();
            $table->string('game_slug'); // 'jetx', 'crash', 'aviator', etc.
            $table->string('provider_round_id')->unique();
            $table->float('crash_point')->nullable(); // Crash/JetX outcome
            $table->string('server_seed'); // For provably fair
            $table->enum('status', ['betting', 'playing', 'crashed', 'ended'])->default('betting');
            $table->timestamp('betting_ends_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('crashed_at')->nullable();
            $table->timestamps();
            
            $table->index('game_slug');
            $table->index('status');
            $table->index('created_at');
        });

        // Real-time game bets (player bets in Crash/JetX/etc.)
        Schema::create('real_time_game_bets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('round_id');
            $table->unsignedBigInteger('user_id');
            $table->decimal('bet_amount', 10, 2);
            $table->decimal('payout', 10, 2)->nullable();
            $table->float('cashout_multiplier')->nullable(); // Multiplier at which player cashed out
            $table->enum('status', ['active', 'cashed_out', 'lost', 'expired'])->default('active');
            $table->timestamps();
            
            $table->index('round_id');
            $table->index('user_id');
            $table->index('status');
            $table->foreign('round_id')->references('id')->on('real_time_game_rounds')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        // Wallet transactions (if not already in your app)
        if (!Schema::hasTable('transactions')) {
            Schema::create('transactions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('wallet_id');
                $table->enum('type', ['deposit', 'withdrawal', 'stake', 'payout', 'reversal', 'bonus', 'refund'])->default('deposit');
                $table->decimal('amount', 10, 2);
                $table->decimal('balance_after', 10, 2);
                $table->string('reference')->nullable();
                $table->enum('status', ['completed', 'pending', 'failed'])->default('completed');
                $table->timestamps();
                
                $table->index('wallet_id');
                $table->index('type');
                $table->index('created_at');
                $table->foreign('wallet_id')->references('id')->on('wallets')->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('real_time_game_bets');
        Schema::dropIfExists('real_time_game_rounds');
        Schema::dropIfExists('casino_rounds');
        Schema::dropIfExists('games');
        Schema::dropIfExists('game_categories');
        Schema::dropIfExists('transactions');
    }
}
