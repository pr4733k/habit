<?php
declare(strict_types=1);


final class DailyInput extends RuntimeException {}
require __DIR__ . '/_result.php';
require_once __DIR__ . '/_training.php';
require __DIR__ . '/_coach.php';

const DAILY_START_DATE = '0001-01-01';

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function body(): array
{
    $input = file_get_contents('php://input', false, null, 0, 65537);
    if ($input === false || strlen($input) > 65536) {
        throw new DailyInput('This request is too large.');
    }
    $data = json_decode($input, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new DailyInput('Supply a JSON object.');
    }
    return $data;
}

function logDate(mixed $value): string
{
    if (!is_string($value) || !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $parts)
        || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
        throw new DailyInput('Select a valid date.');
    }
    return $value;
}

function noteId(mixed $value): string
{
    if (!is_string($value) || !preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/D', $value)) {
        throw new DailyInput('Invalid note ID.');
    }
    return $value;
}

function noteRevision(mixed $value): int
{
    $revision = dailyNumber($value, 1000000, true);
    if ($revision === 0) {
        throw new DailyInput('Invalid note revision.');
    }
    return $revision;
}

function run(PDO $db, string $sql, array $params = []): PDOStatement
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function privateNote(array $row): array
{
    global $db, $config;
    $status = isset($db, $config) && function_exists('dailyProcessor') && dailyProcessor($config) === 'openrouter'
        ? ['processing' => $row['parsed'] !== null ? ['state' => 'complete', 'error' => null] : dailyJobStatus($db, $row['id'], (int)$row['revision'])] : [];
    return $status + [
        'id' => $row['id'],
        'date' => $row['log_date'],
        'text' => $row['raw_note'],
        'revision' => (int)$row['revision'],
        'defaultMeal' => dailyDefaultMeal($row['created_at']),
        'parsed' => $row['parsed'] === null ? null : json_decode($row['parsed'], true, 32, JSON_THROW_ON_ERROR),
    ];
}

function getNote(PDO $db, string $id): array
{
    $row = run($db, 'SELECT * FROM daily_notes WHERE id = ?', [$id])->fetch();
    if (!$row) {
        respond(['error' => 'This note could not be found.'], 404);
    }
    return privateNote($row);
}

function saveNote(PDO $db, array $data, string $today): array
{
    $id = noteId($data['id'] ?? null);
    $date = logDate($data['date'] ?? null);
    if ($date > $today) {
        throw new DailyInput('Select today or an earlier date.');
    }
    $text = dailyText($data['text'] ?? null, 12000);
    $revision = isset($data['revision']) ? noteRevision($data['revision']) : null;
    if ($revision === null) {
        try {
            run($db, 'INSERT INTO daily_notes (id, log_date, raw_note, revision, created_at) VALUES (?, ?, ?, 1, ?)',
                [$id, $date, $text, (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')]);
        } catch (PDOException $error) {
            if ($error->getCode() !== '23000') {
                throw $error;
            }
        }
    } else {
        $previous = getNote($db, $id);
        if ($previous['date'] !== $date || $previous['text'] !== $text) {
            run($db, 'UPDATE daily_notes SET log_date = ?, raw_note = ?, revision = revision + 1, parsed = NULL
                WHERE id = ? AND revision = ?', [$date, $text, $id, $revision]);
        }
    }
    $note = getNote($db, $id);
    if ($note['date'] !== $date || $note['text'] !== $text) {
        respond(['error' => 'This note changed. Reload it before saving.'], 409);
    }
    return $note;
}

function addWater(PDO $db, array $data, string $today): array
{
    $id = noteId($data['id'] ?? null);
    $date = logDate($data['date'] ?? null);
    if ($date < DAILY_START_DATE || $date > $today) {
        throw new DailyInput('Select a date within the tracking period.');
    }
    $text = '100 mL water';
    $parsed = dailyResult(['water' => [['mode' => 'add', 'ml' => 100]]]);
    try {
        run($db, 'INSERT INTO daily_notes (id, log_date, raw_note, revision, created_at, parsed)
            VALUES (?, ?, ?, 1, ?, ?)', [$id, $date, $text,
            (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
            json_encode($parsed, JSON_THROW_ON_ERROR)]);
    } catch (PDOException $error) {
        if ($error->getCode() !== '23000') {
            throw $error;
        }
    }
    $note = getNote($db, $id);
    if ($note['date'] !== $date || $note['revision'] !== 1 || $note['text'] !== $text || $note['parsed'] !== $parsed) {
        respond(['error' => 'This water entry has already been used.'], 409);
    }
    return $note;
}

function commitNote(PDO $db, array $data): array
{
    $id = noteId($data['id'] ?? null);
    $revision = noteRevision($data['revision'] ?? null);
    $note = getNote($db, $id);
    if ($note['revision'] !== $revision) {
        respond(['error' => 'A newer revision is pending. Fetch the note again.'], 409);
    }
    if ($note['parsed'] !== null) {
        return $note;
    }
    $json = json_encode(dailyResult($data['parsed'] ?? null, $note['defaultMeal']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    if (strlen($json) > 60000) {
        throw new DailyInput('This result is too large.');
    }
    run($db, 'UPDATE daily_notes SET parsed = ? WHERE id = ? AND revision = ? AND parsed IS NULL',
        [$json, $id, $revision]);
    $note = getNote($db, $id);
    if ($note['revision'] !== $revision) {
        respond(['error' => 'A newer revision is pending. Fetch the note again.'], 409);
    }
    return $note;
}

function syncRequest(PDO $db, string $operation, array $data = []): array
{
    if ($operation === 'request') {
        // Bootstrap the additive table on first use for existing installations.
        $db->exec('CREATE TABLE IF NOT EXISTS daily_sync_request (
            id INTEGER PRIMARY KEY, state VARCHAR(8) NOT NULL, lease VARCHAR(32) DEFAULT NULL,
            updated_at BIGINT NOT NULL)');
    }
    try {
        $row = run($db, 'SELECT state, lease, updated_at FROM daily_sync_request WHERE id = 1')->fetch();
    } catch (PDOException $error) {
        if ($error->getCode() === '42S02' || str_contains($error->getMessage(), 'no such table: daily_sync_request')) {
            return ['state' => 'idle'];
        }
        throw $error;
    }
    $state = $row['state'] ?? 'idle';
    $now = time();
    if (in_array($state, ['queued', 'claimed', 'sent'], true)) {
        // The existing note queue is the source of truth for completion.
        $expired = ($state === 'claimed' && $now - (int)$row['updated_at'] > 90)
            || ($state === 'sent' && $now - (int)$row['updated_at'] > 900);
        $updated = run($db, "UPDATE daily_sync_request SET state = 'complete', lease = NULL, updated_at = ?
            WHERE id = 1 AND state = ? AND updated_at = ?
            AND NOT EXISTS (SELECT 1 FROM daily_notes WHERE parsed IS NULL)",
            [$now, $state, $row['updated_at']])->rowCount();
        if ($updated) {
            $state = 'complete';
        } elseif ($expired && run($db, "UPDATE daily_sync_request SET state = 'failed', lease = NULL, updated_at = ?
            WHERE id = 1 AND state = ? AND updated_at = ?", [$now, $state, $row['updated_at']])->rowCount()) {
            $state = 'failed';
        }
    }
    if ($operation === 'status' && $state === 'complete'
        && run($db, 'SELECT 1 FROM daily_notes WHERE parsed IS NULL LIMIT 1')->fetchColumn()) {
        $state = 'idle';
    }
    if ($operation === 'request' && !in_array($state, ['queued', 'claimed', 'sent'], true)) {
        if (!run($db, 'SELECT 1 FROM daily_notes WHERE parsed IS NULL LIMIT 1')->fetchColumn()) {
            return ['state' => 'complete'];
        }
        if (!$row) {
            try {
                run($db, "INSERT INTO daily_sync_request (id, state, updated_at) VALUES (1, 'queued', ?)", [$now]);
            } catch (PDOException $error) {
                if ($error->getCode() !== '23000') {
                    throw $error;
                }
            }
        } else {
            run($db, "UPDATE daily_sync_request SET state = 'queued', lease = NULL, updated_at = ?
                WHERE id = 1 AND state IN ('complete', 'failed')", [$now]);
        }
        return ['state' => 'queued'];
    }
    if ($operation === 'claim' && $state === 'queued') {
        $lease = bin2hex(random_bytes(16));
        if (run($db, "UPDATE daily_sync_request SET state = 'claimed', lease = ?, updated_at = ?
            WHERE id = 1 AND state = 'queued'", [$lease, $now])->rowCount()) {
            return ['state' => 'claimed', 'lease' => $lease];
        }
    }
    if (in_array($operation, ['sent', 'failed'], true)) {
        $lease = $data['lease'] ?? null;
        if (!is_string($lease) || !preg_match('/^[0-9a-f]{32}$/D', $lease)) {
            throw new DailyInput('Invalid sync lease.');
        }
        if (run($db, "UPDATE daily_sync_request SET state = ?, updated_at = ?
            WHERE id = 1 AND state = 'claimed' AND lease = ?", [$operation, $now, $lease])->rowCount()) {
            $state = $operation;
        }
    }
    return ['state' => $operation === 'status' && $state === 'queued' && $now - (int)$row['updated_at'] > 30
        ? 'waiting' : $state];
}

function rememberLog(string $token, array $config): void
{
    setcookie('daily_log_access', $token, [
        'expires' => time() + 365 * 24 * 60 * 60,
        'path' => '/daily', 'httponly' => true, 'samesite' => 'Strict', 'secure' => !$config['local'],
    ]);
}

function logSignature(string $nonce, array $config): string
{
    return hash_hmac('sha256', 'daily-log:' . $nonce, $config['password_hash']);
}

function browserAccess(array $config, string $method, string $action): void
{
    if ($method !== 'GET' && ($_SERVER['HTTP_X_DAILY_REQUEST'] ?? '') !== '1') {
        respond(['error' => 'Invalid browser request.'], 403);
    }
    if ($action === 'login' && $method === 'POST') {
        ini_set('session.use_strict_mode', '1');
        session_name('daily_login_attempts');
        session_set_cookie_params([
            'path' => '/daily', 'httponly' => true, 'samesite' => 'Strict', 'secure' => !$config['local'],
        ]);
        session_start();
        if (($_SESSION['try_after'] ?? 0) > time()) {
            respond(['error' => 'Wait a moment before trying again.'], 429);
        }
        $password = dailyText(body()['password'] ?? null, 512);
        if (!password_verify($password, $config['password_hash'])) {
            $_SESSION['failed'] = ($_SESSION['failed'] ?? 0) + 1;
            $_SESSION['try_after'] = time() + min(30, $_SESSION['failed'] * 2);
            respond(['error' => 'That password didn’t match.'], 401);
        }
        $_SESSION = [];
        session_destroy();
        $nonce = bin2hex(random_bytes(32));
        rememberLog($nonce . '.' . logSignature($nonce, $config), $config);
        respond(['signedIn' => true]);
    }
    $token = $_COOKIE['daily_log_access'] ?? '';
    $unlocked = is_string($token) && preg_match('/^([0-9a-f]{64})\.([0-9a-f]{64})$/D', $token, $parts)
        && hash_equals(logSignature($parts[1], $config), $parts[2]);
    if ($action === 'session' && $method === 'GET') {
        if ($unlocked) {
            rememberLog($token, $config);
        }
        respond(['signedIn' => $unlocked]);
    }
    if (!$unlocked) {
        respond(['error' => 'Unlock your daily log first.'], 401);
    }
}

function dailyGoals(PDO $db, string $date): ?array
{
    $goals = run($db, 'SELECT protein, carbs, fat, water_ml FROM daily_goal_targets
        WHERE effective_date <= ? ORDER BY effective_date DESC LIMIT 1', [$date])->fetch();
    return $goals ? array_map('intval', $goals) : null;
}

function dailySnapshot(PDO $db, string $date): array
{
    $day = [
        'date' => $date, 'goals' => dailyGoals($db, $date),
        'totals' => ['kcal' => null, 'protein' => null, 'carbs' => null, 'fat' => null],
        'waterMl' => null, 'studyMinutes' => 0,
        'meals' => [], 'workouts' => [], 'alcohol' => [], 'study' => [], 'pending' => [],
    ];
    $rows = run($db, 'SELECT id, revision, raw_note, parsed FROM daily_notes
        WHERE log_date = ? ORDER BY created_at, id', [$date]);
    foreach ($rows as $row) {
        if ($row['parsed'] === null) {
            $day['pending'][] = ['id' => $row['id'], 'revision' => (int)$row['revision'], 'text' => $row['raw_note']];
            continue;
        }
        $parsed = json_decode($row['parsed'], true, 32, JSON_THROW_ON_ERROR);
        foreach (['meals', 'workouts', 'alcohol', 'study'] as $group) {
            array_push($day[$group], ...($parsed[$group] ?? []));
        }
        foreach ($parsed['water'] ?? [] as $water) {
            $day['waterMl'] = $water['mode'] === 'total' ? $water['ml'] : ($day['waterMl'] ?? 0) + $water['ml'];
        }
    }
    foreach (array_merge($day['meals'], $day['alcohol']) as $item) {
        foreach ($day['totals'] as $key => $total) {
            $value = $item['nutrition'][$key] ?? null;
            if ($value !== null) {
                $day['totals'][$key] = ($total ?? 0) + $value;
            }
        }
    }
    $day['totals'] = array_map(static fn ($value) => $value === null ? null : round($value, 2), $day['totals']);
    $day['studyMinutes'] = array_sum(array_column($day['study'], 'minutes'));
    foreach (run($db, 'SELECT summary FROM daily_runs WHERE log_date = ? ORDER BY activity_id', [$date]) as $activity) {
        $day['workouts'][] = json_decode($activity['summary'], true, 32, JSON_THROW_ON_ERROR);
    }
    return $day;
}

function monthGoalTargets(PDO $db, string $start, string $end): array
{
    $rows = run($db, 'SELECT effective_date, protein, carbs, fat, water_ml FROM daily_goal_targets
        WHERE effective_date < ? AND (effective_date >= ? OR effective_date = (
            SELECT MAX(effective_date) FROM daily_goal_targets WHERE effective_date < ?
        ))
        ORDER BY effective_date', [$end, $start, $start])->fetchAll();
    return array_map(static function (array $row): array {
        $effectiveDate = $row['effective_date'];
        unset($row['effective_date']);
        return ['effectiveDate' => $effectiveDate, 'goals' => array_map('intval', $row)];
    }, $rows);
}

function syncRuns(PDO $db, array $data, string $today): int
{
    $runs = dailyItems($data['runs'] ?? null, static function ($item) use ($today) {
        $id = $item['activity_id'] ?? '';
        if (!is_string($id) || !preg_match('/^\d{1,32}$/D', $id)) {
            throw new DailyInput('Invalid Garmin activity ID.');
        }
        $date = logDate($item['date'] ?? null);
        if ($date < DAILY_START_DATE || $date > $today) {
            throw new DailyInput('Run date is outside the tracking period.');
        }
        $summary = dailyWorkout(['type' => 'run'] + $item) + ['source' => 'garmin'];
        return [$id, $date, json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
    }, 100);
    $db->beginTransaction();
    try {
        foreach ($runs as [$id, $date, $summary]) {
            try {
                run($db, 'INSERT INTO daily_runs (activity_id, log_date, summary) VALUES (?, ?, ?)', [$id, $date, $summary]);
            } catch (PDOException $error) {
                if ($error->getCode() !== '23000') {
                    throw $error;
                }
                run($db, 'UPDATE daily_runs SET log_date = ?, summary = ? WHERE activity_id = ?', [$date, $summary, $id]);
            }
        }
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
    return count($runs);
}
