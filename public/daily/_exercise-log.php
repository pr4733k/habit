<?php
declare(strict_types=1);

final class DailyExerciseConflict extends RuntimeException {}

// Only equivalent names are aliases. Incline/flat, machine/free weights and reverse/chest fly stay distinct.
function dailyExerciseKey(string $name): string {
    $key = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($name)));
    $key = preg_replace('/\bdb\b/u', 'dumbbell', $key);
    return match ($key) {
        'bench press', 'flat bench press', 'flat barbell bench press', 'flat barbell bench press station' => 'barbell bench press',
        'dumbbell bench press', 'flat dumbbell bench press', 'dumbbell chest press' => 'dumbbell chest press',
        'pec deck', 'pec deck fly', 'pec deck chest fly', 'chest fly machine' => 'pec deck chest fly',
        'high pulley triceps pushdown', 'high pulley triceps pushdowns', 'triceps pushdowns', 'triceps pushdown' => 'triceps pushdown',
        'plate loaded seated chest press', 'plate loaded chest press' => 'plate loaded chest press',
        'plate loaded seated dip machine', 'seated dip machine' => 'seated dip machine',
        default => $key,
    };
}

function dailyExerciseId(string $date, string $key): string {
    $hash = hash('sha256', 'habit-exercise:' . $date . ':' . $key);
    return substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-5' . substr($hash, 13, 3) . '-8' . substr($hash, 17, 3) . '-' . substr($hash, 20, 12);
}

function dailyRecordedExerciseKey(array $workout): string {
    $key = dailyExerciseKey($workout['name']);
    if ($key === 'chest press') {
        $sets = $workout['sets'] ?? dailyLegacySets($workout['name'], $workout['details'] ?? null)['sets'];
        if ($sets && count(array_filter($sets, static fn($set) => ($set['weight_basis'] ?? null) !== 'per_dumbbell')) === 0) return 'dumbbell chest press';
    }
    return $key;
}

function dailyExerciseGroups(array $sets): array {
    $groups = [];
    foreach ($sets as $set) {
        if (($set['warmup'] ?? null) === true) continue;
        $value = array_intersect_key($set, array_flip(['weight', 'reps', 'weight_unit', 'weight_basis']));
        $value += ['weight' => null, 'reps' => null, 'weight_unit' => null, 'weight_basis' => null];
        $last = count($groups) - 1;
        if ($last >= 0 && array_diff_assoc($value, $groups[$last]) === []) $groups[$last]['sets']++;
        else $groups[] = $value + ['sets' => 1];
    }
    return $groups;
}

function dailyExerciseEntry(array $row): ?array {
    $parsed = $row['parsed'] ? json_decode($row['parsed'], true, 32, JSON_THROW_ON_ERROR) : null;
    $workout = $parsed['workouts'][0] ?? null;
    if (!$workout || count($parsed['workouts']) !== 1 || $workout['type'] !== 'gym') return null;
    return ['id' => $row['id'], 'date' => $row['log_date'], 'revision' => (int)$row['revision'],
        'key' => dailyExerciseKey($workout['name']), 'name' => $workout['name'], 'workout' => $workout,
        'groups' => dailyExerciseGroups($workout['sets'] ?? dailyLegacySets($workout['name'], $workout['details'] ?? null)['sets'])];
}

function dailyWorkoutSession(PDO $db, string $date): array {
    $plan = dailyReadPlan($db, $date)['plan'];
    $items = [];
    foreach ($plan['exercises'] ?? [] as $exercise) {
        $key = dailyExerciseKey($exercise['name']);
        $items[$key] ??= ['key' => $key, 'name' => $exercise['name'], 'planned' => $exercise, 'previous' => null, 'saved' => null];
    }
    // Include saved exercises even if the recommendation is subsequently regenerated.
    foreach (run($db, 'SELECT * FROM daily_notes WHERE log_date = ? AND parsed IS NOT NULL ORDER BY created_at, id', [$date]) as $row) {
        $parsed = json_decode($row['parsed'], true, 32, JSON_THROW_ON_ERROR);
        foreach ($parsed['workouts'] ?? [] as $workout) {
            if ($workout['type'] !== 'gym' || ($workout['status'] ?? 'completed') !== 'completed') continue;
            $key = dailyRecordedExerciseKey($workout);
            if (isset($items[$key]) && $row['id'] !== dailyExerciseId($date, $key)) {
                $items[$key]['saved'] = ['id' => $row['id'], 'date' => $date, 'revision' => (int)$row['revision'], 'external' => true,
                    'key' => $key, 'name' => $workout['name'], 'workout' => $workout,
                    'groups' => dailyExerciseGroups($workout['sets'] ?? dailyLegacySets($workout['name'], $workout['details'] ?? null)['sets'])];
            }
        }
        if (!isset($parsed['workout_log'])) continue;
        $entry = dailyExerciseEntry($row);
        if (!$entry) continue;
        $key = $entry['key'];
        $items[$key] ??= ['key' => $key, 'name' => $entry['name'], 'planned' => null, 'previous' => null, 'saved' => null];
    }
    foreach ($items as $key => &$item) {
        $row = run($db, 'SELECT * FROM daily_notes WHERE id = ? AND log_date = ?', [dailyExerciseId($date, $key), $date])->fetch();
        if ($row) $item['saved'] = dailyExerciseEntry($row);
    }
    unset($item);
    $remaining = array_fill_keys(array_keys($items), true);
    foreach (run($db, 'SELECT log_date, parsed FROM daily_notes WHERE log_date < ? AND parsed IS NOT NULL ORDER BY log_date DESC, created_at DESC, id DESC', [$date]) as $row) {
        if (!$remaining) break;
        $parsed = json_decode($row['parsed'], true, 32, JSON_THROW_ON_ERROR);
        foreach ($parsed['workouts'] ?? [] as $workout) {
            if ($workout['type'] !== 'gym' || ($workout['status'] ?? 'completed') !== 'completed') continue;
            $sets = isset($workout['sets']) ? ['sets' => $workout['sets'], 'complete' => true] : dailyLegacySets($workout['name'], $workout['details'] ?? null);
            $key = dailyRecordedExerciseKey($workout);
            if (!isset($remaining[$key])) continue;
            $groups = dailyExerciseGroups($sets['sets']);
            if (!$groups && $sets['complete']) continue;
            $items[$key]['previous'] = ['date' => $row['log_date'], 'name' => $workout['name'], 'groups' => $groups,
                'complete' => $sets['complete'], 'details' => $workout['details'] ?? null];
            unset($remaining[$key]);
        }
    }
    return ['date' => $date, 'title' => ($plan['kind'] ?? 'lift') === 'lift' ? ($plan['title'] ?? 'Workout') : 'Workout', 'venue' => $plan['venue'] ?? null,
        'minutes' => $plan['minutes'] ?? null, 'kind' => $plan['kind'] ?? null, 'reason' => $plan['reason'] ?? null, 'exercises' => array_values($items)];
}

function dailySaveExercise(PDO $db, array $input, string $today): array {
    $date = logDate($input['date'] ?? null);
    if ($date < DAILY_START_DATE || $date > $today) throw new DailyInput('Select a date within the tracking period.');
    $name = dailyText($input['name'] ?? null, 160);
    $key = dailyExerciseKey($name);
    if ($key === '') throw new DailyInput('Enter an exercise name.');
    $id = dailyExerciseId($date, $key);
    $revision = isset($input['revision']) ? noteRevision($input['revision']) : null;
    $venue = dailyOptionalText($input['venue'] ?? null, 160);
    $sets = []; $lines = [];
    $groups = dailyItems($input['groups'] ?? [], static function ($group) {
        $value = dailyTrainingSet($group);
        $count = dailyNumber($group['sets'] ?? null, 30, true);
        if ($count < 1 || $value['reps'] === 0) throw new DailyInput('Enter at least one rep and set.');
        if ($value['weight_basis'] === 'bodyweight') {
            if ($value['weight'] !== null || $value['weight_unit'] !== null) throw new DailyInput('Use added weight or assistance to record a load with bodyweight.');
        } elseif ($value['weight'] !== null && $value['weight_unit'] === null) throw new DailyInput('Choose kg or lb for the recorded weight.');
        return $value + ['count' => $count];
    }, 20);
    if (!$groups) throw new DailyInput('Add at least one set.');
    foreach ($groups as $group) {
        $count = $group['count']; unset($group['count']);
        if (count($sets) + $count > 100) throw new DailyInput('Record at most 100 sets per exercise.');
        for ($i = 0; $i < $count; $i++) $sets[] = $group;
        $basis = match ($group['weight_basis']) {
            'per_dumbbell' => ' per dumbbell', 'per_side' => ' per side', 'total' => ' total',
            'assistance' => ' assistance', 'added_weight' => ' added weight', default => '',
        };
        $load = $group['weight_basis'] === 'bodyweight' ? 'Bodyweight' : ($group['weight'] === null ? 'Weight not logged' : $group['weight'] . ' ' . $group['weight_unit'] . $basis);
        $lines[] = $load . ' × ' . ($group['reps'] === null ? 'reps not logged' : $group['reps'] . ' reps') . ' × ' . $count . ($count === 1 ? ' set' : ' sets');
    }
    $details = implode("\n", $lines);
    $parsed = dailyResult(['workouts' => [['type' => 'gym', 'name' => $name, 'details' => $details,
        'sets' => $sets, 'session_id' => 'habit:' . $date, 'venue' => $venue]]]);
    $parsed['workout_log'] = ['key' => $key];
    $json = json_encode($parsed, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $raw = 'Gym' . ($venue ? ' at ' . $venue : '') . ': ' . $name . "\n" . $details;
    $read = static fn() => run($db, 'SELECT * FROM daily_notes WHERE id = ?', [$id])->fetch();
    $current = $read();
    $same = static fn($row) => $row && $row['log_date'] === $date && $row['raw_note'] === $raw && $row['parsed'] !== null && json_decode($row['parsed'], true, 32, JSON_THROW_ON_ERROR) == $parsed;
    if ($same($current)) return dailyExerciseEntry($current);
    if ($current) {
        $oldParsed = $current['parsed'] ? json_decode($current['parsed'], true, 32, JSON_THROW_ON_ERROR) : [];
        foreach (['meals', 'water', 'alcohol', 'study', 'recovery', 'planned_sessions'] as $category) {
            if (!empty($oldParsed[$category])) throw new DailyExerciseConflict('This entry contains other logs. Edit the original note to keep them together.');
        }
        $entry = dailyExerciseEntry($current);
        if (!$entry || $entry['key'] !== $key || $entry['date'] !== $date || $revision !== (int)$current['revision']) throw new DailyExerciseConflict('This exercise changed elsewhere. Reload saved values before replacing it.');
        if (!run($db, 'UPDATE daily_notes SET raw_note = ?, parsed = ?, revision = revision + 1 WHERE id = ? AND revision = ?', [$raw, $json, $id, $revision])->rowCount()) {
            $latest = $read();
            if ($same($latest)) return dailyExerciseEntry($latest);
            throw new DailyExerciseConflict('This exercise changed elsewhere. Reload saved values before replacing it.');
        }
    } else {
        if ($revision !== null) throw new DailyExerciseConflict('This exercise no longer exists. Reload saved values first.');
        foreach (run($db, 'SELECT id, parsed FROM daily_notes WHERE log_date = ? AND parsed IS NOT NULL', [$date]) as $row) {
            if ($row['id'] === $id) continue;
            foreach (json_decode($row['parsed'], true, 32, JSON_THROW_ON_ERROR)['workouts'] ?? [] as $workout) {
                if ($workout['type'] === 'gym' && ($workout['status'] ?? 'completed') === 'completed' && dailyRecordedExerciseKey($workout) === $key) {
                    throw new DailyExerciseConflict('This exercise was already logged today. Edit the original entry to avoid counting it twice.');
                }
            }
        }
        try {
            run($db, 'INSERT INTO daily_notes (id, log_date, raw_note, revision, created_at, parsed) VALUES (?, ?, ?, 1, ?, ?)',
                [$id, $date, $raw, (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'), $json]);
        } catch (PDOException $error) {
            if ($error->getCode() !== '23000') throw $error;
            $latest = $read();
            if ($same($latest)) return dailyExerciseEntry($latest);
            throw new DailyExerciseConflict('This exercise was saved elsewhere. Reload saved values first.');
        }
    }
    dailyCoachTouch($db);
    return dailyExerciseEntry($read());
}
