# Laravel Game Migration - Implementation Guide

## Overview

This guide walks you through integrating the Flask games into your Laravel casino application. The migration is split into manageable phases.

## File Structure

### New PHP Classes (Copy to Your App)

```
app/
├── Http/
│   └── Controllers/
│       ├── CasinoGamesController.php      (NEW) HTTP/API games (init-round, settle-round)
│       ├── JetXController.php             (NEW) Real-time JetX game
│       ├── CrashController.php            (NEW) Real-time Crash game (similar to JetX)
│       ├── AviatorMziziController.php     (NEW) Aviator game
│       ├── HiLoCardController.php         (NEW) Hi-Lo card game
│       └── PlinkoMziziController.php      (NEW) Plinko game
├── Services/
│   └── GameEngineService.php              (NEW) Seed generation, payout logic
├── Game.php                               (NEW/UPDATE) Game catalog model
├── CasinoRound.php                        (NEW) HTTP/API game rounds
├── RealTimeGameRound.php                  (NEW) Real-time game rounds
└── RealTimeGameBet.php                    (NEW) Real-time game bets
```

### Database

```
database/
└── migrations/
    └── 2024_08_21_create_casino_tables.php  (NEW) All necessary tables
```

### Routes

```
routes/
└── api.php                                (UPDATE) Add casino game routes
```

## Phase 1: Foundation Setup (2-3 hours)

### 1.1 Copy Files to Your Project

```bash
# Copy services
cp GameEngineService.php app/Services/

# Copy models
cp Game.php app/
cp CasinoRound.php app/
cp RealTimeGameModels.php app/  # Extracts to RealTimeGameRound & RealTimeGameBet

# Copy controllers
cp CasinoGamesController.php app/Http/Controllers/
```

### 1.2 Create Database Tables

```bash
# Copy migration
cp 2024_08_21_create_casino_tables.php database/migrations/

# Run migration
php artisan migrate
```

### 1.3 Update Routes

Add this to `routes/api.php`:

```php
// Casino Games API (HTTP/API pattern: init-round → play → settle-round)
Route::middleware('auth:sanctum')->prefix('casino')->group(function () {
    Route::post('/init-round', 'CasinoGamesController@initRound');
    Route::post('/settle-round', 'CasinoGamesController@settleRound');
    Route::get('/balance', 'CasinoGamesController@getBalance');
    Route::get('/game-seeds/{roundId}', 'CasinoGamesController@getGameSeeds');
    Route::get('/round-history', 'CasinoGamesController@roundHistory');
});

// Real-time Games
Route::middleware('auth:sanctum')->prefix('games')->group(function () {
    Route::prefix('jetx')->group(function () {
        Route::get('/state', 'JetXController@getGameState');
        Route::post('/place-bet', 'JetXController@placeBet');
        Route::post('/cashout', 'JetXController@cashout');
        Route::get('/history', 'JetXController@history');
    });
});
```

### 1.4 Seed Initial Game Catalog

Create a seeder file `database/seeders/GameCatalogSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Game;
use App\GameCategory;
use Illuminate\Database\Seeder;

class GameCatalogSeeder extends Seeder
{
    public function run()
    {
        // Create categories
        $classic = GameCategory::firstOrCreate(['slug' => 'classic'], [
            'name' => 'Classic Games',
            'display_order' => 1,
        ]);

        $crash = GameCategory::firstOrCreate(['slug' => 'crash'], [
            'name' => 'Crash Games',
            'display_order' => 2,
        ]);

        // HTTP/API games
        Game::firstOrCreate(['slug' => 'dice'], [
            'name' => 'Dice',
            'category_id' => $classic->id,
            'provider' => 'mzizibet',
            'min_bet' => 1.00,
            'max_bet' => 10000.00,
            'is_active' => true,
            'display_order' => 1,
            'badge' => 'POPULAR',
        ]);

        Game::firstOrCreate(['slug' => 'european-roulette'], [
            'name' => 'European Roulette',
            'category_id' => $classic->id,
            'provider' => 'mzizibet',
            'min_bet' => 1.00,
            'max_bet' => 10000.00,
            'is_active' => true,
            'display_order' => 2,
        ]);

        Game::firstOrCreate(['slug' => 'mines'], [
            'name' => 'Mines',
            'category_id' => $classic->id,
            'provider' => 'mzizibet',
            'min_bet' => 1.00,
            'max_bet' => 10000.00,
            'is_active' => true,
            'display_order' => 3,
            'badge' => 'HOT',
        ]);

        Game::firstOrCreate(['slug' => 'slots'], [
            'name' => 'Slots',
            'category_id' => $classic->id,
            'provider' => 'mzizibet',
            'min_bet' => 1.00,
            'max_bet' => 10000.00,
            'is_active' => true,
            'display_order' => 4,
        ]);

        // Real-time games
        Game::firstOrCreate(['slug' => 'jetx'], [
            'name' => 'JetX',
            'category_id' => $crash->id,
            'provider' => 'mzizibet',
            'min_bet' => 10.00,
            'max_bet' => 50000.00,
            'is_active' => true,
            'display_order' => 1,
            'badge' => 'HOT',
        ]);

        Game::firstOrCreate(['slug' => 'mzizicrash'], [
            'name' => 'Crash',
            'category_id' => $crash->id,
            'provider' => 'mzizibet',
            'min_bet' => 10.00,
            'max_bet' => 50000.00,
            'is_active' => true,
            'display_order' => 2,
        ]);

        Game::firstOrCreate(['slug' => 'aviatormzizi'], [
            'name' => 'Aviator',
            'category_id' => $crash->id,
            'provider' => 'mzizibet',
            'min_bet' => 10.00,
            'max_bet' => 50000.00,
            'is_active' => true,
            'display_order' => 3,
        ]);

        Game::firstOrCreate(['slug' => 'hilocard'], [
            'name' => 'Hi-Lo Card',
            'category_id' => $classic->id,
            'provider' => 'mzizibet',
            'min_bet' => 1.00,
            'max_bet' => 10000.00,
            'is_active' => true,
            'display_order' => 5,
        ]);

        Game::firstOrCreate(['slug' => 'plinkomzizi'], [
            'name' => 'Plinko',
            'category_id' => $classic->id,
            'provider' => 'mzizibet',
            'min_bet' => 1.00,
            'max_bet' => 10000.00,
            'is_active' => true,
            'display_order' => 6,
            'badge' => 'POPULAR',
        ]);
    }
}
```

Run it:
```bash
php artisan db:seed --class=GameCatalogSeeder
```

### 1.5 Test Basic Endpoints

```bash
# Get balance
curl -X GET http://localhost:8000/api/casino/balance \
  -H "Authorization: Bearer YOUR_TOKEN"

# Initialize a round
curl -X POST http://localhost:8000/api/casino/init-round \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "game_id": 1,
    "stake": 100,
    "client_seed": "test123"
  }'
```

## Phase 2: Copy Frontend Assets

The Flask version has game UI in `mzizibet-main/app/static/`. You'll need:

### Games that Need Frontend JS:
- Dice (template: `games/dice.html`)
- European Roulette (template: `games/european-roulette.html`)
- Mines (template: `games/mines.html`)
- Slots (template: `games/slots.html`)
- JetX (template: `games/jetx.html`)

### Action Items:

1. Copy Flask templates to Laravel:
```bash
cp mzizibet-main/app/templates/games/*.html \
   your-laravel/resources/views/games/
```

2. Copy game assets (JS, CSS, images):
```bash
cp -r mzizibet-main/app/static/css/games \
   your-laravel/public/css/

cp -r mzizibet-main/app/static/js/games \
   your-laravel/public/js/

cp -r mzizibet-main/app/static/img/games \
   your-laravel/public/img/
```

3. Update template references:
   - Change `{{ url_for() }}` to `{{ route() }}`
   - Change `{{ current_user }}` to `{{ Auth::user() }}`
   - Update API endpoints from Flask paths to Laravel paths

## Phase 3: Implement Real-Time Game Controllers

The JetX controller is a template. Create similar controllers for:

### Crash (CrashController.php)
Similar to JetX but with different game mechanics:
- Same crash point generation
- Same betting/cashout flow
- Different visual representation

### Aviator (AviatorMziziController.php)
Flight-themed crash variant

### Hi-Lo Card (HiLoCardController.php)
Card prediction game:
- Different API (predict endpoint instead of place-bet)
- Multiple prediction rounds per game

### Plinko (PlinkoMziziController.php)
Ball drop game:
- Deterministic path based on seeds
- Different payout calculation

## Phase 4: Testing Checklist

### Unit Tests
- [ ] GameEngineService seed generation (compare with Flask)
- [ ] Payout calculation for each game type
- [ ] Decimal/Decimal arithmetic (no floating point errors)

### Integration Tests
- [ ] init-round → settle-round flow (wallet consistency)
- [ ] Multiple concurrent bets
- [ ] Edge cases (zero payout, max multiplier, etc.)

### Load Tests
- [ ] Real-time game under 100 concurrent players
- [ ] API rate limiting
- [ ] Database connection pool

## Troubleshooting

### "Round not found" or "Game mismatch"
- Verify game_id exists in `games` table
- Check that provider_round_id is unique

### Wallet balance goes negative
- Ensure database transactions are atomic
- Use `DB::beginTransaction()` and `DB::commit()`

### Seeds don't match Flask version
- Compare GameEngineService methods with Flask `game_engine.py`
- Check hash algorithm (SHA256)
- Verify random byte generation

### Real-time games timeout
- Increase Cache TTL (5 minutes might be too short)
- Consider using Redis with persistent connections
- Implement client-side retry logic

## Next Steps

1. **Immediate**: Run Phase 1 setup (foundation)
2. **Short-term**: Implement frontend assets (Phase 2)
3. **Medium-term**: Wire up real-time controllers (Phase 3)
4. **Long-term**: Add WebSocket support (Pusher/Laravel Echo)

## Architecture Notes

### Why This Design?

1. **GameEngineService**: Centralized game logic (seeds, payouts)
   - Ensures consistency across all games
   - Makes testing easier
   - Facilitates future provider integrations

2. **Separate Models for Game Types**:
   - `CasinoRound`: HTTP/API games (stateless)
   - `RealTimeGameRound/Bet`: Real-time games (stateful)
   - Keeps database schema focused

3. **Cache for Real-Time State**:
   - Faster than database polling
   - Atomic round state (not split across DB updates)
   - Easy to expire old rounds

### Security Considerations

1. **Wallet Transactions**:
   - Always use DB transactions
   - Log every debit/credit
   - Validate stake before debit

2. **Payout Validation**:
   - Server-side calculation only
   - Max 100x multiplier hard limit
   - Client result verified against seeds

3. **Provably Fair**:
   - Server seed hashed initially (sent to client)
   - Client seed chosen by user
   - Final result = hash(server_seed + client_seed)
   - User can verify after settlement

## Migration Path from Existing Laravel Casino

If your Laravel casino already has:
- User & Wallet models
- CasinoRound/GameBet tables

You'll need to:

1. Ensure Game & GameCategory tables exist
2. Migrate existing game data to new schema
3. Implement GameEngineService with existing game logic
4. Gradually switch routes to new controllers

Example migration:
```php
// Old route: POST /play/dice
Route::post('/play/dice', 'OldDiceController@play');

// New route: POST /api/casino/init-round + settle-round
// Update frontend to use new flow
```

## Support & Debugging

Enable query logging in `.env`:
```
DB_LOG_QUERIES=true
```

Check logs:
```bash
tail -f storage/logs/laravel.log
```

Monitor Redis (if using cache):
```bash
redis-cli
KEYS jetx*  # See all JetX game state
```

---

**Last Updated**: August 21, 2024
**Version**: 1.0 (Foundation Release)
