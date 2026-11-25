<?php
declare(strict_types=1);
require __DIR__ . '/../public/daily/_store.php';
require __DIR__ . '/../public/daily/_ai.php';
require __DIR__ . '/../public/daily/_workout-plan.php';
require __DIR__ . '/../public/daily/_exercise-log.php';
$checks = 0;
function check(bool $value, string $name): void { global $checks; if (!$value) throw new RuntimeException($name); $checks++; }
function rejects(callable $run, string $class, string $name): void { try { $run(); } catch (Throwable $e) { check($e instanceof $class, $name . ': ' . $e::class); return; } check(false, $name); }
function note(PDO $db, string $id, string $date, array $parsed): void {
    run($db, 'INSERT INTO daily_notes (id,log_date,revision,created_at,raw_note,parsed) VALUES (?,?,1,?,?,?)', [$id,$date,$date.' 10:00:00','Original private note',json_encode($parsed)]);
}
$db = new PDO('sqlite::memory:', options:[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec(file_get_contents(__DIR__.'/../daily/schema.sql'));
$date = '2026-11-25';
$plan = ['title'=>'Upper body','venue'=>'Aurum','minutes'=>60,'exercises'=>[
    ['name'=>'DB chest press','sets'=>3,'reps'=>'8–10','load'=>null,'notes'=>null],
    ['name'=>'Triceps pushdown','sets'=>3,'reps'=>'10','load'=>null,'notes'=>null],
    ['name'=>'Incline dumbbell press','sets'=>3,'reps'=>'10','load'=>null,'notes'=>null],
]];
run($db,'INSERT INTO daily_workout_plans(log_date,plan) VALUES(?,?)',[$date,json_encode($plan)]);
note($db,'old-chest','2026-11-11',dailyResult(['workouts'=>[['type'=>'gym','name'=>'Chest press','details'=>"12.5 kg each hand × 10 reps\n15 kg each hand × 8 reps × 2 sets"]]]));
note($db,'pushdown','2026-11-17',dailyResult(['workouts'=>[['type'=>'gym','name'=>'Triceps pushdown','sets'=>[
    ['reps'=>10,'weight'=>10,'weight_unit'=>'kg','warmup'=>true],
    ['reps'=>10,'weight'=>25,'weight_unit'=>'kg','warmup'=>false],
    ['reps'=>10,'weight'=>25,'weight_unit'=>'kg','warmup'=>false],
]]]]));
note($db,'planned-only','2026-11-24',['workouts'=>[['type'=>'gym','name'=>'DB chest press','status'=>'planned','details'=>'99 kg × 10 reps']]]);
$session=dailyWorkoutSession($db,$date);
check($session['exercises'][0]['previous']['date']==='2026-11-11','Look beyond seven days and exclude planned training');
$previous=$session['exercises'][0]['previous']['groups'];
check(count($previous)===2 && $previous[1]['sets']===2 && $previous[1]['weight_basis']==='per_dumbbell','Legacy per-hand sets group conservatively');
check($session['exercises'][1]['previous']['groups'][0]['sets']===2 && $session['exercises'][1]['previous']['groups'][0]['weight_basis']===null,'Warmups are excluded and an unknown load basis stays unknown');
check($session['exercises'][2]['previous']===null,'Incline press never inherits flat press weights');
check(!$session['exercises'][0]['saved'] && run($db,'SELECT COUNT(*) FROM daily_ai_usage')->fetchColumn()===0,'Reading a session creates no completed activity or AI call');
$input=['date'=>$date,'name'=>'DB chest press','venue'=>'Aurum','groups'=>[
    ['weight'=>15,'reps'=>10,'sets'=>2,'weight_unit'=>'kg','weight_basis'=>'per_dumbbell'],
    ['weight'=>17.5,'reps'=>8,'sets'=>1,'weight_unit'=>'kg','weight_basis'=>'per_dumbbell'],
]];
$first=dailySaveExercise($db,$input,$date);
check($first['revision']===1 && count($first['workout']['sets'])===3,'Separate groups expand to individual working sets');
check($first['groups'][1]['weight']===17.5 && $first['groups'][0]['sets']===2,'Grouped data round-trips without doubling dumbbell weight');
$count=(int)run($db,'SELECT COUNT(*) FROM daily_notes')->fetchColumn();
$retry=dailySaveExercise($db,$input,$date);
check($retry['revision']===1 && (int)run($db,'SELECT COUNT(*) FROM daily_notes')->fetchColumn()===$count,'A lost-response retry is idempotent');
$input['revision']=1; $input['groups'][1]['reps']=9;
$edited=dailySaveExercise($db,$input,$date);
check($edited['revision']===2 && $edited['groups'][1]['reps']===9 && (int)run($db,'SELECT COUNT(*) FROM daily_notes')->fetchColumn()===$count,'Editing replaces the existing exercise');
check(dailySaveExercise($db,$input,$date)['revision']===2,'An edit retry with an old revision is safe when its content already matches');
$input['groups'][1]['reps']=7;
rejects(fn()=>dailySaveExercise($db,$input,$date),DailyExerciseConflict::class,'A stale client cannot overwrite a newer edit');
$session=dailyWorkoutSession($db,$date);
check($session['exercises'][0]['saved']['revision']===2 && $session['exercises'][0]['saved']['groups'][1]['reps']===9,'Saved exercises survive reopening');
$feed=dailyCoachResponse($db,new DateTimeImmutable($date.'T12:00:00+05:30'),7);
check(count($feed['days'][6]['completed'])===1 && count($feed['days'][6]['completed'][0]['exercises'])===1,'Coach feed counts only the saved exercise');
$snapshot=dailySnapshot($db,$date);
check(count($snapshot['workouts'])===1 && !$snapshot['pending'] && !$snapshot['meals'],'Exercise saving is immediate and does not invent food or pending notes');
check((int)run($db,'SELECT COUNT(*) FROM daily_ai_usage')->fetchColumn()===0 && (int)run($db,'SELECT COUNT(*) FROM daily_processing_jobs')->fetchColumn()===0,'Manual sets bypass AI processing');
note($db,'earlier-today',$date,dailyResult(['workouts'=>[['type'=>'gym','name'=>'Triceps pushdown','details'=>'25 kg × 10 reps × 3 sets']]]));
check(dailyWorkoutSession($db,$date)['exercises'][1]['saved']['external']===true,'An exercise logged through another flow is visibly complete');
rejects(fn()=>dailySaveExercise($db,['date'=>$date,'name'=>'Triceps pushdown','groups'=>$input['groups']],$date),DailyExerciseConflict::class,'Do not duplicate an exercise already logged elsewhere');
foreach ([[], [['weight'=>-1,'reps'=>10,'sets'=>3]], [['weight'=>15,'reps'=>0,'sets'=>1]], [['weight'=>15,'reps'=>10,'sets'=>0]], [['weight'=>15,'reps'=>10,'sets'=>1,'weight_unit'=>null]], [['weight'=>15,'reps'=>10,'sets'=>1,'weight_basis'=>'bodyweight']]] as $groups) {
    rejects(fn()=>dailySaveExercise($db,['date'=>$date,'name'=>'Invalid exercise','groups'=>$groups],$date),DailyInput::class,'Malformed groups rejected');
}
rejects(fn()=>dailySaveExercise($db,['date'=>'2026-11-26','name'=>'Future exercise','groups'=>$input['groups']],$date),DailyInput::class,'Future exercise saves are rejected');
$bw=dailySaveExercise($db,['date'=>$date,'name'=>'Pull-ups','groups'=>[['weight'=>null,'reps'=>2,'sets'=>4,'weight_unit'=>null,'weight_basis'=>'bodyweight']]],$date);
check($bw['workout']['sets'][0]['weight']===null && $bw['groups'][0]['weight_basis']==='bodyweight','Bodyweight remains distinct from a zero external load');
$unknown=dailySaveExercise($db,['date'=>'2026-11-24','name'=>'Seated row','groups'=>[['weight'=>null,'reps'=>null,'sets'=>2,'weight_basis'=>null]]],$date);
check($unknown['workout']['sets'][0]['reps']===null && $unknown['workout']['sets'][0]['weight']===null,'Missing values remain unknown');
$row=run($db,'SELECT parsed FROM daily_notes WHERE id=?',[$first['id']])->fetchColumn();
$mixed=json_decode($row,true);$mixed['water']=[['mode'=>'add','ml'=>100]];
run($db,'UPDATE daily_notes SET parsed=? WHERE id=?',[json_encode($mixed),$first['id']]);
$input['revision']=2;
rejects(fn()=>dailySaveExercise($db,$input,$date),DailyExerciseConflict::class,'An edited note containing other logs cannot lose those logs');
check(dailyExerciseKey('Reverse pec deck fly')!==dailyExerciseKey('Pec deck chest fly'),'Reverse fly and chest fly never share history');
run($db,'UPDATE daily_workout_plans SET plan=? WHERE log_date=?',[json_encode(['kind'=>'rest','title'=>'Rest day','exercises'=>[]]),$date]);
$reopened=dailyWorkoutSession($db,$date);
check(count($reopened['exercises'])===2 && $reopened['title']==='Workout','Saved exercise entries remain accessible when a plan changes to rest');
echo "$checks checks passed.\n";
