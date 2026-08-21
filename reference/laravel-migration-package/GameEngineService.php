<?php

namespace App\Services;

use Illuminate\Support\Str;
use Decimal\Decimal;

/**
 * GameEngineService - Provably Fair Game Engine
 * 
 * Converts Flask game_engine.py to Laravel service.
 * Handles:
 * - Deterministic seed generation
 * - Provably fair result calculation
 * - Payout validation
 */
class GameEngineService
{
    /**
     * Generate a cryptographically secure round ID
     * Format: hex string (32 chars)
     */
    public static function generateRoundId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Generate a server seed (secret, sent hashed to client initially)
     * Format: hex string (32 chars)
     */
    public static function generateServerSeed(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Generate a client nonce (prevents server manipulation)
     * Format: hex string (16 chars)
     */
    public static function generateClientNonce(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Validate payout amount doesn't exceed max multiplier
     * Protection against fraud/manipulation
     */
    public static function validatePayout($payout, $stake, $maxMultiplier = 100): bool
    {
        $maxPayout = (new Decimal($stake))->multiply($maxMultiplier);
        
        return (new Decimal($payout))->isGreaterThanOrEqual(0) &&
               (new Decimal($payout))->isLessThanOrEqual($maxPayout);
    }

    /**
     * Generate deterministic dice result (0-999999)
     * 
     * Result is reproducible from same seeds, allowing provably fair verification.
     * Uses SHA256 hash of combined seeds.
     */
    public static function generateDiceResult(
        string $serverSeed,
        string $clientSeed,
        int $nonce = 1
    ): int {
        $combined = "{$serverSeed}{$clientSeed}{$nonce}";
        $hash = hash('sha256', $combined);
        
        // Take first 8 chars of hash, convert to int
        $intValue = hexdec(substr($hash, 0, 8));
        
        // Normalize to 0-999999
        return $intValue % 1000000;
    }

    /**
     * Generate deterministic roulette number (0-36)
     */
    public static function generateRouletteNumber(
        string $serverSeed,
        string $clientSeed,
        int $nonce = 1
    ): int {
        $result = self::generateDiceResult($serverSeed, $clientSeed, $nonce);
        return $result % 37; // European roulette: 0-36
    }

    /**
     * Generate deterministic crash multiplier (2.00x to 500.00x)
     * Uses exponential distribution for realistic multiplier spread.
     * 
     * Conversion from Flask's math.exp() logic:
     * ```python
     * crash_multiplier = math.exp(random_factor * math.log(500.0))
     * ```
     */
    public static function generateCrashMultiplier(
        string $serverSeed,
        string $clientSeed,
        int $nonce = 1
    ): float {
        $combined = "{$serverSeed}{$clientSeed}{$nonce}";
        $hash = hash('sha256', $combined);
        
        // Take first 8 hex chars, normalize to 0-1
        $byte1 = hexdec(substr($hash, 0, 8));
        $randomFactor = ($byte1 % 10000) / 10000.0;
        
        // Exponential distribution: e^(factor * ln(500))
        $crashMultiplier = exp($randomFactor * log(500.0));
        
        // Clamp to 2.00x - 500.00x range
        $crashMultiplier = max(2.00, min(500.00, $crashMultiplier));
        
        return round($crashMultiplier, 2);
    }

    /**
     * Generate Mines pattern (hidden mines positions)
     * Returns array of mine positions (0-24)
     * 
     * @param string $serverSeed
     * @param string $clientSeed
     * @param int $mineCount Number of mines (typically 3-24)
     * @return array Indices of mine positions
     */
    public static function generateMinesPattern(
        string $serverSeed,
        string $clientSeed,
        int $mineCount = 3,
        int $nonce = 1
    ): array {
        $combined = "{$serverSeed}{$clientSeed}{$nonce}";
        $hash = hash('sha256', $combined);
        
        $mines = [];
        $gridSize = 25; // 5x5 grid
        
        // Generate deterministic positions using hash
        for ($i = 0; $i < $gridSize && count($mines) < $mineCount; $i++) {
            $byte = hexdec(substr($hash, ($i * 2) % 60, 2));
            $position = $byte % $gridSize;
            
            if (!in_array($position, $mines)) {
                $mines[] = $position;
            }
        }
        
        return array_slice($mines, 0, $mineCount);
    }

    /**
     * Generate Plinko ball drop position (0-14 for 16-row pin board)
     */
    public static function generatePlinkoResult(
        string $serverSeed,
        string $clientSeed,
        int $nonce = 1
    ): int {
        $result = self::generateDiceResult($serverSeed, $clientSeed, $nonce);
        return $result % 15; // Typically 15 buckets
    }

    /**
     * Generate Hi-Lo card (High/Low indication)
     * Returns array with 'card' and 'suit'
     */
    public static function generateHiLoCard(
        string $serverSeed,
        string $clientSeed,
        int $nonce = 1
    ): array {
        $result = self::generateDiceResult($serverSeed, $clientSeed, $nonce);
        
        $suits = ['♠', '♥', '♦', '♣'];
        $ranks = ['2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K', 'A'];
        
        $suitIndex = $result % 4;
        $rankIndex = ($result / 4) % 13;
        
        return [
            'suit' => $suits[$suitIndex],
            'rank' => $ranks[(int)$rankIndex],
            'value' => ($rankIndex + 2), // 2-14
        ];
    }

    /**
     * Calculate payout multiplier based on game type and result
     * This is a stub - each game implements its own payout logic
     */
    public static function calculateGamePayout(
        string $gameSlug,
        array $gameResult,
        $stake
    ): ?float {
        switch ($gameSlug) {
            case 'dice':
                return self::calculateDicePayout($gameResult);
            case 'european-roulette':
                return self::calculateRoulettePayout($gameResult);
            case 'mines':
                return self::calculateMinesPayout($gameResult);
            default:
                return null;
        }
    }

    /**
     * Dice payout: (chance% / 100) * 100 → multiplier
     * e.g., 50% chance = 2x payout
     */
    private static function calculateDicePayout(array $gameResult): float
    {
        $chance = $gameResult['chance'] ?? 0;
        if ($chance <= 0 || $chance >= 100) {
            return 0;
        }
        
        // House edge: 1%
        $multiplier = (99.0 / $chance);
        return round($multiplier, 2);
    }

    /**
     * European Roulette payout based on bet type
     */
    private static function calculateRoulettePayout(array $gameResult): float
    {
        $betType = $gameResult['bet_type'] ?? 'straight';
        $winnings = $gameResult['winnings'] ?? false;
        
        if (!$winnings) {
            return 0;
        }
        
        return match($betType) {
            'straight' => 36, // 1:35 + original bet
            'split' => 18,
            'street' => 12,
            'corner' => 9,
            'line' => 6,
            'dozen' => 3,
            'column' => 3,
            'red_black' => 2,
            'odd_even' => 2,
            'high_low' => 2,
            default => 0,
        };
    }

    /**
     * Mines payout based on tiles revealed before hitting mine
     * Multiplier increases exponentially as more tiles are safe
     */
    private static function calculateMinesPayout(array $gameResult): float
    {
        $tilesRevealed = $gameResult['tiles_revealed'] ?? 0;
        $totalMines = $gameResult['total_mines'] ?? 3;
        
        if ($tilesRevealed <= 0) {
            return 0;
        }
        
        // Base multiplier: 1.15x per safe tile
        $multiplier = pow(1.15, $tilesRevealed);
        
        // House edge adjustment
        $multiplier = $multiplier * 0.97;
        
        return round($multiplier, 2);
    }
}
