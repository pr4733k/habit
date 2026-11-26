<?php
declare(strict_types=1);
require __DIR__.'/../public/daily/_store.php';require __DIR__.'/../public/daily/_ai.php';require __DIR__.'/../public/daily/_workout-plan.php';
require __DIR__.'/../public/daily/_exercise-log.php';require __DIR__.'/../public/daily/_food-tools.php';require __DIR__.'/../public/daily/_repeat-foods.php';
$checks=0;
function check(bool $v,string $m):void{global $checks;if(!$v)throw new RuntimeException($m);$checks++;}
function rejects(callable $f,string $m):void{try{$f();}catch(DailyInput|DailyExerciseConflict $e){check(true,$m);return;}check(false,$m);}
function foodNote(PDO $db,string $id,string $name,?string $quantity,array $nutrition):void{
    run($db,'INSERT INTO daily_notes(id,log_date,revision,created_at,raw_note,parsed) VALUES(?,?,1,?,?,?)',[$id,'2026-11-24','2026-11-24 12:00:00','Original meal',json_encode(dailyResult(['meals'=>[['name'=>$name,'quantity'=>$quantity,'nutrition'=>$nutrition]]]))]);
}
$db=new PDO('sqlite::memory:',options:[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$db->exec(file_get_contents(__DIR__.'/../daily/schema.sql'));
$banana=['kcal'=>210,'protein'=>2.6,'carbs'=>54,'fat'=>.8,'estimated'=>true];
foodNote($db,'legacy-banana','Banana','2 bananas (240 g)',$banana);
foodNote($db,'legacy-donut','One-off doughnut','1 piece',['kcal'=>300,'protein'=>4,'carbs'=>40,'fat'=>14,'estimated'=>true]);
check(dailySavedFoods($db)===[],'Past meals do not automatically become saved foods');
check(dailyFoodIdentity('2 boiled eggs')===dailyFoodIdentity('Boiled egg'),'Quantity and plural spelling do not split an egg food identity');
check(dailyRepeatFoodName('150 g grilled chicken')==='grilled chicken' && dailyRepeatFoodName('Grilled chicken (180 g)')==='Grilled chicken','Food names drop leading and trailing portion weights');
check(dailyRepeatCandidates($db,'')===[] && count(dailyRepeatCandidates($db,'banana'))===1,'Past foods are searchable without listing everything');
$draft=dailyPrepareRepeat($db,[],['source'=>'legacy-banana','index'=>0]);
check($draft['components'][0]['unit']==='piece' && $draft['components'][0]['amount']==2,'Banana defaults to pieces from the original count');
check($draft['components'][0]['grams_per_piece']==120,'Known gram and piece amounts establish a real conversion');
$saved=dailySaveRepeat($db,$draft+['id'=>'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa']);
check(count(dailySavedFoods($db))===1 && dailySavedFoods($db)[0]['name']==='Banana','Only an explicitly saved food enters the menu');
check(!str_contains(dailySavedFoods($db)[0]['name'],'240'),'Saved food name has no carried-over quantity');
check(dailySaveRepeat($db,$draft+['id'=>'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'])['id']===$saved['id'],'Saving a favourite is idempotent');
$request=['id'=>'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb','food_id'=>$saved['id'],'date'=>'2026-11-25','amounts'=>[['amount'=>3,'unit'=>'piece']]];
$note=dailyLogSavedFood($db,$request,'2026-11-26');
check($note['parsed']['meals'][0]['nutrition']['kcal']==315,'Three bananas scale from the saved food, not a fixed repeated portion');
check($note['parsed']['meals'][0]['quantity']==='3 piece','The amount eaten is recorded on the new day');
$again=dailyLogSavedFood($db,$request,'2026-11-26');check($again['id']===$note['id'] && dailySavedFoods($db)[0]['count']===2,'Retry does not add another meal or inflate usage');
$grams=array_replace($request,['id'=>'cccccccc-cccc-cccc-cccc-cccccccccccc','amounts'=>[['amount'=>120,'unit'=>'g']]]);
check(dailyLogSavedFood($db,$grams,'2026-11-26')['parsed']['meals'][0]['nutrition']['kcal']==105,'Known piece-to-gram conversion scales correctly');
check(dailySavedFoods($db)[0]['components'][0]['default_unit']==='g','Next use remembers the most recently logged unit');
check(dailyKnownFoodComponent(['name'=>'Whey','quantity'=>'25 g protein from 33 g powder','nutrition'=>$banana])['amount']==33,'Protein grams are not mistaken for powder grams');
check(dailyKnownFoodComponent(['name'=>'Rice and chicken','quantity'=>'100 g rice + 180 g chicken','nutrition'=>$banana])['amount']===null,'Ambiguous combined food weight needs confirmation');
rejects(fn()=>dailyLogSavedFood($db,array_replace($request,['amounts'=>[]]),'2026-11-26'),'Each component needs an amount');
rejects(fn()=>dailyLogSavedFood($db,array_replace($request,['amounts'=>[['amount'=>0,'unit'=>'piece']]]),'2026-11-26'),'Blank or zero amounts are not logged');
foodNote($db,'chicken','Grilled chicken','180 g',['kcal'=>300,'protein'=>50,'carbs'=>0,'fat'=>10,'estimated'=>true]);
$chicken=dailyPrepareRepeat($db,[],['source'=>'chicken','index'=>0]);check($chicken['components'][0]['unit']==='g','Chicken defaults to grams');
rejects(fn()=>dailyRepeatPortion($chicken,[['amount'=>2,'unit'=>'piece']]),'Unknown grams-per-piece conversion is never invented');
$egg=['kcal'=>338,'protein'=>27,'carbs'=>11,'fat'=>20.5,'estimated'=>true];foodNote($db,'salad','Egg salad','4 eggs + 100 g salad without eggs',$egg);
$recipe=dailyPrepareRepeat($db,[],['source'=>'salad','index'=>0],static fn()=>['name'=>'Egg salad','components'=>[
    ['name'=>'Eggs','unit'=>'piece','amount'=>4,'grams_per_piece'=>50,'nutrition'=>['kcal'=>288,'protein'=>25,'carbs'=>1,'fat'=>20,'estimated'=>true]],
    ['name'=>'Salad without eggs','unit'=>'g','amount'=>100,'grams_per_piece'=>null,'nutrition'=>['kcal'=>50,'protein'=>2,'carbs'=>10,'fat'=>.5,'estimated'=>true]],
]]);
$mixed=dailyRepeatPortion($recipe,[['amount'=>2,'unit'=>'piece'],['amount'=>100,'unit'=>'g']]);
check($mixed['nutrition']['kcal']==194,'Changing only eggs leaves salad nutrition unchanged');
check($mixed['nutrition']['protein']==14.5 && $mixed['nutrition']['estimated'],'Recipe component scaling keeps estimates labelled');
$same=dailyRepeatPortion($recipe,[['amount'=>4,'unit'=>'piece'],['amount'=>100,'unit'=>'g']]);
check($same['nutrition']==$egg,'Original ingredient quantities reproduce the original total');
$recipeSaved=dailySaveRepeat($db,$recipe+['id'=>'dddddddd-dddd-dddd-dddd-dddddddddddd']);
check(dailySavedFoods($db)[0]['id']===$saved['id'],'Menu is sorted by times logged');
$before=(int)$db->query('SELECT COUNT(*) FROM daily_notes')->fetchColumn();run($db,'DELETE FROM daily_saved_foods WHERE id=?',[$recipeSaved['id']]);
check((int)$db->query('SELECT COUNT(*) FROM daily_notes')->fetchColumn()===$before,'Removing from repeats never deletes meal history');
rejects(fn()=>dailyLogSavedFood($db,['food_id'=>$recipeSaved['id']],'2026-11-26'),'Stale menu item cannot be logged after removal');
$changed=$draft;$changed['key']=str_repeat('0',64);rejects(fn()=>dailySaveRepeat($db,$changed+['id'=>'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee']),'Changed source food needs a refreshed template');
foodNote($db,'unknown','Unknown food',null,['kcal'=>200,'protein'=>10,'carbs'=>20,'fat'=>8,'estimated'=>true]);
$unknown=dailyPrepareRepeat($db,[],['source'=>'unknown','index'=>0]);check($unknown['components'][0]['amount']===null,'Missing historical amount is not invented');
rejects(fn()=>dailySaveRepeat($db,$unknown+['id'=>'ffffffff-ffff-ffff-ffff-ffffffffffff']),'Unknown reference amount requires confirmation');
$intake=['meals'=>[['name'=>'Whey','meal'=>'Snack','quantity'=>'33 g','nutrition'=>['kcal'=>130,'protein'=>25,'carbs'=>3,'fat'=>2,'estimated'=>false],'basis'=>['amount'=>33,'unit'=>'g','consumed'=>33],'reference_id'=>null]]];
$scaled=dailyResult(dailyScaleNutrition($intake,[]));check($scaled['meals'][0]['portion']===['amount'=>33,'unit'=>'g'],'New AI logs preserve the usable measurement basis');
echo "$checks checks passed.\n";
