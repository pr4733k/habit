<?php
declare(strict_types=1);

function dailyCoachDefaults(): array
{
    return ['preferences' => ['training_goals' => [], 'available_session_minutes' => null,
        'gym_venue' => null, 'weekly_running_goal' => null], 'fixed_sessions' => []];
}

function dailyCoachSettings(array $input): array
{
    if (!is_array($input['preferences'] ?? [])) throw new DailyInput('Invalid training preferences.');
    $preferences = array_replace(dailyCoachDefaults()['preferences'], dailyTrainingPreferences($input['preferences'] ?? []));
    $rules = dailyItems($input['fixed_sessions'] ?? [], static function ($rule) {
        $start = dailyTrainingDate($rule['start_date'] ?? null);
        $end = isset($rule['end_date']) ? dailyTrainingDate($rule['end_date']) : null;
        if ($end !== null && $end < $start) throw new DailyInput('Training schedule ends before it starts.');
        $weekday = dailyNumber($rule['weekday'] ?? null, 7, true);
        if ($weekday === 0) throw new DailyInput('Weekday must be 1 (Monday) through 7 (Sunday).');
        $value = dailyPlannedSession(['date' => $start] + $rule);
        unset($value['date'], $value['status']);
        $value += ['weekday' => $weekday, 'start_date' => $start, 'end_date' => $end];
        $seen = [];
        $value['exceptions'] = dailyItems($rule['exceptions'] ?? [], static function ($exception) use ($start, $end, $weekday, &$seen) {
            $date = dailyTrainingDate($exception['date'] ?? null);
            if (isset($seen[$date]) || $date < $start || ($end !== null && $date > $end)
                || (int)(new DateTimeImmutable($date))->format('N') !== $weekday) {
                throw new DailyInput('An exception must identify one scheduled occurrence.');
            }
            $seen[$date] = true;
            $cancelled = $exception['cancelled'] ?? false;
            if (!is_bool($cancelled)) throw new DailyInput('Cancelled must be true or false.');
            $item = ['date' => $date, 'cancelled' => $cancelled];
            if (isset($exception['move_to'])) $item['move_to'] = dailyTrainingDate($exception['move_to']);
            foreach (['venue' => 160, 'notes' => 600] as $key => $limit) {
                if (array_key_exists($key, $exception)) $item[$key] = dailyOptionalText($exception[$key], $limit);
            }
            foreach (['exercises', 'muscle_groups'] as $key) {
                if (array_key_exists($key, $exception)) $item[$key] = dailyTrainingTexts($exception[$key]);
            }
            return $item;
        }, 100);
        return $value;
    }, 20);
    if (count(array_unique(array_column($rules, 'id'))) !== count($rules)) throw new DailyInput('Schedule IDs must be unique.');
    return ['preferences' => $preferences, 'fixed_sessions' => $rules];
}

function dailyCoachMissingTable(PDOException $error): bool
{
    return $error->getCode() === '42S02' || str_contains($error->getMessage(), 'no such table: daily_coach_state');
}

function dailyCoachState(PDO $db): array
{
    try {
        $row = run($db, 'SELECT settings, updated_at FROM daily_coach_state WHERE id = 1')->fetch();
    } catch (PDOException $error) {
        if (!dailyCoachMissingTable($error)) throw $error;
        $row = false;
    }
    return ['settings' => $row ? json_decode($row['settings'], true, 32, JSON_THROW_ON_ERROR) : dailyCoachDefaults(),
        'updated_at' => $row['updated_at'] ?? null];
}

function dailyCoachTimestamp(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
}

function dailyCoachTouch(PDO $db): void
{
    try {
        run($db, 'UPDATE daily_coach_state SET updated_at = ? WHERE id = 1', [dailyCoachTimestamp()]);
    } catch (PDOException $error) {
        if (!dailyCoachMissingTable($error)) throw $error;
    }
}

function dailyCoachSaveSettings(PDO $db, array $input): array
{
    $settings = dailyCoachSettings($input);
    $db->exec('CREATE TABLE IF NOT EXISTS daily_coach_state (
        id INTEGER PRIMARY KEY, settings TEXT NOT NULL, updated_at VARCHAR(32) NOT NULL)');
    $previous = dailyCoachState($db);
    if ($settings === $previous['settings'] && $previous['updated_at'] !== null) return $previous;
    $json = json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $time = dailyCoachTimestamp();
    try {
        run($db, 'INSERT INTO daily_coach_state (id, settings, updated_at) VALUES (1, ?, ?)', [$json, $time]);
    } catch (PDOException $error) {
        if ($error->getCode() !== '23000') throw $error;
        run($db, 'UPDATE daily_coach_state SET settings = ?, updated_at = ? WHERE id = 1', [$json, $time]);
    }
    return ['settings' => $settings, 'updated_at' => $time];
}

function dailyCoachPlans(array $rules, string $from, string $through): array
{
    $plans = [];
    foreach ($rules as $rule) {
        $exceptions = array_column($rule['exceptions'], null, 'date');
        $candidates = array_keys($exceptions);
        for ($date = new DateTimeImmutable($from); $date->format('Y-m-d') <= $through; $date = $date->modify('+1 day')) {
            $day = $date->format('Y-m-d');
            if ((int)$date->format('N') === $rule['weekday'] && $day >= $rule['start_date']
                && ($rule['end_date'] === null || $day <= $rule['end_date'])) $candidates[] = $day;
        }
        foreach (array_unique($candidates) as $day) {
            $exception = $exceptions[$day] ?? [];
            if ($exception['cancelled'] ?? false) continue;
            $actual = $exception['move_to'] ?? $day;
            if ($actual < $from || $actual > $through) continue;
            $plan = array_replace($rule, $exception, ['id' => $rule['id'] . ':' . $day, 'date' => $actual, 'status' => 'planned',
                'fixed_session_id' => $rule['id']]);
            unset($plan['weekday'], $plan['start_date'], $plan['end_date'], $plan['exceptions'], $plan['cancelled'], $plan['move_to']);
            $plans[] = $plan;
        }
    }
    usort($plans, static fn($a, $b) => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
    return $plans;
}

function dailyCoachExercise(array $workout): array
{
    $legacy = isset($workout['sets']) ? ['sets' => $workout['sets'], 'complete' => true]
        : dailyLegacySets($workout['name'], $workout['details'] ?? null);
    $exercise = ['name' => $workout['name'], 'sets' => $legacy['sets']];
    if (!$legacy['complete']) $exercise['details'] = $workout['details'] ?? null;
    foreach (['rir', 'effort', 'notes'] as $key) if (isset($workout[$key])) $exercise[$key] = $workout[$key];
    return $exercise;
}

function dailyCoachRun(array $workout, string $id): array
{
    $distance = $workout['distance_m'] ?? null;
    if ($distance === null && preg_match('/\b(\d+(?:\.\d+)?)\s*km\b/i', $workout['details'] ?? '', $match)) {
        $distance = (float)$match[1] * 1000;
    }
    $session = ['id' => $id, 'type' => 'run', 'status' => 'completed', 'name' => $workout['name'],
        'venue' => $workout['venue'] ?? null, 'distance_m' => $distance, 'duration_s' => $workout['duration_s'] ?? null,
        'pace_s_per_km' => $workout['pace_s_per_km'] ?? null, 'avg_hr_bpm' => $workout['avg_hr_bpm'] ?? null];
    if (($workout['source'] ?? null) === 'garmin') $session['source'] = 'garmin';
    foreach (['details', 'effort', 'notes', 'fixed_session_id'] as $key) if (isset($workout[$key])) $session[$key] = $workout[$key];
    return $session;
}

function dailyCoachResponse(PDO $db, DateTimeImmutable $now, int $count): array
{
    $zone = $GLOBALS['config']['timezone'] ?? 'Asia/Kolkata';
    $now = $now->setTimezone(new DateTimeZone($zone));
    $today = $now->format('Y-m-d');
    $from = $now->modify('-' . ($count - 1) . ' days')->format('Y-m-d');
    $futureStart = $now->modify('+1 day')->format('Y-m-d');
    $futureEnd = $now->modify('+7 days')->format('Y-m-d');
    $state = dailyCoachState($db);
    $preferences = $state['settings']['preferences'];
    if (empty($preferences['training_goals'])) $preferences['training_goals'] = $GLOBALS['config']['training_goals'] ?? [];
    $preferences['available_session_minutes'] ??= $GLOBALS['config']['workout_minutes'] ?? null;
    $days = [];
    for ($date = new DateTimeImmutable($from); $date->format('Y-m-d') <= $today; $date = $date->modify('+1 day')) {
        $day = $date->format('Y-m-d');
        $days[$day] = ['date' => $day, 'training_status' => 'unlogged', 'rest_logged' => false,
            'completed' => [], 'planned' => [], 'recovery' => []];
    }
    $extraPlans = [];
    foreach (run($db, 'SELECT id, log_date, created_at, parsed FROM daily_notes WHERE log_date <= ? AND parsed IS NOT NULL
        AND (log_date >= ? OR parsed LIKE ?) ORDER BY created_at, id', [$today, $from, '%"planned_sessions"%']) as $row) {
        $parsed = json_decode($row['parsed'], true, 32, JSON_THROW_ON_ERROR);
        array_push($extraPlans, ...($parsed['planned_sessions'] ?? []));
        $date = $row['log_date'];
        if (!isset($days[$date])) continue;
        if ($parsed['rest'] ?? false) $days[$date]['rest_logged'] = true;
        foreach ($parsed['recovery'] ?? [] as $observation) {
            $observation['recorded_at'] = (new DateTimeImmutable($row['created_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
            $observation['soreness'] = (object)($observation['soreness'] ?? []);
            $days[$date]['recovery'][] = $observation;
        }
        foreach ($parsed['workouts'] ?? [] as $index => $workout) {
            // A legacy record may explicitly say planned; it must never become completed.
            if (($workout['status'] ?? 'completed') !== 'completed') continue;
            $id = substr(hash('sha256', $row['id'] . ':' . $index), 0, 12);
            if ($workout['type'] === 'run') {
                $days[$date]['completed'][] = dailyCoachRun($workout, 'run:' . $id);
                continue;
            }
            $key = $workout['type'] . ':' . ($workout['session_id'] ?? $workout['venue'] ?? 'unknown');
            $found = null;
            foreach ($days[$date]['completed'] as $sessionIndex => $session) {
                if (($session['_key'] ?? null) === $key) { $found = $sessionIndex; break; }
            }
            if ($found === null) {
                $found = count($days[$date]['completed']);
                $days[$date]['completed'][] = ['_key' => $key, 'id' => $workout['session_id'] ?? 'gym:' . $id,
                    'type' => $workout['type'], 'status' => 'completed', 'venue' => $workout['venue'] ?? null,
                    'duration_s' => null, 'exercises' => []];
            }
            $session = &$days[$date]['completed'][$found];
            if (isset($workout['fixed_session_id'])) $session['fixed_session_id'] = $workout['fixed_session_id'];
            if (isset($workout['duration_s'])) $session['duration_s'] = ($session['duration_s'] ?? 0) + $workout['duration_s'];
            $session['exercises'][] = dailyCoachExercise($workout);
            unset($session);
        }
    }
    foreach (run($db, 'SELECT activity_id, log_date, summary FROM daily_runs WHERE log_date >= ? AND log_date <= ? ORDER BY activity_id', [$from, $today]) as $row) {
        $run = dailyCoachRun(json_decode($row['summary'], true, 32, JSON_THROW_ON_ERROR), 'garmin:' . $row['activity_id']);
        $sessions = &$days[$row['log_date']]['completed'];
        // Match each Garmin record to at most one overlapping manual run, just as the calendar does.
        foreach ($sessions as $index => $manual) {
            if ($manual['type'] === 'run' && ($manual['source'] ?? null) !== 'garmin'
                && $manual['distance_m'] !== null && $run['distance_m'] !== null
                && abs($manual['distance_m'] - $run['distance_m']) <= 50
                && ($manual['duration_s'] === null || $run['duration_s'] === null || abs($manual['duration_s'] - $run['duration_s']) <= 60)) {
                foreach (['effort', 'notes', 'details', 'venue', 'fixed_session_id'] as $key) if (isset($manual[$key]) && !isset($run[$key])) $run[$key] = $manual[$key];
                unset($sessions[$index]);
                break;
            }
        }
        $sessions = array_values($sessions);
        $sessions[] = $run;
        unset($sessions);
    }
    $plans = dailyCoachPlans($state['settings']['fixed_sessions'], $from, $futureEnd);
    $plans = array_merge($plans, $extraPlans);
    foreach (function_exists('dailyCoachAiPlans') ? dailyCoachAiPlans($db, $from, $futureEnd) : [] as $recommendation) {
        if (isset($days[$recommendation['date']])) $days[$recommendation['date']]['recommendation'] = $recommendation;
    }
    $upcoming = [];
    foreach ($plans as $plan) {
        if ($plan['date'] > $today && $plan['date'] <= $futureEnd) { $upcoming[$plan['id']] = $plan; continue; }
        if (!isset($days[$plan['date']])) continue;
        $matched = false;
        foreach ($days[$plan['date']]['completed'] as &$session) {
            $fixed = $plan['fixed_session_id'] ?? $plan['id'];
            $matches = isset($session['fixed_session_id']) ? $session['fixed_session_id'] === $fixed
                : ($plan['venue'] !== null && isset($session['venue']) && strcasecmp($plan['venue'], $session['venue']) === 0);
            if ($session['type'] === $plan['type'] && $matches) {
                $session['fixed_session_id'] = $fixed;
                $matched = true;
                break;
            }
        }
        unset($session);
        if (!$matched) $days[$plan['date']]['planned'][$plan['id']] = $plan;
    }
    foreach ($days as &$day) {
        $day['completed'] = array_values($day['completed']);
        $day['planned'] = array_values($day['planned']);
        foreach ($day['completed'] as &$session) unset($session['_key']);
        unset($session);
        $day['training_status'] = count($day['completed']) ? 'completed' : ($day['rest_logged'] ? 'rest' : 'unlogged');
    }
    unset($day);
    $upcoming = array_values($upcoming);
    usort($upcoming, static fn($a, $b) => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
    return ['schema_version' => 1, 'timezone' => $zone, 'today' => $today,
        'range' => ['from' => $from, 'through' => $today, 'days' => $count],
        'last_updated_at' => $state['updated_at'], 'days' => array_values($days),
        'upcoming_range' => ['from' => $futureStart, 'through' => $futureEnd],
        'upcoming_fixed' => $upcoming, 'preferences' => $preferences];
}
