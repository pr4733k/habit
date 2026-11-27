<?php
declare(strict_types=1);
require __DIR__.'/../public/daily/_store.php';
require __DIR__.'/../public/daily/_garmin.php';
$checks=0;
function check(bool $ok,string $message):void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function rejects(callable $fn,string $message):void {try{$fn();}catch(DailyInput|DailyGarminFailure $e){check(true,$message);return;}check(false,$message);}
function database():PDO {
    $db=new PDO('sqlite::memory:',options:[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec(file_get_contents(__DIR__.'/../daily/schema.sql'));return $db;
}
$today='2026-11-27';$db=database();
$activity=['activityId'=>12345678901,'startTimeLocal'=>'2026-11-27 17:47:35','activityType'=>['typeKey'=>'treadmill_running','parentTypeId'=>1],
    'distance'=>2000,'duration'=>845.9279785,'averageSpeed'=>2.364,'averageHR'=>169];
$run=dailyGarminRun($activity,$today);
check($run['date']===$today && $run['name']==='Treadmill run','Garmin date and treadmill subtype are retained');
check($run['duration_s']===846 && $run['distance_m']===2000 && $run['pace_s_per_km']===423 && $run['avg_hr_bpm']===169,'API measurements map to the existing run units');
$unknown=dailyGarminRun(array_replace($activity,['averageHR'=>null,'averageSpeed'=>null]),$today);
check($unknown['avg_hr_bpm']===null && $unknown['pace_s_per_km']===null,'Missing measurements remain unknown');
check(dailyGarminRun(array_replace($activity,['activityType'=>['typeKey'=>'cycling','parentTypeId'=>2]]),$today)===null,'Non-running activities are excluded');
check(dailyGarminRun(array_replace($activity,['startTimeLocal'=>'2026-11-28 08:00:00']),$today)===null,'Future dates are excluded');
check(dailyGarminRun(array_replace($activity,['startTimeLocal'=>'2026-10-01 08:00:00']),$today)===null,'Dates before tracking are excluded');
rejects(fn()=>dailyGarminRun(array_replace($activity,['activityId'=>'invalid']),$today),'Invalid IDs are rejected');
rejects(fn()=>dailyGarminRun(array_replace($activity,['startTimeLocal'=>'not a date']),$today),'Invalid dates are rejected');
rejects(fn()=>dailyGarminRun(array_replace($activity,['distance'=>-1]),$today),'Invalid measurements are rejected');
$first=dailyGarminImport($db,[$activity],$today);
check($first['added']===1 && $first['updated']===0,'A new run is added once');
$again=dailyGarminImport($db,[$activity,$activity],$today);
check($again['added']===0 && $again['updated']===0 && $again['checked']===1,'Retries and duplicate activity IDs do not inflate counts');
check((int)$db->query('SELECT COUNT(*) FROM daily_runs')->fetchColumn()===1,'Only one run row exists');
$old=json_decode($db->query('SELECT summary FROM daily_runs')->fetchColumn(),true);
$old['name']='Easy long run';$old['details']='Keep this coach note';
run($db,'UPDATE daily_runs SET summary=?',[json_encode($old)]);
$changed=dailyGarminImport($db,[array_replace($activity,['distance'=>2100,'averageHR'=>null])],$today);
$updated=dailySnapshot($db,$today)['workouts'][0];
check($changed['added']===0 && $changed['updated']===1,'A corrected Garmin activity updates its existing ID');
check($updated['name']==='Easy long run' && $updated['details']==='Keep this coach note','Existing workout names and details are preserved');
check($updated['avg_hr_bpm']==169,'Missing API data does not erase an existing measurement');
rejects(fn()=>dailyGarminImport($db,[array_replace($activity,['activityId'=>22222222222]),array_replace($activity,['activityId'=>33333333333,'distance'=>3000000])],$today),'Malformed activity batches are rejected before any writes');
check((int)$db->query('SELECT COUNT(*) FROM daily_runs')->fetchColumn()===1,'Rejected batches leave all existing runs unchanged');
check((int)$db->query('SELECT COUNT(*) FROM daily_notes')->fetchColumn()===0,'Run syncing never creates or changes food and water notes');

$folder=sys_get_temp_dir().'/habit-garmin-'.bin2hex(random_bytes(8));mkdir($folder);
$config=['garmin_key_file'=>$folder.'/key.txt','garmin_cache_file'=>$folder.'/cache.json'];
file_put_contents($config['garmin_key_file'],'test-personal-key');
$token='header.'.rtrim(strtr(base64_encode(json_encode(['exp'=>time()+3600])),'+/','-_'),'=').'.signature';
$tokenCalls=0;$activityCalls=0;
$request=static function($path,$authorization,$post)use(&$tokenCalls,&$activityCalls,$token,$activity){
    if($post){$tokenCalls++;check($authorization==='ApiKey test-personal-key','Key is sent only to the token endpoint');return $token;}
    $activityCalls++;check($authorization==='Bearer '.$token,'Activity requests use the access token');
    check(str_contains($path,'activityType=running') && str_contains($path,'excludeChildren=true'),'The request filters running and excludes duplicate multisport children');
    return json_encode([$activity]);
};
try {
    $db2=database();check(dailyGarminConfigured($config),'A private key enables the integration');
    dailyGarminSync($db2,$config,$today,$request);dailyGarminSync($db2,$config,$today,$request);
    check($tokenCalls===1 && $activityCalls===2,'Repeated syncs reuse the valid access token');
    $cache=json_decode(file_get_contents($config['garmin_cache_file']),true);
    check(!str_contains(file_get_contents($config['garmin_cache_file']),'test-personal-key'),'Token cache does not store the original API key');
    check(isset($cache['synced_at']),'Successful sync time is saved privately');
    rejects(fn()=>dailyGarminSync($db2,$config,$today,static fn()=>'{bad JSON'),'Unreadable activity responses fail without changing runs');
    $before=$db2->query('SELECT * FROM daily_runs')->fetchAll();
    rejects(fn()=>dailyGarminSync($db2,$config,$today,static function(){throw new DailyGarminFailure('Temporary outage');}),'Network failures expose a useful retry state');
    check($before===$db2->query('SELECT * FROM daily_runs')->fetchAll(),'Network failures preserve saved runs');
    $locked=fopen($config['garmin_cache_file'],'c+');flock($locked,LOCK_EX);
    rejects(fn()=>dailyGarminSync($db2,$config,$today,$request),'Concurrent syncs do not start another import');
    flock($locked,LOCK_UN);fclose($locked);
    $cache['expires_at']=time()-1;file_put_contents($config['garmin_cache_file'],json_encode($cache));
    dailyGarminSync($db2,$config,$today,$request);
    check($tokenCalls===2,'Expired tokens are renewed automatically');
    unlink($config['garmin_key_file']);
    check(!dailyGarminConfigured($config),'No button is advertised without a configured private key');
    rejects(fn()=>dailyGarminSync($db2,$config,$today,$request),'Missing keys fail without a network request');
} finally {
    foreach(['key.txt','cache.json'] as $file)if(is_file($folder.'/'.$file))unlink($folder.'/'.$file);
    rmdir($folder);
}
echo "$checks checks passed.\n";
