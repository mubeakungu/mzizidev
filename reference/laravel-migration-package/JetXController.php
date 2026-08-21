<?php

namespace App\Http\Controllers;

use App\Game;
use App\RealTimeGameRound;
use App\RealTimeGameBet;
use App\Services\GameEngineService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Exception;
use Decimal\Decimal;

/**
 * JetXController - Real-time Crash/JetX game
 * 
 * Architecture:
 * - Game state stored in Cache (Redis) for multi-player sync
 * - Each round has live_multiplier that increases in real-time
 * - Players can cash-out at any multiplier before crash
 * - Server randomly crashes at predetermined multiplier (provably fair)
 * 
 * Polling Flow:
 * 1. Client calls /games/jetx/state → get current round state
 * 2. If betting period open, client POST /games/jetx/place-bet
 * 3. While game in progress, client polls /games/jetx/state for live_multiplier
 * 4. Client clicks cashout → POST /games/jetx/cashout with current multiplier
 * 5. Round settles when crash_point is reached or all players cash out
 */
class JetXController extends Controller
{
    const GAME_SLUG = 'jetx';
    const BETTING_DURATION = 5;      // seconds players can place bets
    const GAME_DURATION = 60;         // max seconds game can run
    const MIN_MULTIPLIER = 1.0;
    const MAX_MULTIPLIER = 500.0;
    const MULTIPLIER_SPEED = 0.01;   // increase per 100ms = 0.1x per second
    const HOUSE_EDGE = 0.99;         // 1% house edge on payouts

    /**
     * Get current game state
     * 
     * GET /api/games/jetx/state
     * 
     * Returns:
     * {
     *   "round_id": "...",
     *   "status": "betting|playing|crashed",
     *   "crash_point": 2.50,
     *   "live_multiplier": 1.82,
     *   "betting_ends_at": "2024-08-21T10:30:05Z",
     *   "started_at": "2024-08-21T10:30:00Z",
     *   "elapsed_ms": 4200,
     *   "you_in_round": true,
     *   "your_bet": 100,
     *   "your_multiplier": null,
     *   "player_count": 23,
     *   "next_round_starts": "2024-08-21T10:30:10Z"
     * }
     */
    public function getGameState(Request $request)
    {
        $user = Auth::user();
        
        // Get current round from cache
        $currentRound = Cache::get('jetx:current_round', []);
        $cacheKey = "jetx:round:{$currentRound['round_id'] ?? 'none'}";
        $roundData = Cache::get($cacheKey, []);

        // If no active round, start new one
        if (empty($currentRound) || empty($roundData)) {
            return $this->startNewRound();
        }

        // Check if round should crash
        $startedAt = $roundData['started_at'];
        $elapsedSeconds = now()->diffInSeconds($startedAt);
        $crashPoint = $roundData['crash_point'];
        
        // Calculate current multiplier based on elapsed time
        $liveMultiplier = $this->calculateMultiplier($elapsedSeconds);
        
        if ($liveMultiplier >= $crashPoint) {
            // Round crashed!
            return $this->settleRound($currentRound, $crashPoint, 'crashed');
        }

        // Get user's bet in this round
        $userBet = RealTimeGameBet::where('round_id', $currentRound['id'])
            ->where('user_id', $user->id)
            ->first();

        $playerCount = RealTimeGameBet::where('round_id', $currentRound['id'])
            ->distinct('user_id')
            ->count();

        return response()->json([
            'round_id' => $currentRound['round_id'],
            'status' => $roundData['status'],
            'crash_point' => $crashPoint,
            'live_multiplier' => $liveMultiplier,
            'betting_ends_at' => $roundData['betting_ends_at'],
            'started_at' => $roundData['started_at'],
            'elapsed_ms' => $elapsedSeconds * 1000,
            'you_in_round' => $userBet !== null,
            'your_bet' => $userBet?->bet_amount ?? null,
            'your_multiplier' => $userBet?->cashout_multiplier ?? null,
            'player_count' => $playerCount,
        ], 200);
    }

    /**
     * Place a bet on the current round
     * 
     * POST /api/games/jetx/place-bet
     * {
     *   "bet_amount": 100
     * }
     * 
     * Returns:
     * {
     *   "success": true,
     *   "bet_id": "...",
     *   "bet_amount": 100,
     *   "round_id": "..."
     * }
     */
    public function placeBet(Request $request)
    {
        $request->validate([
            'bet_amount' => 'required|numeric|min:10|max:50000',
        ]);

        $user = Auth::user();
        $betAmount = new Decimal($request->bet_amount);

        // Get current round
        $currentRound = Cache::get('jetx:current_round', []);
        if (empty($currentRound)) {
            return response()->json(['error' => 'No active round'], 400);
        }

        $cacheKey = "jetx:round:{$currentRound['round_id']}";
        $roundData = Cache::get($cacheKey, []);

        // Check betting window
        if ($roundData['status'] !== 'betting') {
            return response()->json(['error' => 'Betting period has closed'], 400);
        }

        // Check user hasn't already bet
        $existingBet = RealTimeGameBet::where('round_id', $currentRound['id'])
            ->where('user_id', $user->id)
            ->first();

        if ($existingBet) {
            return response()->json(['error' => 'You already have a bet this round'], 400);
        }

        // Check wallet balance
        $wallet = $user->wallet;
        if (!$wallet || (new Decimal($wallet->balance))->isLessThan($betAmount)) {
            return response()->json(['error' => 'Insufficient balance'], 402);
        }

        DB::beginTransaction();

        try {
            // Debit wallet
            $wallet->balance -= (float)$betAmount;
            $wallet->save();

            // Create bet record
            $bet = new RealTimeGameBet();
            $bet->round_id = $currentRound['id'];
            $bet->user_id = $user->id;
            $bet->bet_amount = (float)$betAmount;
            $bet->status = 'active';
            $bet->save();

            // Track this player in the round
            $playersKey = "jetx:round:{$currentRound['round_id']}:players";
            $players = Cache::get($playersKey, []);
            $players[$user->id] = [
                'bet_id' => $bet->id,
                'bet_amount' => (float)$betAmount,
                'status' => 'active',
            ];
            Cache::put($playersKey, $players, now()->addMinutes(5));

            DB::commit();

            return response()->json([
                'success' => true,
                'bet_id' => $bet->id,
                'bet_amount' => (float)$betAmount,
                'round_id' => $currentRound['round_id'],
            ], 200);

        } catch (Exception $e) {
            DB::rollback();
            \Log::error('JetX placeBet error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to place bet'], 500);
        }
    }

    /**
     * Cash out current bet
     * 
     * POST /api/games/jetx/cashout
     * {
     *   "round_id": "...",
     *   "multiplier": 2.45
     * }
     * 
     * Returns:
     * {
     *   "success": true,
     *   "payout": 245,
     *   "profit": 145,
     *   "balance": 895
     * }
     */
    public function cashout(Request $request)
    {
        $request->validate([
            'round_id' => 'required|string',
            'multiplier' => 'required|numeric|min:1.0|max:500.0',
        ]);

        $user = Auth::user();
        $cashoutMultiplier = $request->multiplier;

        // Get the bet
        $currentRound = Cache::get('jetx:current_round', []);
        $bet = RealTimeGameBet::where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (!$bet) {
            return response()->json(['error' => 'No active bet found'], 404);
        }

        // Get round data to verify crash point
        $cacheKey = "jetx:round:{$request->round_id}";
        $roundData = Cache::get($cacheKey, []);

        if ($roundData['status'] === 'crashed' && $cashoutMultiplier > $roundData['crash_point']) {
            return response()->json(['error' => 'Bet already lost in crash'], 400);
        }

        DB::beginTransaction();

        try {
            // Calculate payout with house edge
            $betAmount = new Decimal($bet->bet_amount);
            $multiplier = new Decimal($cashoutMultiplier);
            $payout = $betAmount->multiply($multiplier)->multiply(self::HOUSE_EDGE);

            // Update bet
            $bet->status = 'cashed_out';
            $bet->cashout_multiplier = (float)$cashoutMultiplier;
            $bet->payout = (float)$payout;
            $bet->save();

            // Credit wallet
            $wallet = $user->wallet;
            $wallet->balance += (float)$payout;
            $wallet->save();

            DB::commit();

            $profit = $payout->minus($betAmount);

            return response()->json([
                'success' => true,
                'payout' => (float)$payout,
                'profit' => (float)$profit,
                'balance' => (float)$wallet->balance,
            ], 200);

        } catch (Exception $e) {
            DB::rollback();
            \Log::error('JetX cashout error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to process cashout'], 500);
        }
    }

    /**
     * Get user's JetX history
     * 
     * GET /api/games/jetx/history?limit=20&offset=0
     */
    public function history(Request $request)
    {
        $user = Auth::user();
        $limit = $request->query('limit', 20);
        $offset = $request->query('offset', 0);

        $bets = RealTimeGameBet::where('user_id', $user->id)
            ->whereIn('status', ['cashed_out', 'lost'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->offset($offset)
            ->get();

        $history = $bets->map(function ($bet) {
            $betAmount = new Decimal($bet->bet_amount);
            $payout = new Decimal($bet->payout ?? 0);
            $profit = $payout->minus($betAmount);

            return [
                'round_id' => $bet->round_id,
                'bet_amount' => (float)$betAmount,
                'cashout_multiplier' => $bet->cashout_multiplier,
                'payout' => (float)$payout,
                'profit' => (float)$profit,
                'status' => $bet->status,
                'created_at' => $bet->created_at->toIso8601String(),
            ];
        });

        return response()->json([
            'history' => $history,
            'count' => count($history),
        ], 200);
    }

    /**
     * Start a new round
     * 
     * Called automatically when previous round ends
     */
    private function startNewRound()
    {
        // Generate seeds for provably fair
        $roundId = GameEngineService::generateRoundId();
        $serverSeed = GameEngineService::generateServerSeed();
        $crashPoint = GameEngineService::generateCrashMultiplier($serverSeed, '', 1);

        // Create round record in DB
        $roundRecord = new RealTimeGameRound();
        $roundRecord->game_slug = self::GAME_SLUG;
        $roundRecord->provider_round_id = $roundId;
        $roundRecord->crash_point = $crashPoint;
        $roundRecord->server_seed = $serverSeed;
        $roundRecord->status = 'betting';
        $roundRecord->save();

        // Store round state in cache
        $bettingEndsAt = now()->addSeconds(self::BETTING_DURATION);
        $startedAt = $bettingEndsAt->clone();

        $roundData = [
            'round_id' => $roundId,
            'database_id' => $roundRecord->id,
            'crash_point' => $crashPoint,
            'server_seed' => $serverSeed,
            'status' => 'betting',
            'betting_ends_at' => $bettingEndsAt->toIso8601String(),
            'started_at' => $startedAt->toIso8601String(),
        ];

        Cache::put("jetx:current_round", [
            'id' => $roundRecord->id,
            'round_id' => $roundId,
        ], now()->addMinutes(5));

        Cache::put("jetx:round:{$roundId}", $roundData, now()->addMinutes(5));

        return response()->json([
            'round_id' => $roundId,
            'status' => 'betting',
            'betting_ends_at' => $bettingEndsAt->toIso8601String(),
            'you_in_round' => false,
            'player_count' => 0,
        ], 200);
    }

    /**
     * Calculate current multiplier based on elapsed time
     * Multiplier increases smoothly during game
     */
    private function calculateMultiplier($elapsedSeconds)
    {
        $multiplier = self::MIN_MULTIPLIER + ($elapsedSeconds * 0.1);
        return min($multiplier, self::MAX_MULTIPLIER);
    }

    /**
     * Settle a round (crash or end)
     */
    private function settleRound($roundInfo, $crashPoint, $status)
    {
        DB::beginTransaction();

        try {
            // Update round record
            $round = RealTimeGameRound::find($roundInfo['id']);
            $round->status = $status;
            $round->crashed_at = now();
            $round->save();

            // Process all bets in this round
            $bets = RealTimeGameBet::where('round_id', $roundInfo['id'])->get();

            foreach ($bets as $bet) {
                if ($bet->status === 'active') {
                    // Bet lost (didn't cash out before crash)
                    $bet->status = 'lost';
                    $bet->payout = 0;
                    $bet->save();
                }
            }

            DB::commit();

            // Clear round from cache, queue next round to start in 5 seconds
            Cache::forget("jetx:current_round");
            Cache::forget("jetx:round:{$roundInfo['round_id']}");

            return response()->json([
                'round_id' => $roundInfo['round_id'],
                'status' => 'crashed',
                'crash_point' => $crashPoint,
            ], 200);

        } catch (Exception $e) {
            DB::rollback();
            \Log::error('JetX settleRound error: ' . $e->getMessage());
            throw $e;
        }
    }
}
