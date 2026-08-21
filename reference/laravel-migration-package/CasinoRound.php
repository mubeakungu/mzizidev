<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

/**
 * CasinoRound - Game round state and settlement record
 * 
 * Stores:
 * - Round state (pending → settled or void)
 * - Seeds for provably fair verification
 * - Game results
 * - Payout amounts
 * 
 * Lifecycle:
 * 1. User calls init-round → status='pending', stake debited
 * 2. User calls settle-round → status='settled', payout credited
 * 3. (If fraud detected) → status='void', stake reversed
 */
class CasinoRound extends Model
{
    protected $fillable = [
        'user_id',
        'game_id',
        'stake',
        'payout',
        'status',
        'provider_round_id',
        'provider_result',
        'settled_at',
    ];

    protected $casts = [
        'stake' => 'decimal:2',
        'payout' => 'decimal:2',
        'provider_result' => 'json',
        'settled_at' => 'datetime',
    ];

    /**
     * Relationship: CasinoRound belongs to User
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relationship: CasinoRound belongs to Game
     */
    public function game()
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * Get settled rounds only (not pending or void)
     */
    public function scopeSettled($query)
    {
        return $query->where('status', 'settled');
    }

    /**
     * Get pending (unsettled) rounds
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Get rounds for a specific game
     */
    public function scopeForGame($query, $gameId)
    {
        return $query->where('game_id', $gameId);
    }

    /**
     * Get rounds for a user within a time range
     */
    public function scopeForPeriod($query, Carbon $startDate, Carbon $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Calculate net result (payout - stake)
     */
    public function getNetResultAttribute()
    {
        return $this->payout - $this->stake;
    }

    /**
     * Check if round is a win
     */
    public function isWin()
    {
        return $this->payout > $this->stake;
    }

    /**
     * Check if round is a loss
     */
    public function isLoss()
    {
        return $this->payout < $this->stake;
    }

    /**
     * Get user's house edge contribution for this round
     * (stake - payout, where positive means player lost)
     */
    public function getHouseEdgeAttribute()
    {
        return $this->stake - $this->payout;
    }

    /**
     * Get user statistics for a specific game
     * 
     * Returns: ['total_played', 'total_wagered', 'total_won', 'roi']
     */
    public static function getUserGameStats($userId, $gameId)
    {
        $rounds = self::where('user_id', $userId)
            ->where('game_id', $gameId)
            ->where('status', 'settled')
            ->get();

        $totalPlayed = $rounds->count();
        $totalWagered = (float)$rounds->sum('stake');
        $totalWon = (float)$rounds->sum('payout');
        $roi = $totalWagered > 0 ? (($totalWon / $totalWagered) - 1) * 100 : 0;

        return [
            'total_played' => $totalPlayed,
            'total_wagered' => round($totalWagered, 2),
            'total_won' => round($totalWon, 2),
            'roi' => round($roi, 2),
            'house_edge' => round($totalWagered - $totalWon, 2),
        ];
    }

    /**
     * Get overall user casino statistics
     */
    public static function getUserCasinoStats($userId)
    {
        $rounds = self::where('user_id', $userId)
            ->where('status', 'settled')
            ->get();

        $totalPlayed = $rounds->count();
        $totalWagered = (float)$rounds->sum('stake');
        $totalWon = (float)$rounds->sum('payout');
        $totalLoss = $totalWagered - $totalWon;
        $totalWins = $rounds->where('payout', '>', 0)->count();
        $winRate = $totalPlayed > 0 ? ($totalWins / $totalPlayed) * 100 : 0;
        $roi = $totalWagered > 0 ? (($totalWon / $totalWagered) - 1) * 100 : 0;
        $avgPayout = $totalPlayed > 0 ? $totalWon / $totalPlayed : 0;

        return [
            'total_played' => $totalPlayed,
            'total_wins' => $totalWins,
            'win_rate' => round($winRate, 2),
            'total_wagered' => round($totalWagered, 2),
            'total_won' => round($totalWon, 2),
            'total_loss' => round($totalLoss, 2),
            'roi' => round($roi, 2),
            'avg_payout' => round($avgPayout, 2),
            'house_edge' => round($totalLoss, 2),
        ];
    }

    /**
     * Audit: Get all rounds that might be fraudulent
     * (extremely high payouts, multiple rounds in short time, etc.)
     */
    public static function getFraudFlags($threshold = 100)
    {
        return self::where('status', 'settled')
            ->where(function ($query) use ($threshold) {
                // Payout > 100x stake
                $query->whereRaw('payout > stake * ?', [$threshold])
                    // OR multiple wins within 1 minute
                    ->orWhereRaw('payout > stake AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)');
            })
            ->get();
    }
}
