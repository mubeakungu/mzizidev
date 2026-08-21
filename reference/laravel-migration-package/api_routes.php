<?php

/**
 * Casino Games API Routes
 * 
 * Add these to routes/api.php
 * 
 * Usage: All routes prefixed with /api
 */

Route::middleware('auth:sanctum')->group(function () {
    
    // Casino game initialization and settlement
    Route::post('/casino/init-round', 'CasinoGamesController@initRound');
    Route::post('/casino/settle-round', 'CasinoGamesController@settleRound');
    Route::get('/casino/balance', 'CasinoGamesController@getBalance');
    Route::get('/casino/game-seeds/{roundId}', 'CasinoGamesController@getGameSeeds');
    Route::get('/casino/round-history', 'CasinoGamesController@roundHistory');
    
    // Real-time game APIs (WebSocket or polling)
    Route::prefix('games/jetx')->group(function () {
        Route::get('/state', 'JetXController@getGameState');
        Route::post('/place-bet', 'JetXController@placeBet');
        Route::post('/cashout', 'JetXController@cashout');
        Route::get('/history', 'JetXController@history');
    });
    
    Route::prefix('games/crash')->group(function () {
        Route::get('/state', 'CrashController@getGameState');
        Route::post('/place-bet', 'CrashController@placeBet');
        Route::post('/cashout', 'CrashController@cashout');
        Route::get('/history', 'CrashController@history');
    });
    
    Route::prefix('games/aviator')->group(function () {
        Route::get('/state', 'AviatorMziziController@getGameState');
        Route::post('/place-bet', 'AviatorMziziController@placeBet');
        Route::post('/cashout', 'AviatorMziziController@cashout');
        Route::get('/history', 'AviatorMziziController@history');
    });
    
    Route::prefix('games/hilocard')->group(function () {
        Route::get('/state', 'HiLoCardController@getGameState');
        Route::post('/place-bet', 'HiLoCardController@placeBet');
        Route::post('/predict', 'HiLoCardController@predict');
        Route::get('/history', 'HiLoCardController@history');
    });
    
    Route::prefix('games/plinko')->group(function () {
        Route::get('/state', 'PlinkoMziziController@getGameState');
        Route::post('/place-bet', 'PlinkoMziziController@placeBet');
        Route::get('/history', 'PlinkoMziziController@history');
    });

});
