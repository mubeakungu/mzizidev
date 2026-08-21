# Flask to Laravel Game Migration Plan

## Overview
Migrate game implementations from Flask (Python) to Laravel (PHP), converting 7 games:
- **Real-time games** (2): mzizicrash, jetx (requires WebSocket handling)
- **HTTP/API games** (5): aviatormzizi, hilocard, plinkomzizi, + existing dice/roulette updates

## Architecture Changes

### Current Flask Structure
```
Flask (Python)
├── app/routes/
│   ├── casino_games.py (HTTP API: init-round, settle-round)
│   ├── mzizicrash_blueprint.py (Real-time crash)
│   ├── jetx_blueprint.py (Real-time JetX)
│   ├── aviatormzizi_blueprint.py (Redirect)
│   ├── hilocard_blueprint.py (Redirect)
│   └── plinkomzizi_blueprint.py (Redirect)
├── app/models/crash.py (CrashGame, CrashBet)
├── app/models/casino.py (Game, CasinoRound, GameCategory)
└── app/game_engine.py (GameEngine, PayoutCalculator)
```

### Target Laravel Structure
```
Laravel (PHP)
├── app/Http/Controllers/
│   ├── CasinoGamesController.php (API: init-round, settle-round)
│   ├── JetXController.php (Real-time JetX)
│   ├── AviatorMziziController.php (Aviator variant)
│   ├── HiLoCardController.php (Hi-Lo variant)
│   └── PlinkoMziziController.php (Plinko variant)
├── app/
│   ├── Game.php (Model)
│   ├── CasinoRound.php (Model)
│   ├── GameEngineService.php (Game logic service)
│   └── ...
└── routes/
    ├── api.php (API routes)
    └── web.php (Game pages)
```

## Game Types

### Type A: HTTP/API Games (Dice, Roulette, Mines, Slots)
**Pattern**: POST → init-round (debit) → client plays → settle-round (credit/reversal)

**Controllers to create/update**:
- ✅ CasinoGamesController@initRound
- ✅ CasinoGamesController@settleRound
- ✅ CasinoGamesController@getBalance
- ✅ CasinoGamesController@getGameSeeds
- ✅ CasinoGamesController@roundHistory

**Models to create/update**:
- ✅ Game (catalog)
- ✅ CasinoRound (game state + results)
- ✅ GameEngineService (seed generation, payout validation)

### Type B: Real-Time Games with WebSockets (Crash, JetX)
**Pattern**: WebSocket connection → live multiplier update → cash-out tracking

**Controllers to create**:
- 🟡 JetXController@show (page render)
- 🟡 WebSocket handlers (if using Laravel Echo/Pusher)
- 🟡 GameStateManager (in-memory state or Redis)

**Challenge**: Flask uses threading + custom game loop. Laravel alternatives:
1. **Option 1**: Use Laravel Broadcasting + Pusher/Redis (production-ready)
2. **Option 2**: Use WebSocket library (php-socket-io) + custom loop
3. **Option 3**: Simplify to HTTP-based (polling) for MVP

Recommendation: Start with **Option 3 (polling)**, upgrade to Option 1 later.

### Type C: Redirect Games (Aviator, Hi-Lo, Plinko)
**Pattern**: Show game page that loads via iframe/embed

**Controllers to create**:
- ✅ AviatorMziziController@show
- ✅ HiLoCardController@show
- ✅ PlinkoMziziController@show

## Migration Phases

### Phase 1: Foundation (Week 1)
- [ ] Create GameEngineService (seed generation, payout validation)
- [ ] Create/update Game & CasinoRound models
- [ ] Create CasinoGamesController with init-round/settle-round endpoints
- [ ] Create simple game pages (dice, roulette, etc.)

### Phase 2: Real-Time Game Framework (Week 2)
- [ ] Set up WebSocket infrastructure (choose option above)
- [ ] Create base game controller/service for real-time games
- [ ] Implement Crash game logic
- [ ] Implement JetX game logic

### Phase 3: Specialized Games (Week 3)
- [ ] Create Aviator controller/page
- [ ] Create Hi-Lo Card controller/page
- [ ] Create Plinko controller/page
- [ ] Integrate with frontend assets from Flask version

### Phase 4: Frontend & Testing (Week 4)
- [ ] Copy/adapt frontend assets (JS, CSS, templates)
- [ ] Integration testing (user flow, wallet debits/credits)
- [ ] Load testing (real-time games)
- [ ] Security audit (seed generation, payout validation)

## Key Implementation Details

### GameEngineService Methods
```php
// Deterministic seed generation (provably fair)
generateServerSeed(): string
generateClientNonce(): string
generateRoundId(): string

// Payout calculation & validation
calculatePayout(game: Game, result: array, stake: Decimal): Decimal
validatePayout(payout: Decimal, stake: Decimal): bool

// Hash-based result generation (reproducible)
generateDiceResult(serverSeed: string, clientSeed: string): int
generateCrashMultiplier(serverSeed: string, clientSeed: string): float
generateMinesPattern(serverSeed: string, clientSeed: string): array
```

### Database Schema Updates
```sql
-- If not already in Laravel version:
CREATE TABLE IF NOT EXISTS games (
    id BIGINT PRIMARY KEY,
    name VARCHAR(255),
    slug VARCHAR(255) UNIQUE,
    category_id BIGINT,
    provider VARCHAR(100),
    is_active BOOLEAN DEFAULT true,
    display_order INT,
    badge VARCHAR(50),
    created_at TIMESTAMP
);

CREATE TABLE IF NOT EXISTS casino_rounds (
    id BIGINT PRIMARY KEY,
    user_id BIGINT,
    game_id BIGINT,
    stake DECIMAL(15,2),
    payout DECIMAL(15,2),
    status VARCHAR(50),
    provider_round_id VARCHAR(255),
    provider_result JSON,
    settled_at TIMESTAMP,
    created_at TIMESTAMP
);
```

## API Endpoints to Create

```
POST   /api/casino/init-round          → CasinoGamesController@initRound
POST   /api/casino/settle-round        → CasinoGamesController@settleRound
GET    /api/casino/balance             → CasinoGamesController@getBalance
GET    /api/casino/seeds/{roundId}     → CasinoGamesController@getGameSeeds
GET    /api/casino/history             → CasinoGamesController@roundHistory

GET    /casino/play/dice               → DiceController@show (or via casino_play view)
GET    /casino/play/roulette           → RouletteController@show
GET    /casino/play/mines              → MinesController@show
GET    /casino/play/slots              → SlotsController@show

GET    /jetx                           → JetXController@show (real-time)
GET    /aviator-mzizi                  → AviatorMziziController@show
GET    /hi-lo-card                     → HiLoCardController@show
GET    /plinko-mzizi                   → PlinkoMziziController@show
```

## Risk Assessment & Mitigations

| Risk | Severity | Mitigation |
|------|----------|-----------|
| WebSocket migration complexity | HIGH | Start with polling API, upgrade later |
| Seed generation parity | HIGH | Extensive test suite comparing Flask vs Laravel |
| Wallet consistency | CRITICAL | Database transactions, idempotent API design |
| Performance (real-time) | MEDIUM | Load test, consider Redis for game state |
| Frontend compatibility | MEDIUM | Reuse Flask frontend JS, minimal changes |

## Testing Strategy

### Unit Tests
- [ ] GameEngineService seed generation & payout logic
- [ ] Each game's result calculation (dice rolls, crash point, etc.)

### Integration Tests
- [ ] init-round → settle-round flow for each game
- [ ] Wallet debit/credit and transaction logging
- [ ] Balance retrieval after bets

### Load Testing
- [ ] Real-time games under concurrent load
- [ ] WebSocket message throughput

## Rollout Strategy

1. Deploy Phase 1 (foundation) to staging
2. QA testing (2-3 days)
3. Deploy to production (with feature flags)
4. Enable games one at a time
5. Monitor wallet transactions, error rates
6. Collect feedback from users before full rollout

## Estimated Effort

| Phase | Task | Hours |
|-------|------|-------|
| 1 | GameEngineService + models | 16 |
| 1 | CasinoGamesController endpoints | 12 |
| 2 | WebSocket setup | 20 |
| 2 | Crash + JetX logic | 24 |
| 3 | Specialized games (3 × 8h) | 24 |
| 4 | Frontend integration | 16 |
| 4 | Testing & QA | 24 |
| **Total** | | **136 hours (~4 weeks)** |

---

**Next Step**: Start with Phase 1 (GameEngineService + CasinoGamesController).
