<?php
declare(strict_types=1);

final class DailyGarminFailure extends RuntimeException {}

function dailyGarminKeyFile(array $config): string {
    return $config['garmin_key_file'] ?? dirname(__DIR__, 2).'/daily-garmin-key.txt';
}
function dailyGarminConfigured(array $config): bool {
    $file=dailyGarminKeyFile($config);
    return is_file($file) && is_readable($file) && filesize($file)>0;
}
function dailyGarminRequest(string $path,string $authorization,bool $post=false,?string $caFile=null): string {
    $curl=curl_init('https://personal.connectapi.garmin.com'.$path);$body='';
    curl_setopt_array($curl,[CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_POST=>$post,
        CURLOPT_HTTPHEADER=>['Authorization: '.$authorization,$post?'Accept: text/plain':'Accept: application/json'],
        CURLOPT_WRITEFUNCTION=>static function($handle,$chunk)use(&$body){
            if(strlen($body)+strlen($chunk)>2000000)return 0;
            $body.=$chunk;return strlen($chunk);
        }]);
    if($caFile!==null)curl_setopt($curl,CURLOPT_CAINFO,$caFile);
    $ok=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
    if($ok===false)throw new DailyGarminFailure('Garmin did not respond. Try syncing again.');
    if($status===401 || $status===403)throw new DailyGarminFailure('Garmin access expired or was refused. Check the Personal API key.',401);
    if($status===429)throw new DailyGarminFailure('Garmin has limited requests. Try again in a few minutes.');
    if($status!==200)throw new DailyGarminFailure('Garmin could not return your runs. Try again shortly.',$status);
    return $body;
}
function dailyGarminToken(string $key,array &$state,callable $request): string {
    $fingerprint=hash('sha256',$key);
    if(($state['key_hash']??null)===$fingerprint && ($state['expires_at']??0)>time()+60 && is_string($state['token']??null))return $state['token'];
    $token=trim($request('/personalapitoken-service/personal-api/personal-api-key/token','ApiKey '.$key,true));
    if(!preg_match('/^[A-Za-z0-9_-]+\.([A-Za-z0-9_-]+)\.[A-Za-z0-9_-]+$/D',$token,$parts))throw new DailyGarminFailure('Garmin returned an invalid access token. Try again.');
    $claims=json_decode(base64_decode(strtr($parts[1],'-_','+/')),true);
    $expires=is_numeric($claims['exp']??null)?min((int)$claims['exp'],time()+86400):time()+82800;
    if($expires<=time()+60)throw new DailyGarminFailure('Garmin returned an expired access token. Check the Personal API key.');
    $state=['key_hash'=>$fingerprint,'token'=>$token,'expires_at'=>$expires];
    return $token;
}
function dailyGarminRun(array $activity,string $today): ?array {
    $type=$activity['activityType']??[];$key=$type['typeKey']??'';
    if($key!=='running' && ($type['parentTypeId']??null)!==1 && !str_ends_with($key,'_running'))return null;
    $id=(string)($activity['activityId']??'');
    if(!preg_match('/^\d{1,32}$/D',$id))throw new DailyGarminFailure('Garmin returned a run without a valid activity ID.');
    $date=substr($activity['startTimeLocal']??'',0,10);
    try { logDate($date); } catch(DailyInput $e) { throw new DailyGarminFailure('Garmin returned a run without a valid date.'); }
    if($date<DAILY_START_DATE || $date>$today)return null;
    $metric=static function(string $field)use($activity):?int {
        $value=$activity[$field]??null;
        if($value===null)return null;
        if(!is_numeric($value) || !is_finite((float)$value) || $value<0)throw new DailyGarminFailure('Garmin returned an invalid run measurement.');
        return (int)round((float)$value);
    };
    $speed=$activity['averageSpeed']??null;
    $pace=is_numeric($speed) && $speed>0?(int)round(1000/$speed):null;
    return ['activity_id'=>$id,'date'=>$date,'name'=>$key==='treadmill_running'?'Treadmill run':'Run',
        'distance_m'=>$metric('distance'),'duration_s'=>$metric('duration'),'pace_s_per_km'=>$pace,'avg_hr_bpm'=>$metric('averageHR')];
}
function dailyGarminImport(PDO $db,array $activities,string $today): array {
    $runs=[];$added=0;$updated=0;
    foreach($activities as $activity){
        if(!is_array($activity))throw new DailyGarminFailure('Garmin returned an invalid activity list.');
        $run=dailyGarminRun($activity,$today);if(!$run)continue;
        $row=run($db,'SELECT log_date,summary FROM daily_runs WHERE activity_id=?',[$run['activity_id']])->fetch();
        $previous=$row?json_decode($row['summary'],true,32,JSON_THROW_ON_ERROR):[];
        // Keep a coach's workout name and other existing details; absent Garmin values cannot erase them.
        if(isset($previous['name']) && !in_array($previous['name'],['Run','Treadmill run'],true))$run['name']=$previous['name'];
        $summary=$previous;
        foreach(dailyWorkout(['type'=>'run']+$run)+['source'=>'garmin'] as $field=>$value){
            if($value!==null || !array_key_exists($field,$summary))$summary[$field]=$value;
        }
        if(isset($runs[$run['activity_id']]))continue;
        if(!$row)$added++;
        elseif($row['log_date']!==$run['date'] || $previous!=$summary)$updated++;
        $runs[$run['activity_id']]=['activity_id'=>$run['activity_id'],'date'=>$run['date']]+$summary;
    }
    if($runs)syncRuns($db,['runs'=>array_values($runs)],$today);
    if($added+$updated>0)dailyCoachTouch($db);
    return ['added'=>$added,'updated'=>$updated,'checked'=>count($runs),'dates'=>array_values(array_unique(array_column($runs,'date')))];
}
function dailyGarminSync(PDO $db,array $config,string $today,?callable $request=null): array {
    if(!dailyGarminConfigured($config))throw new DailyGarminFailure('Garmin is not connected. Add your Personal API key on the server.');
    $keyFile=dailyGarminKeyFile($config);$key=trim(file_get_contents($keyFile));
    $cache=$config['garmin_cache_file']??dirname($keyFile).'/daily-garmin-state.json';
    $handle=fopen($cache,'c+');if(!$handle)throw new DailyGarminFailure('Garmin sync could not open its private cache.');
    chmod($cache,0600);
    if(!flock($handle,LOCK_EX|LOCK_NB)){fclose($handle);throw new DailyGarminFailure('Garmin is already syncing. Try again shortly.');}
    $state=[];
    try {
        $state=json_decode(stream_get_contents($handle),true);if(!is_array($state))$state=[];
        $request??=static fn($path,$authorization,$post)=>dailyGarminRequest($path,$authorization,$post,$config['garmin_ca_file']??null);
        $token=dailyGarminToken($key,$state,$request);
        $start=max(DAILY_START_DATE,(new DateTimeImmutable($today))->modify('-29 days')->format('Y-m-d'));
        $path='/activitylist-service/activities/search/activities?'.http_build_query(['activityType'=>'running','startDate'=>$start,'endDate'=>$today,'start'=>0,'limit'=>100,'excludeChildren'=>'true','sortOrder'=>'desc']);
        try { $raw=$request($path,'Bearer '.$token,false); }
        catch(DailyGarminFailure $e){
            if($e->getCode()!==401)throw $e;
            $state=[];$token=dailyGarminToken($key,$state,$request);$raw=$request($path,'Bearer '.$token,false);
        }
        try { $activities=json_decode($raw,true,64,JSON_THROW_ON_ERROR); }
        catch(JsonException $e){throw new DailyGarminFailure('Garmin returned unreadable activity data. Try again.');}
        if(!is_array($activities) || !array_is_list($activities) || count($activities)>=100)throw new DailyGarminFailure('Garmin returned an unexpected activity list. No runs were changed.');
        $result=dailyGarminImport($db,$activities,$today);
        $state['synced_at']=gmdate('c');
        return $result+['syncedAt'=>$state['synced_at']];
    } finally {
        rewind($handle);ftruncate($handle,0);fwrite($handle,json_encode($state,JSON_THROW_ON_ERROR));fflush($handle);flock($handle,LOCK_UN);fclose($handle);
    }
}
