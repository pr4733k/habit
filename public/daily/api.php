<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

final class DailyInput extends RuntimeException {}
require __DIR__ . '/_result.php';

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
    return [
        'id' => $row['id'],
        'date' => $row['log_date'],
        'text' => $row['raw_note'],
        'revision' => (int)$row['revision'],
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
    if ($date > $today) {
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
    $json = json_encode(dailyResult($data['parsed'] ?? null), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
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
    $row = run($db, 'SELECT state, lease, updated_at FROM daily_sync_request WHERE id = 1')->fetch();
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
    return hash_hmac('sha256', 'daily-log:' . $nonce, $config['session_key']);
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

function trackingStart(PDO $db, string $today): string
{
    $dates = run($db, 'SELECT MIN(log_date) AS first_date FROM daily_notes
        UNION ALL SELECT MIN(log_date) FROM daily_runs
        UNION ALL SELECT MIN(effective_date) FROM daily_goal_targets')->fetchAll(PDO::FETCH_COLUMN);
    return min([$today, ...array_filter($dates)]);
}

function syncRuns(PDO $db, array $data, string $today): int
{
    $runs = dailyItems($data['runs'] ?? null, static function ($item) use ($today) {
        $id = $item['activity_id'] ?? '';
        if (!is_string($id) || !preg_match('/^\d{1,32}$/D', $id)) {
            throw new DailyInput('Invalid Garmin activity ID.');
        }
        $date = logDate($item['date'] ?? null);
        if ($date > $today) {
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

try {
    $configPath = PHP_SAPI === 'cli-server' ? '/.daily-data/config.php' : '/daily-config.php';
    $configFile = getenv('DAILY_CONFIG') ?: dirname($_SERVER['DOCUMENT_ROOT']) . $configPath;
    if (!is_file($configFile)) {
        respond(['error' => 'The daily tracker API needs local or hosting configuration.'], 503);
    }
    $config = (require $configFile) + [
        'local' => false, 'user' => null, 'password' => null,
        'timezone' => 'UTC', 'public_calendar' => false, 'auto_sync' => false,
    ];
    $action = $_GET['action'] ?? 'session';
    if (!is_string($action)) {
        throw new DailyInput('Invalid tracker request.');
    }
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET' && $action === 'settings') {
        respond([
            'timeZone' => $config['timezone'], 'publicCalendar' => $config['public_calendar'] === true,
            'autoSync' => $config['auto_sync'] === true,
        ]);
    }
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (in_array($action, ['pending', 'commit', 'runs', 'sync-bridge'], true) || ($action === 'day' && $method === 'GET' && $authorization !== '')) {
        $token = str_starts_with($authorization, 'Bearer ') ? substr($authorization, 7) : '';
        if ($token === '' || !hash_equals($config['sync_token_hash'], hash('sha256', $token))) {
            respond(['error' => 'A valid processor token is required.'], 401);
        }
    } elseif ($method !== 'GET' || $action !== 'month' || $config['public_calendar'] !== true) {
        browserAccess($config, $method, $action);
    }
    $db = new PDO($config['dsn'], $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $db->exec('PRAGMA busy_timeout = 4000');
    }
    $today = (new DateTimeImmutable('now', new DateTimeZone($config['timezone'])))->format('Y-m-d');

    switch ($method . ' ' . $action) {
        case 'GET pending':
            $rows = run($db, 'SELECT * FROM daily_notes WHERE parsed IS NULL ORDER BY created_at, id LIMIT 25')->fetchAll();
            respond(['notes' => array_map('privateNote', $rows)]);
        case 'POST commit':
            $note = commitNote($db, body());
            try {
                syncRequest($db, 'status');
            } catch (PDOException $error) {
                error_log('Daily sync status error (' . $error->getCode() . ')');
            }
            respond(['note' => $note]);
        case 'GET runs':
            respond(['today' => $today]);
        case 'POST runs':
            respond(['synced' => syncRuns($db, body(), $today)]);
        case 'GET note':
            respond(['note' => getNote($db, noteId($_GET['id'] ?? null))]);
        case 'GET notes':
            $date = logDate($_GET['date'] ?? null);
            $rows = run($db, 'SELECT * FROM daily_notes WHERE log_date = ? ORDER BY created_at DESC, id', [$date])->fetchAll();
            respond(['notes' => array_map('privateNote', $rows)]);
        case 'POST save':
            $note = saveNote($db, body(), $today);
            $sync = null;
            if ($note['parsed'] === null && $config['auto_sync'] === true) {
                try {
                    $sync = syncRequest($db, 'request');
                } catch (PDOException $error) {
                    error_log('Daily sync request error (' . $error->getCode() . ')');
                    $sync = ['state' => 'failed'];
                }
            }
            respond(['note' => $note, 'sync' => $sync]);
        case 'GET sync':
            respond(syncRequest($db, 'status'));
        case 'POST sync':
            respond(syncRequest($db, 'request'));
        case 'POST sync-bridge':
            $data = body();
            $operation = $data['operation'] ?? null;
            if (!in_array($operation, ['claim', 'sent', 'failed'], true)) {
                throw new DailyInput('Invalid sync operation.');
            }
            respond(syncRequest($db, $operation, $data));
        case 'POST water':
            respond(['note' => addWater($db, body(), $today)]);
        case 'GET widget':
        case 'GET day':
            $date = logDate($_GET['date'] ?? null);
            if ($date > $today) {
                respond(['error' => 'This date is outside the tracking period.'], 404);
            }
            $day = dailySnapshot($db, $date);
            if ($action === 'widget') {
                $day['pendingCount'] = count($day['pending']);
                unset($day['pending']);
            }
            respond($day);
        case 'GET month':
            $month = $_GET['month'] ?? '';
            if (!is_string($month)) {
                throw new DailyInput('Select a valid month.');
            }
            $start = logDate($month . '-01');
            if ($start > $today) {
                respond(['error' => 'The calendar is available through the current month.'], 404);
            }
            $end = (new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');
            $range = [$start, $end];
            $rows = run($db, 'SELECT log_date, parsed FROM daily_notes WHERE log_date >= ? AND log_date < ?
                AND parsed IS NOT NULL ORDER BY log_date, created_at, id', $range)->fetchAll();
            $entries = [];
            $fields = array_flip(['meals', 'workouts', 'water', 'alcohol', 'study']);
            foreach ($rows as $row) {
                $parsed = array_intersect_key(json_decode($row['parsed'], true, 32, JSON_THROW_ON_ERROR), $fields);
                if (array_filter($parsed)) {
                    $entries[] = ['date' => $row['log_date'], 'parsed' => $parsed];
                }
            }
            $runs = run($db, 'SELECT log_date, summary FROM daily_runs WHERE log_date >= ? AND log_date < ?
                ORDER BY log_date, activity_id', $range)->fetchAll();
            foreach ($runs as $activity) {
                $parsed = array_fill_keys(array_keys($fields), []);
                $parsed['workouts'][] = json_decode($activity['summary'], true, 32, JSON_THROW_ON_ERROR);
                $entries[] = ['date' => $activity['log_date'], 'parsed' => $parsed];
            }
            respond([
                'entries' => $entries, 'goals' => dailyGoals($db, $today),
                'goalTargets' => monthGoalTargets($db, $start, $end), 'startDate' => trackingStart($db, $today),
            ]);
        case 'GET goals':
            respond(['goals' => dailyGoals($db, $today)]);
        case 'POST goals':
            $data = body();
            $goals = [];
            foreach (['protein', 'carbs', 'fat', 'water_ml'] as $key) {
                $value = dailyNumber($data[$key] ?? null, $key === 'water_ml' ? 30000 : 2000, true);
                if ($value === 0) {
                    throw new DailyInput('Each goal needs to be greater than zero.');
                }
                $goals[$key] = $value;
            }
            if (dailyGoals($db, $today) === $goals) {
                respond(['goals' => $goals]);
            }
            $values = array_values($goals);
            try {
                run($db, 'INSERT INTO daily_goal_targets (effective_date, protein, carbs, fat, water_ml)
                    VALUES (?, ?, ?, ?, ?)', [$today, ...$values]);
            } catch (PDOException $error) {
                if ($error->getCode() !== '23000') {
                    throw $error;
                }
                run($db, 'UPDATE daily_goal_targets SET protein = ?, carbs = ?, fat = ?, water_ml = ?
                    WHERE effective_date = ?', [...$values, $today]);
            }
            respond(['goals' => $goals]);
        default:
            respond(['error' => 'Unknown tracker request.'], 404);
    }
} catch (DailyInput | JsonException $error) {
    respond(['error' => $error instanceof DailyInput ? $error->getMessage() : 'Invalid JSON.'], 400);
} catch (Throwable $error) {
    error_log('Daily tracker error: ' . get_class($error) . ' (' . $error->getCode() . ')');
    respond(['error' => 'The tracker couldn’t complete this request. Try again.'], 500);
}
