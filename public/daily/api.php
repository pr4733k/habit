<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/_store.php';
require_once __DIR__ . '/_ai.php';
require_once __DIR__ . '/_workout-plan.php';
require_once __DIR__ . '/_exercise-log.php';
require_once __DIR__ . '/_food-tools.php';
require_once __DIR__ . '/_repeat-foods.php';
require_once __DIR__ . '/_garmin.php';

try {
    $configPath = PHP_SAPI === 'cli-server' ? '/.daily-data/config.php' : '/daily-config.php';
    $configFile = getenv('DAILY_CONFIG') ?: dirname(__DIR__, 2) . $configPath;
    if (!is_file($configFile)) {
        respond(['error' => 'The daily tracker API needs local or hosting configuration.'], 503);
    }
    $config = (require $configFile) + ['local' => false, 'user' => null, 'password' => null, 'timezone' => 'Asia/Kolkata', 'public_calendar' => false, 'auto_sync' => true];
    $action = $_GET['action'] ?? 'session';
    if (!is_string($action)) {
        throw new DailyInput('Invalid tracker request.');
    }
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET' && $action === 'settings') {
        respond(['timeZone' => $config['timezone'], 'publicCalendar' => $config['public_calendar'], 'autoSync' => $config['auto_sync'], 'processor' => dailyProcessor($config)]);
    }
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (in_array($action, ['pending', 'commit', 'runs', 'sync-bridge'], true)
        || (in_array($action, ['day', 'coach-settings'], true) && $authorization !== '')) {
        $token = str_starts_with($authorization, 'Bearer ') ? substr($authorization, 7) : '';
        if ($token === '' || !hash_equals($config['sync_token_hash'], hash('sha256', $token))) {
            respond(['error' => 'A valid processor token is required.'], 401);
        }
    } elseif ($method !== 'GET' || (!in_array($action, ['month', 'coach', 'today'], true) || $config['public_calendar'] !== true)) {
        browserAccess($config, $method, $action);
    }
    $db = new PDO($config['dsn'], $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $db->exec('PRAGMA busy_timeout = 4000');
    }
    $today = (new DateTimeImmutable('now', new DateTimeZone($config['timezone'])))->format('Y-m-d');

    if (dailyProcessor($config) === 'openrouter' && in_array($action, ['pending', 'commit', 'sync-bridge'], true)) {
        respond(['error' => 'Hosted processing is enabled. The external bridge is disabled.'], 409);
    }
    switch ($method . ' ' . $action) {
        case 'GET garmin':
            respond(['configured'=>dailyGarminConfigured($config)]);
        case 'POST garmin-sync':
            if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
            respond(dailyGarminSync($db,$config,$today));
        case 'GET saved-foods':
            respond(['foods'=>dailySavedFoods($db)]);
        case 'GET repeat-food-candidates':
            if(!is_string($_GET['query']??''))throw new DailyInput('Enter a food name.');
            respond(['foods'=>dailyRepeatCandidates($db,$_GET['query']??'')]);
        case 'POST prepare-repeat-food':
            if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
            respond(['draft'=>dailyPrepareRepeat($db,$config,body())]);
        case 'POST save-repeat-food':
            respond(['food'=>dailySaveRepeat($db,body())]);
        case 'POST log-repeat-food':
            respond(['note'=>dailyLogSavedFood($db,body(),$today)]);
        case 'POST remove-repeat-food':
            dailySavedFoodsSchema($db);run($db,'DELETE FROM daily_saved_foods WHERE id=?',[noteId(body()['id']??null)]);
            respond(['removed'=>true]);
        case 'GET repeat-meals':
            respond(['meals'=>dailyRepeatMeals($db)]);
        case 'POST repeat-meal':
            respond(['note'=>dailyRepeatMeal($db,body(),$today)]);
        case 'POST scan-label':
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            respond(['label'=>dailyScanLabel($db,$config,$_FILES['image']??[])]);
        case 'POST save-label':
            $input=body();$food=dailyLabelFood($input);
            $nutrition=$food['nutrition'];
            $description='Nutrition label: '.$food['name'].' · '.$food['quantity']."\n".json_encode($nutrition,JSON_THROW_ON_ERROR);
            respond(['note'=>dailySaveFood($db,$input,$today,$food,'label:'.dailyFoodKey($food),$description)]);
        case 'GET workout-session':
            $date = logDate($_GET['date'] ?? $today);
            if ($date < DAILY_START_DATE || $date > $today) throw new DailyInput('Select a date within the tracking period.');
            respond(dailyWorkoutSession($db, $date));
        case 'POST save-exercise':
            respond(['entry' => dailySaveExercise($db, body(), $today)]);
        case 'GET today':
            $day = dailySnapshot($db, $today);
            unset($day['pending']);
            respond(['day' => $day] + dailyReadPlan($db, $today));
        case 'POST generate-workout':
            if (dailyProcessor($config) !== 'openrouter') respond(['error' => 'Enable OpenRouter to generate a workout.'], 409);
            $data = body();
            if (isset($data['regenerate']) && !is_bool($data['regenerate'])) throw new DailyInput('Invalid generation request.');
            $inputs = dailyWorkoutInputs($data['inputs'] ?? null);
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            respond(dailyGeneratePlan($db, $config, new DateTimeImmutable('now', new DateTimeZone($config['timezone'])), $data['regenerate'] ?? false, null, $inputs));
        case 'POST retry-note':
            if (dailyProcessor($config) !== 'openrouter') respond(['error' => 'Hosted processing is not enabled.'], 409);
            $note = getNote($db, noteId(body()['id'] ?? null));
            if ($note['parsed'] === null) {
                dailyAiSchema($db); dailyEnqueue($db, $note['id'], $note['revision']);
                run($db, "UPDATE daily_processing_jobs SET state = 'queued', attempts = 0, next_at = 0, last_error = NULL WHERE note_id = ? AND revision = ? AND state = 'failed'", [$note['id'], $note['revision']]);
            }
            dailySavedResponse($db, $config, $note);
        case 'GET coach':
            $days = $_GET['days'] ?? '14';
            if (!is_string($days) || !preg_match('/^[1-9][0-9]?$/D', $days) || (int)$days > 60) {
                throw new DailyInput('Use days between 1 and 60.');
            }
            respond(dailyCoachResponse($db, new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')), (int)$days));
        case 'GET coach-settings':
            respond(dailyCoachState($db));
        case 'POST coach-settings':
            respond(dailyCoachSaveSettings($db, body()));
        case 'GET pending':
            $rows = run($db, 'SELECT * FROM daily_notes WHERE parsed IS NULL ORDER BY created_at, id LIMIT 25')->fetchAll();
            respond(['notes' => array_map('privateNote', $rows)]);
        case 'POST commit':
            $note = commitNote($db, body());
            dailyCoachTouch($db);
            try {
                syncRequest($db, 'status');
            } catch (PDOException $error) {
                error_log('Daily sync status error (' . $error->getCode() . ')');
            }
            respond(['note' => $note]);
        case 'GET runs':
            respond(['startDate' => DAILY_START_DATE, 'today' => $today]);
        case 'POST runs':
            $synced = syncRuns($db, body(), $today);
            if ($synced > 0) dailyCoachTouch($db);
            respond(['synced' => $synced]);
        case 'GET note':
            respond(['note' => getNote($db, noteId($_GET['id'] ?? null))]);
        case 'GET notes':
            $date = logDate($_GET['date'] ?? null);
            $rows = run($db, 'SELECT * FROM daily_notes WHERE log_date = ? ORDER BY created_at DESC, id', [$date])->fetchAll();
            respond(['notes' => array_map('privateNote', $rows)]);
        case 'POST save':
            $note = saveNote($db, body(), $today);
            dailyCoachTouch($db);
            if (dailyProcessor($config) === 'openrouter') dailySavedResponse($db, $config, $note);
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
            if (dailyProcessor($config) === 'openrouter') respond(['state' => 'hosted']);
            respond(syncRequest($db, 'status'));
        case 'POST sync':
            if (dailyProcessor($config) === 'openrouter') respond(['state' => 'hosted']);
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
            if ($date < DAILY_START_DATE || $date > $today) {
                respond(['error' => 'This date is outside the tracking period.'], 404);
            }
            $day = dailySnapshot($db, $date);
            if ($action === 'widget') {
                $day['pendingCount'] = count($day['pending']);
                $day['workoutSession'] = dailyWorkoutSession($db, $date);
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
            $range = [max(DAILY_START_DATE, $start), $end];
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
                'goalTargets' => monthGoalTargets($db, $start, $end), 'startDate' => DAILY_START_DATE,
                'liftingEnergy' => (object)dailyLiftingEnergy($entries, $config['lifting_energy'] ?? []),
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
} catch (DailyExerciseConflict $error) {
    respond(['error' => $error->getMessage()], 409);
} catch (DailyAiFailure $error) {
    respond(['error' => $error->getMessage()], 503);
} catch (DailyGarminFailure $error) {
    respond(['error' => $error->getMessage()], 503);
} catch (DailyInput | JsonException $error) {
    respond(['error' => $error instanceof DailyInput ? $error->getMessage() : 'Invalid JSON.'], 400);
} catch (Throwable $error) {
    error_log('Daily tracker error: ' . get_class($error) . ' (' . $error->getCode() . ')');
    respond(['error' => 'The tracker couldn’t complete this request. Try again.'], 500);
}
