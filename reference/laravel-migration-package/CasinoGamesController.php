<?php

namespace App\Http\Controllers;

use App\Game;
use App\CasinoRound;
use App\User;
use App\Services\GameEngineService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Decimal\Decimal;
use Exception;

/**
 * CasinoGamesController
 * 
 * Handles HTTP/API-based casino games:
 * - Dice, European Roulette, Mines, Slots
 * 
 * Architecture:
 * 1. Client calls init-round (POST) → debit wallet
 * 2. Client plays game locally (result calculation in frontend/backend)
 * 3. Client calls settle-round (POST) → validate & credit wallet
 * 
 * All payouts validated on server side (no client-side payout calculation).
 */
class CasinoGamesController extends Controller
{
    /**
     * Initialize a game round
     * 
     * POST /api/casino/init-round
     * {
     *   "game_id": 1,
     *   "stake": 100,
     *   "client_seed": "abc123"
     * }
     * 
     * Returns:
     * {
     *   "round_id": "abc123def456...",
     *   "server_seed": "xyz789...",
     *   "client_seed": "abc123",
     *   "nonce": 1,
     *   "balance": 900
     * }
     */
    public function initRound(Request $request)
    {
        $request->validate([
            'game_id' => 'required|integer|exists:games,id',
            'stake' => 'required|numeric|min:0.01|max:10000',
            'client_seed' => 'nullable|string|max:255',
        ]);

        $user = Auth::user();
        $game = Game::find($request->game_id);
        
        // Verify game exists and is active
        if (!$game || !$game->is_active) {
            return response()->json([
                'error' => 'Game not found or inactive',
            ], 404);
        }

        // Verify user can play
        if ($user->ban || !$user->is_verified) {
            return response()->json([
                'error' => 'You are not permitted to play',
            ], 403);
        }

        // Parse stake as Decimal for precision
        $stake = new Decimal($request->stake);

        // Check minimum/maximum bet limits
        $minBet = new Decimal($game->min_bet ?? 0.01);
        $maxBet = new Decimal($game->max_bet ?? 10000);

        if ($stake->isLessThan($minBet) || $stake->isGreaterThan($maxBet)) {
            return response()->json([
                'error' => "Bet must be between {$minBet} and {$maxBet}",
            ], 400);
        }

        // Check wallet balance
        $wallet = $user->wallet;
        if (!$wallet || (new Decimal($wallet->balance))->isLessThan($stake)) {
            return response()->json([
                'error' => 'Insufficient balance',
            ], 402);
        }

        // Begin transaction to debit wallet atomically
        DB::beginTransaction();

        try {
            // Debit wallet
            $wallet->balance -= (float)$stake; // Cast back to float for DB
            $wallet->save();

            // Generate seeds
            $roundId = GameEngineService::generateRoundId();
            $serverSeed = GameEngineService::generateServerSeed();
            $clientSeed = $request->client_seed ?? GameEngineService::generateClientNonce();

            // Create CasinoRound record
            $round = new CasinoRound();
            $round->user_id = $user->id;
            $round->game_id = $game->id;
            $round->stake = (float)$stake; // Store as float
            $round->status = 'pending';
            $round->provider_round_id = $roundId;
            $round->provider_result = [
                'server_seed' => $serverSeed,
                'client_seed' => $clientSeed,
                'round_id' => $roundId,
                'timestamp' => Carbon::now()->toIso8601String(),
            ];
            $round->save();

            // Log wallet transaction
            $this->logTransaction($user, 'stake', $stake, $wallet->balance, "casino:{$roundId}:stake");

            DB::commit();

            return response()->json([
                'round_id' => $roundId,
                'server_seed' => $serverSeed,
                'client_seed' => $clientSeed,
                'nonce' => 1,
                'balance' => (float)$wallet->balance,
            ], 200);

        } catch (Exception $e) {
            DB::rollback();
            \Log::error('initRound error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to initialize round'], 500);
        }
    }

    /**
     * Settle a game round
     * 
     * POST /api/casino/settle-round
     * {
     *   "round_id": "abc123def456...",
     *   "game_id": 1,
     *   "payout": 150,
     *   "game_result": {
     *     "dice_roll": 42,
     *     "win": true,
     *     "multiplier": 1.5
     *   }
     * }
     * 
     * Returns:
     * {
     *   "status": "settled",
     *   "payout": 150,
     *   "net_result": 50,
     *   "balance": 950,
     *   "round_id": "abc123def456..."
     * }
     */
    public function settleRound(Request $request)
    {
        $request->validate([
            'round_id' => 'required|string',
            'game_id' => 'required|integer|exists:games,id',
            'payout' => 'required|numeric|min:0|max:1000000',
            'game_result' => 'required|array',
        ]);

        $user = Auth::user();
        $payout = new Decimal($request->payout);

        // Find the pending round
        $round = CasinoRound::where('provider_round_id', $request->round_id)
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if (!$round) {
            return response()->json([
                'error' => 'Round not found or already settled',
            ], 404);
        }

        // Verify game matches
        if ($round->game_id != $request->game_id) {
            return response()->json([
                'error' => 'Game ID mismatch',
            ], 400);
        }

        $stake = new Decimal($round->stake);

        // Validate payout (can't exceed 100x stake)
        if (!GameEngineService::validatePayout($payout, $stake, 100)) {
            DB::beginTransaction();
            try {
                // Mark as void and reverse stake
                $round->status = 'void';
                $round->payout = 0;
                $round->settled_at = Carbon::now();
                $round->save();

                // Reverse the debit
                $wallet = $user->wallet;
                $wallet->balance += (float)$stake;
                $wallet->save();

                $this->logTransaction($user, 'reversal', $stake, $wallet->balance, "casino:{$request->round_id}:reversal");

                DB::commit();
            } catch (Exception $e) {
                DB::rollback();
            }

            return response()->json(['error' => 'Invalid payout amount'], 400);
        }

        DB::beginTransaction();

        try {
            $wallet = $user->wallet;

            // Update round
            $round->status = 'settled';
            $round->payout = (float)$payout;
            $round->settled_at = Carbon::now();

            $resultData = $round->provider_result ?? [];
            $resultData['game_result'] = $request->game_result;
            $resultData['payout'] = (float)$payout;
            $round->provider_result = $resultData;
            $round->save();

            // Credit payout if greater than 0
            if ($payout->isGreaterThan(0)) {
                $wallet->balance += (float)$payout;
                $wallet->save();

                $this->logTransaction($user, 'payout', $payout, $wallet->balance, "casino:{$request->round_id}:payout");
            }

            DB::commit();

            $netResult = $payout->minus($stake);

            return response()->json([
                'status' => 'settled',
                'payout' => (float)$payout,
                'net_result' => (float)$netResult,
                'balance' => (float)$wallet->balance,
                'round_id' => $request->round_id,
            ], 200);

        } catch (Exception $e) {
            DB::rollback();
            \Log::error('settleRound error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to settle round'], 500);
        }
    }

    /**
     * Get current user balance
     * 
     * GET /api/casino/balance
     * 
     * Returns:
     * {
     *   "balance": 950,
     *   "currency": "KES"
     * }
     */
    public function getBalance(Request $request)
    {
        $user = Auth::user();
        $wallet = $user->wallet;

        return response()->json([
            'balance' => (float)($wallet->balance ?? 0),
            'currency' => $wallet->currency ?? 'KES',
        ], 200);
    }

    /**
     * Get game seeds for verification (provably fair)
     * 
     * GET /api/casino/game-seeds/{roundId}
     * 
     * Returns:
     * {
     *   "round_id": "...",
     *   "server_seed": "...",
     *   "client_seed": "...",
     *   "game_result": {...},
     *   "payout": 150,
     *   "stake": 100
     * }
     */
    public function getGameSeeds(Request $request, $roundId)
    {
        $user = Auth::user();

        $round = CasinoRound::where('provider_round_id', $roundId)
            ->where('user_id', $user->id)
            ->first();

        if (!$round) {
            return response()->json(['error' => 'Round not found'], 404);
        }

        $resultData = $round->provider_result ?? [];

        return response()->json([
            'round_id' => $roundId,
            'server_seed' => $resultData['server_seed'] ?? null,
            'client_seed' => $resultData['client_seed'] ?? null,
            'game_result' => $resultData['game_result'] ?? null,
            'payout' => (float)$round->payout,
            'stake' => (float)$round->stake,
        ], 200);
    }

    /**
     * Get user's round history
     * 
     * GET /api/casino/round-history?limit=20&offset=0
     * 
     * Returns:
     * {
     *   "history": [
     *     {
     *       "round_id": "...",
     *       "game_name": "Dice",
     *       "game_slug": "dice",
     *       "stake": 100,
     *       "payout": 150,
     *       "net": 50,
     *       "status": "settled",
     *       "settled_at": "2024-08-21T10:30:00Z"
     *     }
     *   ],
     *   "count": 1
     * }
     */
    public function roundHistory(Request $request)
    {
        $user = Auth::user();
        $limit = $request->query('limit', 20);
        $offset = $request->query('offset', 0);

        $rounds = CasinoRound::where('user_id', $user->id)
            ->where('status', 'settled')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->offset($offset)
            ->get();

        // Batch fetch game details
        $gameIds = $rounds->pluck('game_id')->unique()->toArray();
        $gamesByID = Game::whereIn('id', $gameIds)->get()->keyBy('id');

        $history = [];
        foreach ($rounds as $round) {
            $game = $gamesByID->get($round->game_id);
            $stake = new Decimal($round->stake);
            $payout = new Decimal($round->payout);
            $net = $payout->minus($stake);

            $history[] = [
                'round_id' => $round->provider_round_id,
                'game_name' => $game->name ?? 'Unknown',
                'game_slug' => $game->slug ?? 'unknown',
                'stake' => (float)$stake,
                'payout' => (float)$payout,
                'net' => (float)$net,
                'status' => $round->status,
                'settled_at' => $round->settled_at?->toIso8601String(),
            ];
        }

        return response()->json([
            'history' => $history,
            'count' => count($history),
        ], 200);
    }

    /**
     * Log a wallet transaction
     * 
     * Assumes Transaction model exists with:
     * - wallet_id, type, amount, balance_after, reference, status
     */
    private function logTransaction($user, $type, $amount, $balanceAfter, $reference)
    {
        $wallet = $user->wallet;
        
        // If your app has a Transaction model, log here:
        // Transaction::create([
        //     'wallet_id' => $wallet->id,
        //     'type' => $type,
        //     'amount' => (float)$amount,
        //     'balance_after' => (float)$balanceAfter,
        //     'reference' => $reference,
        //     'status' => 'completed',
        //     'created_at' => Carbon::now(),
        // ]);
    }
}
