<?php
declare(strict_types=1);

function dailyTrainingDate(mixed $value): string
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)
        || !($date = DateTimeImmutable::createFromFormat('!Y-m-d', $value)) || $date->format('Y-m-d') !== $value) {
        throw new DailyInput('Select a valid training date.');
    }
    return $value;
}

function dailyTrainingSet(array $set): array
{
    $value = [
        'reps' => isset($set['reps']) ? dailyNumber($set['reps'], 1000, true) : null,
        'weight' => isset($set['weight']) ? dailyNumber($set['weight'], 3000) : null,
        'weight_unit' => isset($set['weight_unit']) ? dailyChoice($set['weight_unit'], ['kg', 'lb']) : null,
        'weight_basis' => isset($set['weight_basis']) ? dailyChoice($set['weight_basis'],
            ['total', 'per_dumbbell', 'per_side', 'bodyweight', 'assistance', 'added_weight']) : null,
        'warmup' => $set['warmup'] ?? null,
    ];
    if ($value['warmup'] !== null && !is_bool($value['warmup'])) {
        throw new DailyInput('Warm-up must be true, false, or null.');
    }
    foreach (['rir' => 20, 'effort' => 10] as $key => $max) {
        if (isset($set[$key])) $value[$key] = dailyNumber($set[$key], $max);
    }
    if (isset($set['notes'])) $value['notes'] = dailyText($set['notes'], 600);
    return $value;
}

function dailyTrainingExtras(array $item): array
{
    $extra = [];
    foreach (['session_id' => 80, 'fixed_session_id' => 80, 'venue' => 160, 'notes' => 600] as $key => $limit) {
        if (isset($item[$key])) $extra[$key] = dailyText($item[$key], $limit);
    }
    foreach (['rir' => 20, 'effort' => 10] as $key => $max) {
        if (isset($item[$key])) $extra[$key] = dailyNumber($item[$key], $max);
    }
    if (isset($item['sets'])) $extra['sets'] = dailyItems($item['sets'], 'dailyTrainingSet', 100);
    if (isset($item['status'])) dailyChoice($item['status'], ['completed']);
    return $extra;
}

function dailyRecovery(array $item): array
{
    $soreness = $item['soreness'] ?? [];
    if (!is_array($soreness) || count($soreness) > 30) throw new DailyInput('Invalid muscle soreness.');
    $muscles = [];
    foreach ($soreness as $muscle => $severity) {
        $muscle = dailyText($muscle, 80);
        $muscles[$muscle] = $severity === null ? null
            : (is_string($severity) ? dailyText($severity, 40) : dailyNumber($severity, 10));
    }
    $observedAt = $item['observed_at'] ?? null;
    if ($observedAt !== null) {
        if (!is_string($observedAt) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $observedAt)) {
            throw new DailyInput('Observation time needs an ISO timestamp with a timezone.');
        }
        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', str_replace('Z', '+00:00', $observedAt));
        if (!$time || $time->format('Y-m-d\TH:i:sP') !== str_replace('Z', '+00:00', $observedAt)) {
            throw new DailyInput('Invalid observation time.');
        }
    }
    $fatigue = $item['fatigue'] ?? null;
    return [
        'observed_at' => $observedAt,
        'soreness' => $muscles,
        'pain_notes' => dailyOptionalText($item['pain_notes'] ?? null, 600),
        'sleep_hours' => isset($item['sleep_hours']) ? dailyNumber($item['sleep_hours'], 24) : null,
        'fatigue' => $fatigue === null ? null : (is_string($fatigue) ? dailyText($fatigue, 40) : dailyNumber($fatigue, 10)),
        'notes' => dailyOptionalText($item['notes'] ?? null, 600),
    ];
}

function dailyTrainingTexts(mixed $items, int $limit = 30): array
{
    if (!is_array($items) || !array_is_list($items) || count($items) > $limit) throw new DailyInput('Invalid training list.');
    return array_map(static fn($value) => dailyText($value, 160), $items);
}

function dailyTrainingPreferences(array $input): array
{
    $result = [];
    if (array_key_exists('training_goals', $input)) $result['training_goals'] = dailyTrainingTexts($input['training_goals'], 10);
    if (array_key_exists('available_session_minutes', $input)) $result['available_session_minutes'] =
        $input['available_session_minutes'] === null ? null : dailyNumber($input['available_session_minutes'], 1440, true);
    if (array_key_exists('gym_venue', $input)) $result['gym_venue'] = dailyOptionalText($input['gym_venue'], 160);
    if (array_key_exists('weekly_running_goal', $input)) {
        $goal = $input['weekly_running_goal'];
        if ($goal === null) $result['weekly_running_goal'] = null;
        else {
            if (!is_array($goal)) throw new DailyInput('Invalid running goal.');
            $result['weekly_running_goal'] = [
                'sessions' => dailyNumber($goal['sessions'] ?? null, 14, true),
                'runs' => dailyItems($goal['runs'] ?? [], static fn($run) => [
                    'kind' => dailyText($run['kind'] ?? null, 80),
                    'distance_m' => dailyNumber($run['distance_m'] ?? null, 250000, true),
                ], 14),
            ];
        }
    }
    return $result;
}

function dailyPlannedSession(array $input): array
{
    return [
        'id' => dailyText($input['id'] ?? null, 80),
        'date' => dailyTrainingDate($input['date'] ?? null),
        'type' => dailyChoice($input['type'] ?? null, ['gym', 'run', 'sport']),
        'status' => 'planned',
        'venue' => dailyOptionalText($input['venue'] ?? null, 160),
        'exercises' => dailyTrainingTexts($input['exercises'] ?? []),
        'muscle_groups' => dailyTrainingTexts($input['muscle_groups'] ?? []),
        'notes' => dailyOptionalText($input['notes'] ?? null, 600),
    ];
}

// Read legacy set descriptions conservatively; an unqualified load has no known basis.
function dailyLegacySets(string $name, ?string $details): array
{
    $sets = [];
    $complete = $details !== null;
    foreach (preg_split('/\r?\n/', $details ?? '') as $raw) {
        $line = trim($raw);
        if ($line === '') continue;
        $warmup = preg_match('/\bwarm[ -]?up\b/i', $line) ? true
            : (preg_match('/\bworking(?: set)?\b/i', $line) ? false : null);
        $line = preg_replace('/^(?:warm[ -]?up|working)(?: sets?)?\s*[:\-]?\s*/i', '', $line);
        $line = preg_replace('/\s*\(?warm[ -]?up\)?\s*$/i', '', $line);
        $line = preg_replace('/\.\s*Reps unspecified\.?$/i', '', $line);
        $basis = match (true) {
            (bool)preg_match('/\bassistance\b|\bassisted\b/i', $line . ' ' . $name) => 'assistance',
            (bool)preg_match('/(?:per|each)\s*(?:dumbbell|hand)|each\s*arm/i', $line) => 'per_dumbbell',
            (bool)preg_match('/(?:per|each)\s*(?:side|leg)/i', $line) => 'per_side',
            (bool)preg_match('/\btotal\b/i', $line) => 'total',
            (bool)preg_match('/body\s*weight\s*\+|added\s*weight/i', $line) => 'added_weight',
            (bool)preg_match('/body\s*weight|unassisted/i', $line . ' ' . $name) => 'bodyweight',
            default => null,
        };
        $clean = trim(preg_replace('/(?:per|each)\s*(?:dumbbell|hand|arm|side|leg)|assistance|assisted|\btotal\b|body\s*weight\s*\+?|added\s*weight/i', '', $line));
        $clean = preg_replace('/\s+/', ' ', $clean);
        $count = 1; $reps = null; $weight = null; $unit = null; $matched = true;
        $load = '(\d+(?:\.\d+)?)\s*(kg|lbs?)';
        if (preg_match('/^' . $load . '\s*[×x]\s*(\d+)\s*reps?(?:\s*[×x]\s*(\d+)\s*sets?)?$/iu', $clean, $parts)) {
            $weight = (float)$parts[1]; $unit = strtolower($parts[2]) === 'kg' ? 'kg' : 'lb';
            $reps = (int)$parts[3]; $count = isset($parts[4]) ? (int)$parts[4] : 1;
        } elseif (preg_match('/^(\d+)\s*sets?\s*[×x]\s*(\d+)\s*reps?(?:\s+at\s+' . $load . ')?$/iu', $clean, $parts)) {
            $count = (int)$parts[1]; $reps = (int)$parts[2];
            if (isset($parts[3])) { $weight = (float)$parts[3]; $unit = strtolower($parts[4]) === 'kg' ? 'kg' : 'lb'; }
        } elseif (preg_match('/^(\d+)\s*sets?\s+at\s+' . $load . '$/iu', $clean, $parts)) {
            $count = (int)$parts[1]; $weight = (float)$parts[2]; $unit = strtolower($parts[3]) === 'kg' ? 'kg' : 'lb';
        } elseif (preg_match('/^(\d+)\s*reps?\s+at\s+' . $load . '$/iu', $clean, $parts)) {
            $reps = (int)$parts[1]; $weight = (float)$parts[2]; $unit = strtolower($parts[3]) === 'kg' ? 'kg' : 'lb';
        } else $matched = false;
        if (!$matched || $count < 1 || $count > 100 || count($sets) + $count > 100
            || ($weight !== null && (!is_finite($weight) || $weight > 3000)) || ($reps !== null && $reps > 1000)) {
            $complete = false; continue;
        }
        for ($index = 0; $index < $count; $index++) $sets[] = dailyTrainingSet([
            'reps' => $reps, 'weight' => $weight, 'weight_unit' => $unit, 'weight_basis' => $basis, 'warmup' => $warmup,
        ]);
    }
    return ['sets' => $sets, 'complete' => $complete && count($sets) > 0];
}
