<?php
declare(strict_types=1);

function dailyProgression(array $exercise): ?string {
    $previous=$exercise['previous']??null;$planned=$exercise['planned']??null;
    if(!$previous || !$previous['complete'] || !$planned || !preg_match('/^\s*(\d{1,2})(?:\s*[-–]\s*(\d{1,2}))?\s*(?:reps)?\s*$/iu',$planned['reps']??'',$match))return null;
    $low=(int)$match[1];$high=isset($match[2])?(int)$match[2]:$low;
    if($low<1 || $high<$low || $high>30)return null;
    $groups=$previous['groups'];if(!$groups)return null;
    foreach($groups as $g)if($g['reps']===null || $g['weight_basis']===null || ($g['weight_basis']!=='bodyweight' && ($g['weight']===null || $g['weight_unit']===null)))return null;
    $last=$groups[count($groups)-1];$basis=$last['weight_basis'];
    if($last['reps']<$high) {
        $target=min($high,$last['reps']+1);
        $load=$basis==='bodyweight'?'bodyweight':$last['weight'].' '.$last['weight_unit'].match($basis){'per_dumbbell'=>' each','per_side'=>' per side','assistance'=>' assistance','added_weight'=>' added',default=>' total'};
        return 'Next target: '.$target.' reps on the last set at '.$load.'. Keep today’s plan and recovery in mind.';
    }
    $history=array_slice($exercise['history']??[],0,2);
    if(count($history)===2 && !in_array($basis,['bodyweight','assistance'],true)) {
        foreach($history as $session){
            if(!$session['complete'] || !$session['groups'])return null;
            foreach($session['groups'] as $g)if($g['weight']!==$last['weight'] || $g['weight_unit']!==$last['weight_unit'] || $g['weight_basis']!==$basis || $g['reps']===null || $g['reps']<$high+2)return 'Build consistent reps across all sets before adding weight.';
        }
        return 'Two sessions above the rep target. When recovered, consider the smallest available 2–10% load increase.';
    }
    return 'Hold the load and build consistent reps across all sets before increasing it.';
}
