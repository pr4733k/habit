<?php
declare(strict_types=1);
require __DIR__ . '/../public/daily/_store.php';
require __DIR__ . '/../public/daily/_ai.php';
require __DIR__ . '/../public/daily/_workout-plan.php';
$checks = 0;
function check(bool $value, string $message): void { global $checks; if (!$value) throw new RuntimeException($message); $checks++; }
function testDb(): PDO {
    $db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec(file_get_contents(__DIR__ . '/../daily/schema.sql')); dailyAiSchema($db); return $db;
}
function addNote(PDO $db, string $id, string $text, int $revision = 1): void {
    run($db, 'INSERT INTO daily_notes (id, log_date, raw_note, revision, created_at) VALUES (?, ?, ?, ?, ?)', [$id, '2026-11-22', $text, $revision, '2026-11-22 04:00:00']);
    dailyEnqueue($db, $id, $revision);
}
function readParsed(PDO $db, string $id): ?array {
    $json = run($db, 'SELECT parsed FROM daily_notes WHERE id = ?', [$id])->fetchColumn(); return $json ? json_decode($json, true) : null;
}
$db = testDb(); $config = ['processor' => 'openrouter'];
check(dailyProcessor([]) === 'external', 'Existing installations retain external mode');
foreach (['100ml water' => [100, 'add'], '2L water done' => [2000, 'total'], 'water: 1.75 L total' => [1750, 'total'], 'drank 300 ml' => [300, 'add']] as $text => [$ml, $mode]) {
    $water = dailyWaterOnly($text); check($water['water'][0] === ['mode' => $mode, 'ml' => $ml], 'Water is deterministic: ' . $text);
}
foreach (['2 L water and a banana', 'not 2 L water', 'remove 300ml water', '2L beer', '0 ml water', '31 L water'] as $text) check(dailyWaterOnly($text) === null, 'Mixed or ambiguous text is not discarded: ' . $text);
addNote($db, 'water', '2L water done');
$calls = 0;
check(dailyProcessOne($db, $config, 'water', 1, static function () use (&$calls) { $calls++; throw new RuntimeException(); }), 'Water commits');
check($calls === 0 && readParsed($db, 'water')['water'][0]['ml'] === 2000, 'Water never calls AI');
check(!dailyProcessOne($db, $config, 'water', 1), 'Duplicate work is idempotent');
addNote($db, 'edited', 'banana');
$stale = dailyProcessOne($db, $config, 'edited', 1, static function () use ($db) {
    run($db, 'UPDATE daily_notes SET revision = 2, raw_note = ? WHERE id = ?', ['two bananas', 'edited']); dailyEnqueue($db, 'edited', 2);
    return ['water' => [['mode' => 'add', 'ml' => 100]]];
});
check(!$stale && readParsed($db, 'edited') === null, 'An in-flight edit rejects the old result');
check(dailyProcessOne($db, $config, 'edited', 2, static fn() => ['water' => [['mode' => 'add', 'ml' => 200]]]), 'The latest revision processes');
addNote($db, 'concurrent', 'banana');
dailyProcessOne($db, $config, 'concurrent', 1, static function () use ($db, $config) {
    check(!dailyProcessOne($db, $config, 'concurrent', 1), 'Concurrent worker cannot claim a leased note');
    return ['review' => 'Please edit the original portion.'];
});
check(readParsed($db, 'concurrent')['review'] !== null, 'An ambiguous correction is reviewable without inventing food');
addNote($db, 'retry', 'banana');
for ($i = 1; $i <= 3; $i++) {
    run($db, 'UPDATE daily_processing_jobs SET next_at = 0 WHERE note_id = ?', ['retry']);
    dailyProcessOne($db, $config, 'retry', 1, static fn() => throw new DailyAiFailure('Temporary failure', true));
    check(dailyJobStatus($db, 'retry', 1)['state'] === ($i < 3 ? 'queued' : 'failed'), 'Retry bounded at attempt ' . $i);
}
check(readParsed($db, 'retry') === null, 'Failures keep the original note pending');
addNote($db, 'credits', 'banana');
dailyProcessOne($db, $config, 'credits', 1, static fn() => throw new DailyAiFailure('Credits exhausted'));
check(dailyJobStatus($db, 'credits', 1)['state'] === 'failed', 'Credit failure needs attention immediately');
addNote($db, 'invalid', 'banana');
dailyProcessOne($db, $config, 'invalid', 1, static fn() => ['meals' => [['name' => 'Banana', 'nutrition' => ['protein' => -1, 'estimated' => true]]]]);
check(readParsed($db, 'invalid') === null && dailyJobStatus($db, 'invalid', 1)['state'] === 'queued', 'Invalid numbers cannot enter totals');
addNote($db, 'expired', 'banana');
$expired = dailyProcessOne($db, $config, 'expired', 1, static function () use ($db, $config) {
    run($db, 'UPDATE daily_processing_jobs SET lease_until = ? WHERE note_id = ?', [time() - 1, 'expired']);
    dailyProcessOne($db, $config, 'expired', 1, static fn() => ['water' => [['mode' => 'add', 'ml' => 300]]]);
    return ['water' => [['mode' => 'add', 'ml' => 999]]];
});
check(!$expired && readParsed($db, 'expired')['water'][0]['ml'] === 300, 'Expired workers cannot overwrite a reclaimed result');
$reference = ['whey' => ['basis' => ['amount' => 33, 'unit' => 'g'], 'nutrition' => ['kcal' => 132, 'protein' => 25, 'carbs' => 2.6, 'fat' => 2.4, 'estimated' => true]]];
$scaled = dailyScaleNutrition(['meals' => [['name' => 'Whey', 'reference_id' => 'whey', 'nutrition' => ['kcal' => 200, 'protein' => 99, 'carbs' => 0, 'fat' => 0, 'estimated' => false], 'basis' => ['amount' => 33, 'unit' => 'g', 'consumed' => 66]]]], $reference);
check($scaled['meals'][0]['nutrition']['protein'] === 50.0 && $scaled['meals'][0]['nutrition']['kcal'] === 264.0, 'Reference scaling happens in PHP and wins over model math');
check(dailyDefaultMeal('2026-11-22 07:29:00') === 'Breakfast' && dailyDefaultMeal('2026-11-22 07:30:00') === 'Lunch', 'Meal boundary 1pm India');
check(dailyDefaultMeal('2026-11-22 10:30:00') === 'Snack' && dailyDefaultMeal('2026-11-22 13:30:00') === 'Dinner', '4pm snack and 7pm dinner');
$planFixture = ['kind' => 'lift', 'title' => 'Upper body', 'reason' => 'Lower body was trained yesterday.', 'venue' => 'Aurum', 'minutes' => 60,
    'exercises' => [['name' => 'Bench press', 'sets' => 3, 'reps' => '8–10', 'load' => null, 'load_basis' => 'unknown', 'notes' => null]], 'notes' => null];
$clock = new DateTimeImmutable('2026-11-22T12:00:00+05:30'); $generateCalls = 0;
$generated = dailyGeneratePlan($db, $config, $clock, false, static function ($context) use (&$generateCalls, $planFixture) {
    $generateCalls++; check(count($context['days']) === 7, 'Generator receives seven dates'); check($context['preferences']['available_session_minutes'] === 60, 'Session default is 60 minutes');
    check(!isset($context['today_inputs']), 'Old clients can generate without optional inputs'); return $planFixture;
});
check($generated['plan']['kind'] === 'lift', 'Plan persists');
dailyGeneratePlan($db, $config, $clock, false, static function () use (&$generateCalls) { $generateCalls++; });
check($generateCalls === 1, 'Reading/repeated generation reuses the saved plan');
$feed = dailyCoachResponse($db, $clock, 7); $today = $feed['days'][6];
check($today['training_status'] === 'unlogged' && !$today['completed'], 'A plan never becomes completion or rest');
check($today['recommendation']['status'] === 'planned' && $today['recommendation']['source'] === 'habit-ai', 'Coach JSON includes the saved suggestion without changing legacy planned types');
check(dailyWorkoutInputs(null) === [] && dailyWorkoutInputs(['focus' => '  ', 'soreness' => '', 'tomorrow_plan' => '']) === [], 'All planning inputs are optional');
check(dailyWorkoutInputs(['focus' => '  Chest  ']) === ['focus' => 'Chest'], 'Planning inputs are trimmed');
foreach (['light', 'regular', 'intense'] as $effort) check(dailyWorkoutInputs(['effort' => $effort]) === ['effort' => $effort], 'Selected effort is preserved: ' . $effort);
foreach (['not an object', ['soreness' => 5], ['focus' => str_repeat('x', 601)], ['soreness' => null], ['a list'], ['effort' => 'maximum'], ['effort' => 5]] as $invalid) {
    try { dailyWorkoutInputs($invalid); check(false, 'Invalid planning input must fail'); }
    catch (DailyInput) { check(true, 'Invalid or overlong inputs rejected before a model call'); }
}
dailyCoachSaveSettings($db, ['preferences' => ['gym_venue' => 'Aurum'], 'fixed_sessions' => [[
    'id' => 'fixed-test', 'type' => 'gym', 'venue' => 'Troop', 'weekday' => 1,
    'start_date' => '2026-11-23', 'end_date' => '2026-11-23', 'exercises' => ['Squats'], 'muscle_groups' => ['quads'],
]]]);
run($db, 'INSERT INTO daily_notes (id,log_date,raw_note,revision,created_at,parsed) VALUES (?,?,?,1,?,?)',
    ['recovery-test', '2026-11-21', 'Mild soreness', '2026-11-21 12:00:00', json_encode(dailyResult(['recovery' => [['soreness' => ['quads' => 'mild']]]]))]);
$before = dailyCoachResponse($db, $clock, 7);
run($db, 'UPDATE daily_workout_plans SET last_started_at = 0 WHERE log_date = ?', ['2026-11-22']);
$hints = ['focus' => 'Chest and triceps', 'soreness' => 'Hamstrings sore', 'tomorrow_plan' => 'Squats at Troop', 'effort' => 'intense'];
$regenerated = dailyGeneratePlan($db, $config, $clock, true, static function ($context) use ($hints, $planFixture) {
    check($context['today_inputs'] === ['date' => '2026-11-22', 'tomorrow_date' => '2026-11-23'] + $hints, 'All three inputs reach the generator with India-local dates');
    check(count($context['days']) === 7 && !isset($context['days'][6]['recommendation']), 'Fresh hints supplement history without treating the old plan as a workout');
    return array_replace($planFixture, ['title' => 'Updated upper-body plan']);
}, $hints);
check($regenerated['plan']['title'] === 'Updated upper-body plan', 'Regenerate replaces the saved suggestion');
$after = dailyCoachResponse($db, $clock, 7);
foreach (['days', 'upcoming_fixed', 'preferences'] as $key) {
    if ($key === 'days') { foreach ($before[$key] as &$day) unset($day['recommendation']); unset($day); foreach ($after[$key] as &$day) unset($day['recommendation']); unset($day); }
    check(($before[$key] ?? null) == ($after[$key] ?? null), 'Planning inputs do not create completed training, recovery or bookings: ' . $key);
}
check(dailyEquipment([]) === [], 'An install without an equipment inventory does not invent one');
run($db, 'INSERT INTO daily_notes (id,log_date,raw_note,revision,created_at,parsed) VALUES (?,?,?,1,?,?)', ['dal-reference','2026-11-21','200 g dal','2026-11-21 10:00:00',json_encode(dailyResult(['meals'=>[['name'=>'Dal','quantity'=>'200 g cooked','nutrition'=>['kcal'=>180,'protein'=>10,'carbs'=>24,'fat'=>5,'estimated'=>true]]]]))]);
check(count(dailyFoodReferences($db, [], '200 g dal')) === 1, 'Short Indian food names reuse matching prior foods');
$backlog = testDb();
for ($i=0; $i<100; $i++) { addNote($backlog, 'failed-'.$i, 'unresolved note'); run($backlog, "UPDATE daily_processing_jobs SET state='failed' WHERE note_id=?", ['failed-'.$i]); }
run($backlog, 'INSERT INTO daily_notes (id,log_date,raw_note,revision,created_at) VALUES (?,?,?,1,?)', ['later-water','2026-11-22','250 ml water','2026-11-22 05:00:00']);
dailyDrain($backlog, $config);
check(readParsed($backlog, 'later-water')['water'][0]['ml'] === 250, 'Failed old notes cannot starve a later pending note');
echo "$checks checks passed.\n";
