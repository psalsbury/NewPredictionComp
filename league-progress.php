<?php
function friendLeagueProgress(PDO $db,int $leagueId,int $viewerId,DateTimeImmutable $start,DateTimeImmutable $end):array{
 $q=$db->prepare("SELECT MAX(julianday(f.kickoff_utc)) FROM fixtures f WHERE f.status='FT' AND f.home_score IS NOT NULL AND f.away_score IS NOT NULL AND julianday(f.kickoff_utc)>=julianday(?) AND julianday(f.kickoff_utc)<julianday(?) AND julianday(f.kickoff_utc)<=julianday('now')");
 $q->execute([$start->format('c'),$end->format('c')]);$last=$q->fetchColumn();$weekStart=null;$weekEnd=null;$label='No completed matchweek yet';
 if($last!==false&&$last!==null){
 $timestamp=(int)round(((float)$last-2440587.5)*86400);$day=(new DateTimeImmutable('@'.$timestamp))->setTimezone(new DateTimeZone('Europe/London'))->setTime(0,0);
 $weekStart=$day->modify('-'.(((int)$day->format('N')+2)%7).' days');$weekEnd=$weekStart->modify('+7 days');$label='Week of '.$weekStart->format('d M');
 }
 $q=$db->prepare("SELECT u.id,u.display_name,CAST(u.is_bot AS INTEGER) is_bot,CAST(COALESCE(u.bot_grade,5) AS INTEGER) bot_grade,l.owner_user_id FROM league_members lm JOIN users u ON u.id=lm.user_id JOIN leagues l ON l.id=lm.league_id WHERE lm.league_id=?");
 $q->execute([$leagueId]);$members=[];foreach($q as $r){$r['id']=(int)$r['id'];$r['pts']=0;$r['before_pts']=0;$r['round_pts']=0;$r['is_owner']=$r['id']===(int)$r['owner_user_id']?1:0;$r['is_you']=$r['id']===$viewerId;unset($r['owner_user_id']);$members[$r['id']]=$r;}
 $q=$db->prepare("SELECT p.user_id,COALESCE(p.points,0) points,f.kickoff_utc FROM predictions p JOIN fixtures f ON f.id=p.fixture_id JOIN league_members lm ON lm.user_id=p.user_id AND lm.league_id=? WHERE f.status='FT' AND f.home_score IS NOT NULL AND f.away_score IS NOT NULL AND julianday(f.kickoff_utc)>=julianday(?) AND julianday(f.kickoff_utc)<julianday(?) AND julianday(f.kickoff_utc)<=julianday('now')");
 $q->execute([$leagueId,$start->format('c'),$end->format('c')]);
 foreach($q as $r){$id=(int)$r['user_id'];if(!isset($members[$id]))continue;$points=(int)$r['points'];$kick=strtotime($r['kickoff_utc']);$members[$id]['pts']+=$points;if($weekStart&&$kick<$weekStart->getTimestamp())$members[$id]['before_pts']+=$points;elseif($weekEnd&&$kick<$weekEnd->getTimestamp())$members[$id]['round_pts']+=$points;}
 $before=array_values($members);usort($before,fn($a,$b)=>($b['before_pts']<=>$a['before_pts'])?:($a['id']<=>$b['id']));$ranks=[];$prev=null;$rank=0;foreach($before as $i=>$m){if($prev!==$m['before_pts'])$rank=$i+1;$ranks[$m['id']]=$rank;$prev=$m['before_pts'];}
 $rows=array_values($members);usort($rows,fn($a,$b)=>($b['pts']<=>$a['pts'])?:($a['is_bot']<=>$b['is_bot'])?:($a['id']<=>$b['id']));
 $prev=null;$rank=0;$above=null;$mine=null;
 foreach($rows as $i=>&$m){if($prev!==$m['pts']){$above=$prev;$rank=$i+1;}$m['rank']=$rank;$m['movement']=$weekStart?$ranks[$m['id']]-$rank:0;$m['gap']=$above===null?0:$above-$m['pts'];$m['gap_leader']=$rows[0]['pts']-$m['pts'];if($m['is_you'])$mine=$m;$prev=$m['pts'];unset($m['before_pts']);}unset($m);
 return ['members'=>$rows,'you'=>$mine,'week_label'=>$label,'has_week'=>$weekStart!==null];
}
function leagueMovementHtml(int $move):string{return '<span class="leaguemove '.($move>0?'up':($move<0?'down':'same')).'" aria-label="'.($move>0?'Up '.$move.' places':($move<0?'Down '.abs($move).' places':'No change')).'">'.($move>0?'↑ '.$move:($move<0?'↓ '.abs($move):'—')).'</span>';}
function leaguePersonalHtml(array $progress):string{
 $m=$progress['you'];if(!$m)return '';
 return '<div class="leaguepersonal"><div><strong>#'.$m['rank'].'</strong><span>Your position</span></div><div><strong>'.$m['round_pts'].'</strong><span>Points this week</span></div><div><strong>'.($m['gap']?$m['gap'].' pts':'Leading').'</strong><span>'.($m['gap']?'to next position':'joint or outright').'</span></div></div><p class="leagueprogressnote">'.htmlspecialchars($progress['week_label']).' · Movement since the start of this week, using current league members. Equal points share a position.</p>';
}
