<?php

/**
 * Real-Time Game Models
 * 
 * These are two related Eloquent models for real-time games:
 * - RealTimeGameRound: Represents a single game round
 * - RealTimeGameBet: Represents a player's bet in a round
 */

namespace App;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

/**
 * RealTimeGameRound - Single round of a real-time game
 * 
 * Stores:
 * - Round ID and status (betting → playing → crashed/ended)
 * - Provably fair seeds
 * - Game outcome (crash point for Crash/JetX)
 * - Timing info (when betting ends, when game starts, when it crashes)
 * - Audit trail (server seed for verification)
 */
class RealTimeGameRound extends Model
{
    protected $table = 'real_time_game_rounds';

    protected $fillable = [
        'game_slug',
        'provider_round_id',
        'crash_point',
        'server_seed',
        'status',
        'betting_ends_at',
        'started_at',
        'crashed_at',
    ];

    protected $casts = [
        'crash_point' => 'float',
        'betting_ends_at' => 'datetime',
        'started_at' => 'datetime',
        'crashed_at' => 'datetime',
    ];

    /**
     * Relationship: Round has many bets
     */
    public function bets()
    {
        return $this->hasMany(RealTimeGameBet::class, 'round_id');
    }

    /**
     * Get all winning bets (player cashed out before crash)
     */
    public function winningBets()
    {
        return $this->bets()->where('status', 'cashed_out')->where('payout', '>', 0);
    }

    /**
     * Get all losing bets (player didn't cash out before crash)
     */
    public function losingBets()
    {
        return $this->bets()->where('status', 'lost');
    }

    /**
     * Calculate round profitability
     * 
     * Returns: ['total_wagered', 'total_paid', 'house_profit']
     */
    public function calculateProfitability()
    {
        $totalWagered = (float)$this->bets()->sum('bet_amount');
        $totalPaid = (float)$this->bets()->sum('payout');
        $houseProfit = $totalWagered - $totalPaid;

        return [
            'total_wagered' => round($totalWagered, 2),
            'total_paid' => round($totalPaid, 2),
            'house_profit' => round($houseProfit, 2),
            'player_count' => $this->bets()->distinct('user_id')->count(),
        ];
    }

    /**
     * Get round statistics
     */
    public function getStats()
    {
        $bets = $this->bets;
        $totalBets = $bets->count();
        $winners = $bets->filter(fn($b) => $b->status === 'cashed_out' && $b->payout > 0)->count();
        $losers = $bets->filter(fn($b) => $b->status === 'lost')->count();

        return [
            'total_bets' => $totalBets,
            'unique_players' => $bets->pluck('user_id')->unique()->count(),
            'winners' => $winners,
            'losers' => $losers,
            'win_rate' => $totalBets > 0 ? round(($winners / $totalBets) * 100, 2) : 0,
        ];
    }
}

/**
 * RealTimeGameBet - Player's bet in a real-time game round
 * 
 * Stores:
 * - Player and round reference
 * - Bet amount
 * - Payout (only set when cashed out or lost)
 * - Cashout multiplier (if player cashed out)
 * - Bet status (active → cashed_out | lost | expired)
 */
class RealTimeGameBet extends Model
{
    protected $table = 'real_time_game_bets';

    protected $fillable = [
        'round_id',
        'user_id',
        'bet_amount',
        'payout',
        'status',
        'cashout_multiplier',
    ];

    protected $casts = [
        'bet_amount' => 'float',
        'payout' => 'float',
        'cashout_multiplier' => 'float',
    ];

    /**
     * Relationship: Bet belongs to Round
     */
    public function round()
    {
        return $this->belongsTo(RealTimeGameRound::class);
    }

    /**
     * Relationship: Bet belongs to User
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get profit/loss for this bet
     */
    public function getNetResultAttribute()
    {
        return $this->payout - $this->bet_amount;
    }

    /**
     * Check if bet is a win
     */
    public function isWin()
    {
        return $this->status === 'cashed_out' && $this->payout > $this->bet_amount;
    }

    /**
     * Check if bet is a loss
     */
    public function isLoss()
    {
        return $this->status === 'lost' || ($this->status === 'cashed_out' && $this->payout <= $this->bet_amount);
    }

    /**
     * Scope: Get bets from a specific round
     */
    public function scopeForRound($query, $roundId)
    {
        return $query->where('round_id', $roundId);
    }

    /**
     * Scope: Get bets from a specific user
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: Get settled bets (won or lost)
     */
    public function scopeSettled($query)
    {
        return $query->whereIn('status', ['cashed_out', 'lost', 'expired']);
    }

    /**
     * Scope: Get active bets (not yet settled)
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Get user's stats for this game type
     * 
     * @param $gameSlug e.g., 'jetx', 'crash'
     */
    public static function getUserGameStats($userId, $gameSlug)
    {
        $bets = self::whereHas('round', function ($query) use ($gameSlug) {
            $query->where('game_slug', $gameSlug);
        })
            ->where('user_id', $userId)
            ->whereIn('status', ['cashed_out', 'lost'])
            ->get();

        $totalBets = $bets->count();
        $totalWagered = (float)$bets->sum('bet_amount');
        $totalWon = (float)$bets->filter(fn($b) => $b->status === 'cashed_out' && $b->payout > $b->bet_amount)->sum('payout');
        $totalLost = (float)$bets->filter(fn($b) => $b->status === 'lost')->sum('bet_amount');
        $wins = $bets->filter(fn($b) => $b->isWin())->count();
        $losses = $bets->filter(fn($b) => $b->isLoss())->count();

        $roi = $totalWagered > 0 ? (($totalWon / $totalWagered) - 1) * 100 : 0;

        return [
            'game_slug' => $gameSlug,
            'total_bets' => $totalBets,
            'total_wagered' => round($totalWagered, 2),
            'total_won' => round($totalWon, 2),
            'total_lost' => round($totalLost, 2),
            'wins' => $wins,
            'losses' => $losses,
            'win_rate' => $totalBets > 0 ? round(($wins / $totalBets) * 100, 2) : 0,
            'roi' => round($roi, 2),
            'avg_bet' => $totalBets > 0 ? round($totalWagered / $totalBets, 2) : 0,
            'highest_multiplier' => (float)$bets->max('cashout_multiplier') ?? 0,
        ];
    }
}
