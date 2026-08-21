<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * Game - Casino game catalog entry
 * 
 * Represents a single game in the casino lobby.
 * Links to game implementations via slug (routing key).
 */
class Game extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'category_id',
        'provider',
        'description',
        'rules',
        'min_bet',
        'max_bet',
        'is_active',
        'display_order',
        'badge',
        'image_url',
        'thumbnail_url',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'min_bet' => 'decimal:2',
        'max_bet' => 'decimal:2',
    ];

    /**
     * Relationship: Game belongs to GameCategory
     */
    public function category()
    {
        return $this->belongsTo(GameCategory::class);
    }

    /**
     * Relationship: Game has many CasinoRound records
     */
    public function casinoRounds()
    {
        return $this->hasMany(CasinoRound::class);
    }

    /**
     * Get all active games for casino lobby
     */
    public static function getActiveCatalog()
    {
        return self::where('is_active', true)
            ->orderBy('display_order')
            ->get();
    }

    /**
     * Get featured games (HOT, POPULAR badges)
     */
    public static function getFeatured($limit = 8)
    {
        return self::where('is_active', true)
            ->whereIn('badge', ['HOT', 'POPULAR'])
            ->orderBy('display_order')
            ->limit($limit)
            ->get();
    }

    /**
     * Get games by category
     */
    public static function byCategory($categorySlug)
    {
        return self::whereHas('category', function ($query) use ($categorySlug) {
            $query->where('slug', $categorySlug);
        })
            ->where('is_active', true)
            ->orderBy('display_order')
            ->get();
    }

    /**
     * Search games by name
     */
    public static function search($query)
    {
        return self::where('is_active', true)
            ->where('name', 'ilike', "%{$query}%")
            ->orderBy('display_order')
            ->get();
    }
}
