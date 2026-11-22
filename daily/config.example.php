<?php

return [
    'dsn' => 'mysql:host=localhost;dbname=habit;charset=utf8mb4',
    'user' => 'habit',
    'password' => 'YOUR_DATABASE_PASSWORD',
    'password_hash' => 'YOUR_LOG_PASSWORD_HASH',
    'session_key' => 'YOUR_RANDOM_SESSION_KEY',
    'sync_token_hash' => 'YOUR_PROCESSOR_TOKEN_HASH',
    'timezone' => 'UTC',
    'public_calendar' => false,
    'auto_sync' => false,
    'processor' => 'openrouter', // Use 'external' for Codex or your own processor.
    'openrouter_api_key' => 'YOUR_OPENROUTER_KEY',
    'openrouter_model' => 'google/gemini-3.8-flash',
    'openrouter_monthly_budget' => 5,
    'workout_minutes' => 60,
    'training_goals' => [],
    // Optional private JSON file containing your confirmed food references.
    // 'food_reference_file' => '/absolute/private/path/food-reference.json',
];
