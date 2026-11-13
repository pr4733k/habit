<?php
declare(strict_types=1);

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
    }
    return $workout;
}

function dailyResult(mixed $result): array
{
    if (!is_array($result)) {
        throw new DailyInput('Supply a parsed result.');
    }
    $intake = static fn(array $item) => [
        'name' => dailyText($item['name'] ?? null, 160),
        'quantity' => dailyOptionalText($item['quantity'] ?? null, 120),
        'nutrition' => dailyNutrition($item['nutrition'] ?? null),
    ];
    $parsed = [
        'meals' => dailyItems($result['meals'] ?? [], static fn($item) => $intake($item) + [
            'meal' => dailyChoice($item['meal'] ?? 'Other', ['Breakfast', 'Lunch', 'Dinner', 'Snack', 'Other']),
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
    if (!array_filter($parsed)) {
        throw new DailyInput('Record a parsed item or a reason the note needs review.');
    }
    return $parsed;
}
