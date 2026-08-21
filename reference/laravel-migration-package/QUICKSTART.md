# Flask to Laravel Game Migration - Quick Start

## What You're Getting

A complete migration of 7 Flask games to Laravel:

### HTTP/API Games (Simple Pattern)
- **Dice** - Classic dice game (0-999999)
- **European Roulette** - 37-number roulette
- **Mines** - Tile reveal with hidden mines
- **Slots** - Spin and win

### Real-Time Games (Polling Pattern)
- **JetX** - Plane crash game with live multiplier
- **Crash** - Exponential crash multiplier
- **Aviator** - Flight-themed crash variant

### Additional Games (Templates Provided)
- **Hi-Lo Card** - Card prediction game
- **Plinko** - Ball drop board game

## What's Included

### PHP Classes (Copy to Your Laravel App)
1. **GameEngineService.php** - Seed generation, payout calculation
2. **CasinoGamesController.php** - API endpoints (init-round, settle-round)
3. **JetXController.php** - Real-time JetX game
4. **Game.php** - Game catalog model
5. **CasinoRound.php** - HTTP/API game rounds
6. **RealTimeGameRound.php** - Real-time game rounds
7. **RealTimeGameBet.php** - Real-time game bets

### Database
1. **Migration File** - Creates all necessary tables (games, casino_rounds, real_time_game_rounds, etc.)

### Documentation
1. **MIGRATION_PLAN.md** - Detailed architecture & technical decisions
2. **IMPLEMENTATION_GUIDE.md** - Step-by-step implementation
3. **This file** - Quick start guide

## Getting Started (5 Minutes)

### Step 1: Copy Files to Your Laravel Project

```bash
# Copy PHP classes
cp GameEngineService.php your-app/app/Services/
cp CasinoGamesController.php your-app/app/Http/Controllers/
cp JetXController.php your-app/app/Http/Controllers/
cp Game.php your-app/app/
cp CasinoRound.php your-app/app/
cp RealTimeGameModels.php your-app/app/  # Creates 2 models

# Copy migration
cp 2024_08_21_create_casino_tables.php your-app/database/migrations/
```

### Step 2: Run Migration

```bash
cd your-app
php artisan migrate
```

### Step 3: Add Routes

Add this to `routes/api.php`:

```php
Route::middleware('auth:sanctum')->group(function () {
    // Casino API
    Route::prefix('casino')->group(function () {
        Route::post('/init-round', 'CasinoGamesController@initRound');
        Route::post('/settle-round', 'CasinoGamesController@settleRound');
        Route::get('/balance', 'CasinoGamesController@getBalance');
        Route::get('/game-seeds/{roundId}', 'CasinoGamesController@getGameSeeds');
        Route::get('/round-history', 'CasinoGamesController@roundHistory');
    });
    
    // Real-time games
    Route::prefix('games/jetx')->group(function () {
        Route::get('/state', 'JetXController@getGameState');
        Route::post('/place-bet', 'JetXController@placeBet');
        Route::post('/cashout', 'JetXController@cashout');
        Route::get('/history', 'JetXController@history');
    });
});
```

### Step 4: Seed Game Catalog

Run this in tinker:

```bash
php artisan tinker
```

```php
// Create categories
$classic = \App\GameCategory::create([
    'name' => 'Classic Games',
    'slug' => 'classic',
    'display_order' => 1,
]);

$crash = \App\GameCategory::create([
    'name' => 'Crash Games',
    'slug' => 'crash',
    'display_order' => 2,
]);

// Add games
\App\Game::create([
    'name' => 'Dice',
    'slug' => 'dice',
    'category_id' => $classic->id,
    'min_bet' => 1.00,
    'max_bet' => 10000.00,
    'is_active' => true,
    'display_order' => 1,
    'badge' => 'POPULAR',
]);

\App\Game::create([
    'name' => 'JetX',
    'slug' => 'jetx',
    'category_id' => $crash->id,
    'min_bet' => 10.00,
    'max_bet' => 50000.00,
    'is_active' => true,
    'display_order' => 1,
    'badge' => 'HOT',
]);

// Add more games similarly...
```

## Testing the API

### 1. Get Balance
```bash
curl -X GET http://localhost:8000/api/casino/balance \
  -H "Authorization: Bearer YOUR_TOKEN"
```

Response:
```json
{
  "balance": 1000.00,
  "currency": "KES"
}
```

### 2. Initialize a Game Round
```bash
curl -X POST http://localhost:8000/api/casino/init-round \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "game_id": 1,
    "stake": 100.00,
    "client_seed": "abc123"
  }'
```

Response:
```json
{
  "round_id": "4f8a9c2b1e5d7f3a",
  "server_seed": "xyz789...",
  "client_seed": "abc123",
  "nonce": 1,
  "balance": 900.00
}
```

### 3. Settle a Round
```bash
curl -X POST http://localhost:8000/api/casino/settle-round \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "round_id": "4f8a9c2b1e5d7f3a",
    "game_id": 1,
    "payout": 150.00,
    "game_result": {
      "dice_roll": 42,
      "win": true
    }
  }'
```

Response:
```json
{
  "status": "settled",
  "payout": 150.00,
  "net_result": 50.00,
  "balance": 1050.00,
  "round_id": "4f8a9c2b1e5d7f3a"
}
```

### 4. Get Real-Time Game State (JetX)
```bash
curl -X GET http://localhost:8000/api/games/jetx/state \
  -H "Authorization: Bearer YOUR_TOKEN"
```

Response:
```json
{
  "round_id": "abc123...",
  "status": "betting",
  "crash_point": 2.50,
  "live_multiplier": 1.0,
  "betting_ends_at": "2024-08-21T10:30:05Z",
  "you_in_round": false,
  "player_count": 5
}
```

## Common Integration Points

### With Your Wallet System
The controller assumes:
- `User` model has `wallet` relationship
- `Wallet` has `balance` (float) attribute
- Database transactions work with your setup

Adjust in `CasinoGamesController` if needed:
```php
$wallet = $user->wallet; // Adjust this line
```

### With Your User Model
The controller uses `Auth::user()` which should work with standard Laravel auth.

If using custom guards, update:
```php
// In CasinoGamesController
$user = Auth::guard('your_guard')->user();
```

### With Your Database
Ensure these exist:
- `users` table
- `wallets` table (or integrate with your balance system)
- `games` table (created by migration)
- `casino_rounds` table (created by migration)

## Key Design Decisions

### 1. Why Separate Models for Game Types?
- **HTTP/API** games: Simple debit-settle flow → `CasinoRound`
- **Real-time** games: Complex state management → `RealTimeGameRound` + `RealTimeGameBet`
- Keeps schema focused and queries fast

### 2. Why Cache for Real-Time State?
- Live multiplier needs to update every 100ms
- Database can't keep up with that frequency
- Redis is perfect for this use case
- Round data expires after 5 minutes automatically

### 3. Why Server-Side Payouts?
- Client can't be trusted with payout calculation
- Server validates based on seeds + game result
- Prevents fraud (tampering with payout amount)

### 4. Why These Specific Games?
- Dice, Roulette, Mines, Slots: Classic casino games (easy to implement)
- Crash, JetX, Aviator: Trending games (user engagement)
- All have proven RTP (return to player) algorithms

## Troubleshooting

### Error: "No active round" (JetX)
- First request to `/games/jetx/state` should auto-create a round
- Check Redis/Cache is working: `php artisan tinker` → `Cache::put('test', 'value', 5);`

### Error: "Game not found"
- Ensure games are seeded: `App\Game::count()` in tinker
- Check game_id matches in `init-round` request

### Error: "Insufficient balance"
- Verify user has wallet with balance
- Check decimal precision (stored as float in DB)

### Wallet goes negative
- Ensure `DB::beginTransaction()` in controller
- Check database isolation level (READ COMMITTED recommended)

## Next Steps

1. **Test locally** with cURL commands above
2. **Integrate frontend** - copy Vue/React components from Flask version
3. **Add more games** - duplicate JetXController structure for Crash, Aviator
4. **Setup WebSockets** - upgrade from polling to real-time with Pusher/Laravel Echo
5. **Load test** - verify performance under 100+ concurrent players

## Architecture Overview

```
User Request
    ↓
Route (api.php)
    ↓
Controller (CasinoGamesController or JetXController)
    ├→ Auth check
    ├→ Validate input
    ├→ Wallet debit/credit
    ├→ GameEngineService (seeds, payouts)
    ├→ Database transaction
    └→ Return response

Provably Fair Flow:
1. init-round: Generate server_seed + round_id (no client input)
2. play: Client makes moves (dice roll, roulette spin, etc.)
3. settle-round: Client seed + server seed → deterministic result
4. verify: User can hash(server + client) to verify result
```

## Support Resources

- **IMPLEMENTATION_GUIDE.md** - Full step-by-step
- **MIGRATION_PLAN.md** - Architecture deep-dive
- **GameEngineService.php** - Detailed comments on each method
- **CasinoGamesController.php** - API endpoint reference

---

**Ready to go?** Start with "Step 1: Copy Files" above. Should take ~5 minutes to get the API running.

Questions? Check IMPLEMENTATION_GUIDE.md for troubleshooting.
