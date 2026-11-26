<?php
declare(strict_types=1);
require_once __DIR__ . '/_training.php';

function dailyText(mixed $value, int $limit): string
{
    if (!is_string($value) || trim($value) === '' || strlen($value) > $limit) {
        throw new DailyInput('Enter a valid text value.');
    }
    return trim($value);
}

function dailyOptionalText(mixed $value, int $limit): ?string
{
    return $value === null ? null : dailyText($value, $limit);
}

function dailyNumber(mixed $value, int $max, bool $whole = false): int|float
{
    if ((!is_int($value) && !is_float($value)) || !is_finite($value)
        || $value < 0 || $value > $max || ($whole && floor($value) !== (float)$value)) {
        throw new DailyInput('Enter a valid number.');
    }
    return $whole ? (int)$value : $value;
}

function dailyChoice(mixed $value, array $choices): string
{
    if (!in_array($value, $choices, true)) {
        throw new DailyInput('Select a supported value.');
    }
    return $value;
}

function dailyItems(mixed $value, callable $map, int $limit = 30): array
{
    if (!is_array($value) || !array_is_list($value) || count($value) > $limit) {
        throw new DailyInput('Invalid list of logged items.');
    }
    return array_map(static function ($item) use ($map) {
        if (!is_array($item)) {
            throw new DailyInput('Invalid logged item.');
        }
        return $map($item);
    }, $value);
}

function dailyNutrition(mixed $item): ?array
{
    if ($item === null) {
        return null;
    }
    if (!is_array($item) || !is_bool($item['estimated'] ?? null)) {
        throw new DailyInput('Specify whether nutrition is estimated.');
    }
    $nutrition = ['estimated' => $item['estimated']];
    foreach (['kcal', 'protein', 'carbs', 'fat'] as $key) {
        $value = $item[$key] ?? null;
        $nutrition[$key] = $value === null ? null : dailyNumber($value, $key === 'kcal' ? 20000 : 2000);
    }
    return $nutrition;
}

function dailyWorkout(array $item): array
{
    $workout = [
        'type' => dailyChoice($item['type'] ?? null, ['gym', 'run', 'sport']),
        'name' => dailyText($item['name'] ?? null, 160),
        'details' => dailyOptionalText($item['details'] ?? null, 1600),
    ];
    if ($workout['type'] === 'run') {
        foreach (['distance_m' => 2500000, 'duration_s' => 604800, 'pace_s_per_km' => 43200, 'avg_hr_bpm' => 260] as $key => $max) {
            $workout[$key] = isset($item[$key]) ? dailyNumber($item[$key], $max) : null;
        }
    } elseif ($workout['type'] === 'gym' && isset($item['duration_s'])) {
        $workout['duration_s'] = dailyNumber($item['duration_s'], 86400, true);
    }
    return $workout + dailyTrainingExtras($item);
}

function dailyLiftingEnergy(array $entries, array $settings): array
{
    $bodyWeightKg = $settings['body_weight_kg'] ?? null;
    if (!is_numeric($bodyWeightKg) || $bodyWeightKg <= 0) {
        return [];
    }
    $durations = [];
    foreach ($entries as $entry) {
        foreach ($entry['parsed']['workouts'] as $workout) {
            if ($workout['type'] === 'gym') {
                $date = $entry['date'];
                $durations[$date] = ($durations[$date] ?? 0) + ($workout['duration_s'] ?? 0);
            }
        }
    }
    $defaultMinutes = $settings['default_minutes'] ?? 0;
    $estimates = [];
    foreach ($durations as $date => $duration) {
        $assumedDuration = $duration <= 0;
        $minutes = $assumedDuration ? $defaultMinutes : $duration / 60;
        if (is_numeric($minutes) && $minutes > 0) {
            // The Adult Compendium lists general resistance training at 3.5 MET.
            $activeKcal = (3.5 - 1.0) * 3.5 * $bodyWeightKg * $minutes / 200;
            $estimates[$date] = ['kcal' => round($activeKcal / 5) * 5, 'minutes' => $minutes,
                'assumedDuration' => $assumedDuration];
        }
    }
    return $estimates;
}

function dailyDefaultMeal(string $createdAt): string
{
    $loggedAt = new DateTimeImmutable($createdAt, new DateTimeZone('UTC'));
    $hour = (int)$loggedAt->setTimezone(new DateTimeZone($GLOBALS['config']['timezone'] ?? 'Asia/Kolkata'))->format('G');
    return match (true) {
        $hour < 13 => 'Breakfast',
        $hour < 16 => 'Lunch',
        $hour < 19 => 'Snack',
        default => 'Dinner',
    };
}

function dailyFoodPortion(mixed $input): array {
    if(!is_array($input))throw new DailyInput('Invalid food portion.');
    $amount=dailyNumber($input['amount']??null,30000);
    if($amount<=0)throw new DailyInput('Food portion must be greater than zero.');
    return ['amount'=>$amount,'unit'=>dailyChoice($input['unit']??null,['g','ml','piece','serving'])];
}

function dailyResult(mixed $result, string $defaultMeal = 'Other'): array
{
    if (!is_array($result)) {
        throw new DailyInput('Supply a parsed result.');
    }
    $intake = static fn(array $item) => [
        'name' => dailyText($item['name'] ?? null, 160),
        'quantity' => dailyOptionalText($item['quantity'] ?? null, 120),
        'nutrition' => dailyNutrition($item['nutrition'] ?? null),
    ] + (isset($item['portion'])?['portion'=>dailyFoodPortion($item['portion'])]:[]);
    $parsed = [
        'meals' => dailyItems($result['meals'] ?? [], static fn($item) => $intake($item) + [
            'meal' => dailyChoice($item['meal'] ?? $defaultMeal, ['Breakfast', 'Lunch', 'Dinner', 'Snack', 'Other']),
        ]),
        'workouts' => dailyItems($result['workouts'] ?? [], 'dailyWorkout'),
        'water' => dailyItems($result['water'] ?? [], static fn($item) => [
            'mode' => dailyChoice($item['mode'] ?? null, ['add', 'total']),
            'ml' => dailyNumber($item['ml'] ?? null, 30000, true),
        ]),
        'alcohol' => dailyItems($result['alcohol'] ?? [], $intake),
        'study' => dailyItems($result['study'] ?? [], static fn($item) => [
            'name' => dailyText($item['name'] ?? null, 160),
            'minutes' => isset($item['minutes']) ? dailyNumber($item['minutes'], 1440, true) : null,
        ]),
        'review' => dailyOptionalText($result['review'] ?? null, 600),
    ];
    if (array_key_exists('rest', $result)) {
        if (!is_bool($result['rest'])) throw new DailyInput('Rest must be true or false.');
        $parsed['rest'] = $result['rest'];
    }
    if (isset($result['recovery'])) $parsed['recovery'] = dailyItems($result['recovery'], 'dailyRecovery');
    if (isset($result['planned_sessions'])) $parsed['planned_sessions'] = dailyItems($result['planned_sessions'], 'dailyPlannedSession');
    if (!array_filter($parsed)) {
        throw new DailyInput('Record a parsed item or a reason the note needs review.');
    }
    return $parsed;
}
