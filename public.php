<?php
declare(strict_types=1); require_once __DIR__.'/inc/config.php';
date_default_timezone_set('Europe/London');
$db=new PDO('sqlite:'.pc_db_path(),null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function slug($v){return strtolower(trim(preg_replace('/[^a-z0-9]+/i','-',$v),'-'));}
function uk($v){return (new DateTimeImmutable($v,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/London'))->format('D d M Y, H:i');}
require_once __DIR__.'/clubs.php';
require_once __DIR__.'/odds.php';
$catalog=pcClubCatalog($db);
$clubMap=[];$clubNames=[];
foreach($catalog['aliases'] as $raw=>$name){$clubMap[slug($raw)]=$name;}
foreach($catalog['clubs'] as $name=>$metadata){
 $clubMap[slug($name)]=$name;
 $clubNames[$name]=[$name];
}
foreach($catalog['aliases'] as $raw=>$name)$clubNames[$name][]=$raw;
$currentClubs=array_fill_keys(pcSeasonClubs($db),true);
$teams=array_keys($currentClubs);sort($teams);

$kind=$_GET['kind']??'clubs';$club=null;$match=null;$rows=[];$page=max(1,(int)($_GET['p']??1));$pages=1;
$title='Premier League clubs';$description='Explore Premier League club fixtures, recent results and score prediction challenges on PredictionComp.';$path='/clubs';
if($kind==='club'){
 $club=$clubMap[$_GET['slug']??'']??null;
 if(!$club){http_response_code(404);$title='Club not found';$kind='missing';}
 else{$names=$clubNames[$club];$placeholders=implode(',',array_fill(0,count($names),'?'));$clubWhere="(home COLLATE NOCASE IN ($placeholders) OR away COLLATE NOCASE IN ($placeholders))";$clubParams=array_merge($names,$names);$path='/clubs/'.slug($club);$title=$club.' fixtures, form & predictions';$description=$club.' fixtures, results and form this season, with plain-English summaries and match previews. Predict their scores for free on PredictionComp.';
 $q=$db->prepare("SELECT * FROM fixtures WHERE {$clubWhere} AND julianday(kickoff_utc)>=julianday('now') ORDER BY julianday(kickoff_utc)");$q->execute($clubParams);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
 $q=$db->prepare("SELECT * FROM fixtures WHERE {$clubWhere} AND status IN ('FT','AET','PEN') AND home_score IS NOT NULL AND away_score IS NOT NULL ORDER BY julianday(kickoff_utc) DESC");$q->execute($clubParams);$clubPrevious=$q->fetchAll(PDO::FETCH_ASSOC);$recent=array_slice($clubPrevious,0,5);}
}elseif($kind==='fixtures'||$kind==='results'){
 $isResults=$kind==='results';$path=$isResults?'/results':'/fixtures';$title=$isResults?'Premier League results':'Premier League fixtures';$description=$isResults?'Browse completed Premier League results, with final scores and match reviews, newest first.':'Browse upcoming Premier League fixtures, with UK kick-off times and match prediction pages.';
 $where=$isResults?"status IN ('FT','AET','PEN') AND home_score IS NOT NULL AND away_score IS NOT NULL":"julianday(kickoff_utc)>=julianday('now') AND status NOT IN ('FT','AET','PEN')";
 $order=$isResults?'DESC':'ASC';
 $total=(int)$db->query("SELECT COUNT(*) FROM fixtures WHERE ".$where)->fetchColumn();$pages=max(1,(int)ceil($total/20));$page=min($pages,$page);
 $rows=$db->query("SELECT * FROM fixtures WHERE ".$where." ORDER BY julianday(kickoff_utc) ".$order.", id ".$order." LIMIT 20 OFFSET ".(($page-1)*20))->fetchAll(PDO::FETCH_ASSOC);
 if($page>1){$path.='?p='.$page;$title.=' · Page '.$page;}
}elseif($kind==='league'){
 // Read-only league page for anyone with the invite code. Picks stay hidden until kick-off. Not indexed.
 $code=strtoupper(preg_replace('/[^A-Za-z0-9]/','',(string)($_GET['code']??'')));
 $q=$db->prepare('SELECT id,code,name FROM leagues WHERE code=?');$q->execute([$code]);$league=$q->fetch(PDO::FETCH_ASSOC);
 if(!$league){http_response_code(404);$title='League not found';$kind='missing';}
 else{$path='/league/'.$league['code'];$title=$league['name'];$description='Join '.$league['name'].', a free Premier League prediction league on PredictionComp. Predict the scores, beat your friends and beat the bots.';}
}elseif($kind==='match'){
 $q=$db->prepare('SELECT * FROM fixtures WHERE id=?');$q->execute([(int)($_GET['id']??0)]);$match=$q->fetch(PDO::FETCH_ASSOC);
 if(!$match){http_response_code(404);$title='Fixture not found';$kind='missing';}
 else{$path='/fixtures/'.$match['id'];$when=(new DateTimeImmutable($match['kickoff_utc'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/London'))->format('D j M Y');
 if($match['home_score']!==null&&$match['away_score']!==null&&in_array($match['status'],['FT','AET','PEN'],true)){$title=$match['home'].' '.$match['home_score'].'–'.$match['away_score'].' '.$match['away'].': result & prediction review';$description=$match['home'].' '.$match['home_score'].'–'.$match['away_score'].' '.$match['away'].' ('.$when.'). See the form going in and how PredictionComp players and bots predicted the match.';}
 else{$title=$match['home'].' v '.$match['away'].' prediction & preview';$description=$match['home'].' v '.$match['away'].' on '.$when.': recent form, key numbers and head-to-head. Predict the score for free and take on five levels of bots.';}}
}
require_once __DIR__.'/data-status.php';
require __DIR__.'/player-nav.php';
require __DIR__.'/seo-content.php';
require __DIR__.'/bot-insights.php';
require_once __DIR__.'/inc/league-share.php';
if($kind==='league'){
 require __DIR__.'/league-progress.php';
 $leagueStart=new DateTimeImmutable(pcSeasonYear().'-08-01 00:00:00',new DateTimeZone('Europe/London'));
 $leagueTable=friendLeagueProgress($db,(int)$league['id'],0,$leagueStart,$leagueStart->modify('+10 months'));
 $viewerToken=(string)($_COOKIE['pc_token']??'');$isMember=false;
 if($viewerToken!==''){$q=$db->prepare('SELECT 1 FROM league_members lm JOIN users u ON u.id=lm.user_id WHERE lm.league_id=? AND u.token=?');$q->execute([(int)$league['id'],$viewerToken]);$isMember=(bool)$q->fetchColumn();}
 $leagueWeek=pcWeekFixtures($db,weekKey(gmdate('c')));
 $leaguePicks=[];
 if($leagueWeek){
  $ids=implode(',',array_map(fn($f)=>(int)$f['id'],$leagueWeek));
  $q=$db->prepare("SELECT p.user_id,p.fixture_id,p.home_score,p.away_score,p.points FROM predictions p JOIN league_members lm ON lm.user_id=p.user_id AND lm.league_id=? WHERE p.fixture_id IN ($ids)");
  $q->execute([(int)$league['id']]);foreach($q as $r)$leaguePicks[(int)$r['user_id']][(int)$r['fixture_id']]=$r;
 }
}
$clubSummary='';
if($kind==='club'&&$club){
 require_once __DIR__.'/standings.php';$sy=pcSeasonYear();
 foreach(leagueStandings($db,$sy.'-07-01T00:00:00Z',($sy+1).'-07-01T00:00:00Z')['rows'] as $r){if($r['team']===$club&&$r['p']>0){
  $ord=fn($n)=>$n.(in_array($n%100,[11,12,13],true)?'th':(['th','st','nd','rd'][$n%10]??'th'));
  $clubSummary=$club.' are '.$ord((int)$r['position']).' in our Premier League table with '.pcCount((int)$r['pts'],'point').' from '.pcCount((int)$r['p'],'match','matches').' ('.pcWords([pcCount((int)$r['w'],'win'),pcCount((int)$r['d'],'draw'),pcCount((int)$r['l'],'defeat')]).'), scoring '.$r['gf'].' and conceding '.$r['ga'].'.';}}
 if($rows){$n=$rows[0];$clubSummary.=' Next up: '.$n['home'].' v '.$n['away'].' on '.(new DateTimeImmutable($n['kickoff_utc'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/London'))->format('D j M, H:i').' UK time.';}
 $clubSummary=trim($clubSummary);
}
if($kind==='table'){
 require __DIR__.'/standings.php';
 $seasonYear=pcSeasonYear();
 $standings=leagueStandings($db,$seasonYear.'-07-01T00:00:00Z',($seasonYear+1).'-07-01T00:00:00Z');
 $lastDone=$db->query("SELECT kickoff_utc FROM fixtures WHERE status IN ('FT','AET','PEN') AND home_score IS NOT NULL ORDER BY julianday(kickoff_utc) DESC LIMIT 1")->fetchColumn();$simWeek=[];$simWeekLabel='';
 if($lastDone){$wk=weekKey((string)$lastDone);$simWeekLabel='Week of '.date('j M',strtotime($wk));$simWeek=pcWeekFixtures($db,$wk);}
 $title='Premier League table '.$seasonYear.'/'.substr((string)($seasonYear+1),2);$path='/table';$description='Premier League standings calculated from completed results on PredictionComp, including points, goal difference and recent form.';
}


if($kind==='guides'||$kind==='guide'){
 $all=$db->query('SELECT * FROM fixtures ORDER BY julianday(kickoff_utc)')->fetchAll(PDO::FETCH_ASSOC);$weeks=[];
 foreach($all as $f)$weeks[weekKey($f['kickoff_utc'])][]=$f;
 $currentWeek=weekKey(gmdate('c'));$upcomingWeeks=array_filter($weeks,fn($key)=>$key>=$currentWeek,ARRAY_FILTER_USE_KEY);$pastWeeks=array_reverse(array_filter($weeks,fn($key)=>$key<$currentWeek,ARRAY_FILTER_USE_KEY),true);$guideWeeks=$upcomingWeeks+$pastWeeks;
 $path='/prediction-guides';$title='Weekly Premier League prediction guides';$description='Plan your Premier League score predictions with weekly fixtures, recent form, match previews and result reviews.';
 if($kind==='guide'){$key=$_GET['week']??'';if(!isset($weeks[$key])){http_response_code(404);$kind='missing';$title='Prediction guide not found';}else{$rows=$weeks[$key];$path.='/'.$key;$range=date('j',strtotime($key)).(date('M',strtotime($key))===date('M',strtotime($key.' +6 days'))?'':' '.date('M',strtotime($key))).'–'.date('j M Y',strtotime($key.' +6 days'));$title='Premier League predictions: '.$range;$games=array_slice(array_map(fn($f)=>$f['home'].' v '.$f['away'],$rows),0,2);$description='Form, previews and results for all '.count($rows).' Premier League matches on '.$range.($games?', including '.implode(' and ',$games):'').'. Predict every score for free.';}}
}

function matchLink($f){return '/fixtures/'.(int)$f['id'];}
function fixtureRows($rows){
 global $db;
 $day=null;
 foreach($rows as $f){
  $kick=(new DateTimeImmutable($f['kickoff_utc'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/London'));
  if($day!==$kick->format('Y-m-d')){
   if($day!==null)echo '</div></details>';
   $day=$kick->format('Y-m-d');
   echo '<details class="matchday datefold" open><summary class="matchday-heading"><time datetime="'.e($day).'">'.e($kick->format('l j M Y')).'</time><span class="foldhint">Hide matches</span></summary><div class="matchday-games">';
  }
  $done=in_array($f['status'],['FT','AET','PEN'],true)&&$f['home_score']!==null&&$f['away_score']!==null;
  $status=fixtureStatusLabel($f);
  $label=$done?($f['status']==='FT'?'FT':$status):($f['status']==='NS'?'':$status);
  echo '<a class="matchrow" href="'.matchLink($f).'" aria-label="'.e($f['home'].' v '.$f['away'].', '.($done?$f['home_score'].'–'.$f['away_score'].', '.$status:$kick->format('H:i').' UK, '.$status).'. Match details').'">';
  echo '<span class="matchrow-teams">';
  foreach(['home','away'] as $side)echo '<span class="matchrow-team"><img src="/club-logo.php?team='.urlencode($f[$side]).'" alt="" width="32" height="32" loading="lazy"><span>'.e($f[$side]).'</span></span>';
  echo '</span><span class="matchrow-summary">';
  if($done)echo '<span class="matchrow-score"><b>'.e($f['home_score']).'</b><b>'.e($f['away_score']).'</b></span>';
  else echo '<time datetime="'.e($kick->format(DATE_ATOM)).'">'.e($kick->format('H:i')).'</time>';
  if($label!=='')echo '<small>'.e($label).'</small>';
  echo '</span><span class="matchrow-arrow" aria-hidden="true">›</span></a>';
  echo pcOddsHtml($db,(int)$f['id'],$f['kickoff_utc']);
 }
 if($day!==null)echo '</div></details>';
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($title)?> | PredictionComp</title><meta name="description" content="<?=e($description)?>"><meta name="robots" content="<?=$kind==='league'?'noindex,nofollow':($kind==='missing'||($kind==='guide'&&($_GET['week']??'')>date('Y-m-d',strtotime('+14 days')))?'noindex,follow':'index,follow')?>"><link rel="canonical" href="https://predictioncomp.com<?=e($path)?>"><meta property="og:title" content="<?=e($title)?> | PredictionComp"><meta property="og:description" content="<?=e($description)?>"><meta property="og:url" content="https://predictioncomp.com<?=e($path)?>"><meta property="og:type" content="website"><meta property="og:site_name" content="PredictionComp"><meta property="og:image" content="https://predictioncomp.com/assets/og-image.png"><meta property="og:image:width" content="1200"><meta property="og:image:height" content="630"><meta property="og:image:alt" content="PredictionComp: free Premier League prediction game"><meta name="twitter:card" content="summary_large_image"><script type="application/ld+json"><?=json_encode(['@context'=>'https://schema.org','@type'=>'BreadcrumbList','itemListElement'=>[['@type'=>'ListItem','position'=>1,'name'=>'PredictionComp','item'=>'https://predictioncomp.com/'],['@type'=>'ListItem','position'=>2,'name'=>$title,'item'=>'https://predictioncomp.com'.$path]]],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES)?></script><?php if($kind==='match'&&$match):
// Prediction previews and result reviews are web pages, not event attendance listings.
$pageSchema=['@context'=>'https://schema.org','@type'=>'WebPage','@id'=>'https://predictioncomp.com'.$path.'#webpage','url'=>'https://predictioncomp.com'.$path,'name'=>$title,'description'=>$description,'inLanguage'=>'en-GB','isAccessibleForFree'=>true,'isPartOf'=>['@type'=>'WebSite','@id'=>'https://predictioncomp.com/#website','name'=>'PredictionComp','url'=>'https://predictioncomp.com/'],'about'=>[['@type'=>'SportsTeam','name'=>$match['home']],['@type'=>'SportsTeam','name'=>$match['away']]]];
?><script type="application/ld+json"><?=json_encode($pageSchema,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?></script><?php endif;?><link rel="icon"  href="/favicon.svg"><style>
*{box-sizing:border-box}body{margin:0;background:#08151d;color:#eef5f8;font:16px system-ui}a{color:#77eaa5;text-decoration:none}a:hover{text-decoration:underline}header,main,footer{max-width:1050px;margin:auto;padding:20px}nav{display:flex;flex-wrap:wrap;gap:18px;align-items:center}nav a:first-child{font-weight:900;font-size:21px;color:white;margin-right:auto}h1{font-size:clamp(30px,5vw,48px);letter-spacing:-1px;line-height:1.12}h2{font-size:23px}p{color:#b6c4cd;line-height:1.65}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px}.club,.panel{background:#102530;border:1px solid #29424f;border-radius:13px;padding:18px}.club{display:flex;align-items:center;gap:12px;color:#eef5f8;font-weight:800}.club img{width:34px;height:34px;object-fit:contain}.fixture{display:flex;flex-direction:column;gap:7px;padding:17px;border-bottom:1px solid #29424f;color:#eef5f8}.fixture time,.fixture small{font-size:12px;color:#9ab0bc}.fixture strong span{color:#77eaa5}.cta{display:inline-block;padding:13px 17px;background:#35df7b;color:#062014;border-radius:9px;font-weight:900}.pager{display:flex;justify-content:space-between;gap:15px;margin:22px 0}.formrow{display:flex;flex-wrap:wrap;gap:7px}.badge{padding:6px 10px;border-radius:7px;background:#173d2b;color:#9cf1b7;font-weight:800}.badge.loss{background:#382325;color:#e7aaac}.badge.draw{background:#332e21;color:#e9cf9a}.note{font-size:12px}.columns{display:grid;grid-template-columns:1fr 1fr;gap:20px}footer{border-top:1px solid #29424f;font-size:12px}footer nav{margin-bottom:12px}footer nav a:first-child{font-size:12px;margin:0;font-weight:400}@media(max-width:650px){.columns{grid-template-columns:1fr}header,main,footer{padding:16px}nav{gap:12px;font-size:13px}}
.preview,.insights{margin-top:22px}.grid .panel p{margin-bottom:0}.panel strong{color:#eef5f8}.shareguide{border:1px solid #40596a;background:#132635;color:#eef5f8;border-radius:8px;padding:8px 12px;cursor:pointer;font:inherit}#shareStatus{margin-left:10px;font-size:13px}.standingswrap{border:1px solid #29424f;border-radius:12px;overflow:hidden;background:#10232e}.standings{width:100%;border-collapse:collapse;font-size:13px;font-variant-numeric:tabular-nums}.standings th,.standings td{padding:12px 8px;border-bottom:1px solid #263e4b;text-align:center}.standings thead th{background:#16303d;color:#a8bcc8;font-size:11px}.standings tbody tr:last-child>*{border-bottom:0}.standings tbody tr:hover{background:#16323d}.standings .clubcol{text-align:left}.standings tbody th{text-align:left;font-weight:650}.tableclub{display:flex;gap:9px;align-items:center;color:#eaf3f7}.tableclub img{flex:0 0 24px;object-fit:contain}.tablepoints{color:#92efb4;font-weight:900}.tableform{display:flex;gap:3px;justify-content:center}.tableform span{width:20px;height:22px;display:grid;place-items:center;font-size:10px;font-weight:800;border-radius:4px}.formW{background:#214c36;color:#a3efbc}.formD{background:#473a23;color:#efd399}.formL{background:#452b32;color:#edb2bd}.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%)}header nav a:not(:first-child),.compactlink{padding:8px 11px;border:1px solid #2c4654;border-radius:8px;background:#102530;color:#bdcfd9;font-size:12px;font-weight:700;white-space:nowrap}header nav a[aria-current="page"]{color:#a6efc0;border-color:#47745a;background:#17372a}header nav a:hover,.compactlink:hover{background:#1b3644;text-decoration:none}.compactlink{display:inline-flex;gap:16px;align-items:center}@media(max-width:650px){.standings .extended{display:none}.standings th,.standings td{padding:10px 5px}.tableclub{gap:6px;font-size:12px}.tableclub span{overflow-wrap:anywhere}.formcol{display:none}header nav{gap:7px}header nav a:first-child{flex-basis:100%;margin-bottom:7px}.tableclub img{width:22px;height:22px;flex-basis:22px}}</style><link rel="stylesheet" href="/player-nav.css?v=6"><link rel="manifest" href="/manifest.webmanifest"><meta name="theme-color" content="#0a0f17"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="PredictionComp"><link rel="apple-touch-icon" href="/assets/app/icon-180.png"><script src="/pwa.js?v=1" defer></script><link rel="preload" href="/assets/fonts/barlow-500.woff2" as="font" type="font/woff2" crossorigin><link rel="preload" href="/assets/fonts/barlow-condensed-700.woff2" as="font" type="font/woff2" crossorigin><link rel="stylesheet" href="/assets/theme.css?v=2"><link rel="stylesheet" href="/assets/public-extras.css?v=1"><style>
body>main .fixtures-title{font-size:clamp(34px,5vw,46px);margin:20px 0 12px}
body>main .fixtures-intro{font-size:16px;margin:16px 0}
.matchday{margin:22px 0 28px}
body>main .matchday-heading{margin:0;padding:14px 16px;border-left:4px solid var(--gold);border-bottom:1px solid var(--gold-line);border-radius:10px 10px 0 0;background:var(--ink-2);color:var(--text);font:700 19px/1.3 var(--font);text-transform:none;letter-spacing:0}
.matchday-games{border:1px solid var(--line);border-top:0;border-radius:0 0 10px 10px;overflow:hidden}
body>main a.matchrow{display:grid;grid-template-columns:minmax(0,1fr) auto 12px;gap:18px;align-items:center;padding:16px 18px;background:var(--ink-1);border-bottom:1px solid var(--line);color:var(--text);text-decoration:none}
body>main a.matchrow:last-child{border-bottom:0}
body>main a.matchrow:hover,body>main a.matchrow:focus-visible{background:var(--ink-2)}
.matchrow-teams{display:grid;gap:10px;min-width:0}
.matchrow-team{display:flex;align-items:center;gap:12px;min-height:34px;font-size:20px;line-height:1.25;font-weight:600}
.matchrow-team img{width:32px;height:32px;object-fit:contain;flex:0 0 32px}
.matchrow-summary{display:grid;justify-items:center;gap:5px;min-width:58px}
.matchrow-summary time{font-size:20px;font-weight:700}
.matchrow-summary small{font-size:14px;color:var(--text-2);max-width:100px;text-align:center}
.matchrow-score{display:grid;gap:10px;font-size:22px;line-height:34px;text-align:center}
.matchrow-arrow{font-size:26px;color:var(--text-3)}
@media(max-width:650px){body>main a.matchrow{gap:10px;padding:14px 12px}.matchrow-team{font-size:18px;gap:10px}.matchrow-summary{min-width:48px}.matchrow-summary time{font-size:19px}body>main .matchday-heading{font-size:18px}.matchrow-summary small{max-width:75px}.matchrow-team span{overflow-wrap:break-word}}
</style><style>
.datefold>summary{cursor:pointer;list-style:none;display:flex;align-items:center;gap:12px}
.datefold>summary::-webkit-details-marker{display:none}
.datefold>summary:before{content:"▾";display:inline-block;font-size:18px}
.datefold:not([open])>summary:before{content:"▸"}
.datefold>summary:focus-visible{outline:2px solid var(--gold,#77eaa5);outline-offset:-2px}
.datefold>summary .foldhint{margin-left:auto;font:500 12px system-ui;opacity:.75}
.datefold:not([open])>summary .foldhint{font-size:0}
.datefold:not([open])>summary .foldhint:after{content:"Show matches";font:500 12px system-ui}
.predictionday>.fixtureday{margin:0;padding:14px 16px}
</style><link rel="stylesheet" href="/assets/odds.css?v=1"><link rel="stylesheet" href="/assets/responsive.css?v=20261019"></head><body><header class="siteheader"><?php siteHeaderNavigation();?></header><main><p class="note"><a href="/">PredictionComp</a> / <?=e($title)?></p><h1 class="<?=in_array($kind,['fixtures','results'],true)?'fixtures-title':''?>"><?=$kind==='fixtures'?'Fixtures':($kind==='results'?'Results':e($title))?></h1><?php if($kind!=='missing')echo dataFreshnessHtml($db);?>
<?php if($kind==='clubs'):?><p>Follow a club’s upcoming matches, check its recent results and test your score predictions against five levels of bot opponents. Choose your club below.</p><div class="grid"><?php foreach($teams as $t):?><a class="club" href="/clubs/<?=e(slug($t))?>"><img src="/club-logo.php?team=<?=urlencode($t)?>" alt="" width="34" height="34" loading="lazy"><?=e($t)?></a><?php endforeach;?></div>
<?php elseif($kind==='club'):?><?php if($clubSummary):?><p class="lede"><?=e($clubSummary)?></p><?php endif;?><p>Plan your <?=e($club)?> predictions using the upcoming fixtures and the latest completed results in our database. Predictions can be changed until kick-off: an exact score earns 5 points, and the correct win, draw or loss earns 3.</p><a class="cta" href="/#predict">Make your predictions</a><h2>Recent form</h2><?php if(!$recent):?><p>No completed results available yet.</p><?php else:?><p><?=e(formSummary($recent,$club))?></p><div class="formrow"><?php foreach(array_reverse($recent) as $f):$ours=pcClubName($f['home'],$db)===$club?$f['home_score']:$f['away_score'];$theirs=pcClubName($f['home'],$db)===$club?$f['away_score']:$f['home_score'];$r=$ours>$theirs?'W':($ours<$theirs?'L':'D');?><a class="badge <?=$r==='L'?'loss':($r==='D'?'draw':'')?>" href="<?=matchLink($f)?>" aria-label="<?=e($r.' '.$f['home'].' '.$f['home_score'].'–'.$f['away_score'].' '.$f['away'])?>"><?=$r?></a><?php endforeach;?></div><p class="note">Oldest to newest · W win, D draw, L loss. Based on the last five completed league matches in our database.</p><?php endif;?>
<div class="clubfixturetabs" role="tablist" aria-label="Club fixtures" hidden>
<button type="button" id="club-upcoming-tab" role="tab" aria-controls="club-upcoming-panel" aria-selected="true">Upcoming</button>
<button type="button" id="club-previous-tab" role="tab" aria-controls="club-previous-panel" aria-selected="false" tabindex="-1">Results</button>
</div>
<section id="club-upcoming-panel" role="tabpanel" aria-labelledby="club-upcoming-tab"><h2>Upcoming fixtures</h2><?php fixtureRows($rows);?></section>
<section id="club-previous-panel" role="tabpanel" aria-labelledby="club-previous-tab"><h2>Results</h2><?php fixtureRows($clubPrevious);?></section>
<style>.clubfixturetabs{display:flex;gap:6px;margin:26px 0 20px;padding:5px;background:var(--ink-2,#161e2a);border:1px solid var(--line,#253043);border-radius:12px;max-width:420px}.clubfixturetabs[hidden]{display:none}.clubfixturetabs button{flex:1;min-height:46px;border:0;border-radius:8px;background:transparent;color:var(--text-2,#a8b3c3);font:600 16px var(--font,system-ui);cursor:pointer}.clubfixturetabs button[aria-selected="true"]{background:var(--gold,#f4c542);color:var(--gold-ink,#1b1405)}#club-upcoming-panel[hidden],#club-previous-panel[hidden]{display:none}</style>
<script>
(function(){
 const tabs=Array.from(document.querySelectorAll('.clubfixturetabs [role="tab"]'));
 if(!tabs.length)return;
 function select(tab,focus){
  tabs.forEach(t=>{const active=t===tab;t.setAttribute('aria-selected',String(active));t.tabIndex=active?0:-1;document.getElementById(t.getAttribute('aria-controls')).hidden=!active;});
  if(focus)tab.focus({preventScroll:true});
 }
 tabs.forEach((tab,index)=>{
  tab.addEventListener('click',()=>select(tab,false));
  tab.addEventListener('keydown',e=>{
   let next=index;
   if(e.key==='ArrowRight'||e.key==='ArrowLeft')next=(index+1)%tabs.length;
   else if(e.key==='Home')next=0;
   else if(e.key==='End')next=tabs.length-1;
   else return;
   e.preventDefault();select(tabs[next],true);
  });
 });
 document.querySelector('.clubfixturetabs').hidden=false;select(tabs[0],false);
})();
</script>
<?php elseif($kind==='table'):?><p class="tablenote">Calculated from <?=$standings['matches']?> completed league matches stored for this season. The table updates as results are recorded.</p><div class="standingswrap"><table class="standings"><caption class="sr-only">Premier League standings based on stored results</caption><thead><tr><th scope="col">#</th><th scope="col" class="clubcol">Club</th><th scope="col"><abbr title="Played">P</abbr></th><th scope="col" class="extended"><abbr title="Won">W</abbr></th><th scope="col" class="extended"><abbr title="Drawn">D</abbr></th><th scope="col" class="extended"><abbr title="Lost">L</abbr></th><th scope="col" class="extended"><abbr title="Goals for">GF</abbr></th><th scope="col" class="extended"><abbr title="Goals against">GA</abbr></th><th scope="col"><abbr title="Goal difference">GD</abbr></th><th scope="col">Pts</th><th scope="col" class="formcol">Form</th></tr></thead><tbody><?php foreach($standings['rows'] as $r):?><tr><td><?=$r['position']?></td><th scope="row"><a class="tableclub" href="/clubs/<?=e(slug($r['team']))?>"><img src="/club-logo.php?team=<?=urlencode($r['team'])?>" alt="" width="24" height="24" loading="lazy"><span><?=e($r['team'])?></span></a></th><td><?=$r['p']?></td><?php foreach(['w','d','l','gf','ga'] as $stat):?><td class="extended"><?=$r[$stat]?></td><?php endforeach;?><td><?=$r['gd']>0?'+':''?><?=$r['gd']?></td><td class="tablepoints"><?=$r['pts']?></td><td class="formcol"><span class="tableform" aria-label="<?=e(implode(', ',array_map(fn($v)=>['W'=>'Win','D'=>'Draw','L'=>'Loss'][$v],$r['form'])))?>"><?php foreach($r['form'] as $result):?><span class="form<?=$result?>" aria-hidden="true"><?=$result?></span><?php endforeach;?></span></td></tr><?php endforeach;?></tbody></table></div><p class="note">3 points for a win, 1 for a draw. Sorted by points, goal difference, then goals scored; clubs tied on all three share a position. Form runs oldest to newest.</p><p class="note">Based on recorded results only. Missing results and any official points deductions are not reflected.</p>
<?php beatSim1Block($db,$simWeek,'Can you beat SIM1?',$simWeekLabel);?>
<?php elseif($kind==='league'):?><?php
 $done=fn($f)=>in_array($f['status'],['FT','AET','PEN'],true)&&$f['home_score']!==null&&$f['away_score']!==null;
 $started=array_values(array_filter($leagueWeek,fn($f)=>strtotime($f['kickoff_utc'])<=time()));
 $open=array_values(array_filter($leagueWeek,fn($f)=>strtotime($f['kickoff_utc'])>time()));
 $members=$leagueTable['members'];$inviteUrl=pcLeagueInviteUrl($league['code']);
?><p class="lede">A friends league on PredictionComp with <?=pcCount(count($members),'player')?>. One set of predictions counts for every league you're in.</p>
<div class="leaguecta"><?php if($isMember):?><a class="cta" href="/?league=<?=(int)$league['id']?>#predict">Make your predictions</a><?php else:?><a class="cta" href="<?=e($inviteUrl)?>">Join <?=e($league['name'])?></a><?php endif;?>
<a class="sharebtn whatsapp" href="<?=e(pcLeagueWhatsAppUrl($league['name'],$league['code']))?>" target="_blank" rel="noopener">Share on WhatsApp</a><button type="button" class="sharebtn" data-url="<?=e($inviteUrl)?>" onclick="copyText(this.dataset.url,this,'✓ Link copied','Copy this invite link:')">Copy invite link</button></div>
<section class="panel"><h2>League table</h2><?php if(!$members):?><p>Nobody has joined yet.</p><?php else:?><div class="standingswrap"><table class="standings leaguetable"><thead><tr><th scope="col">#</th><th scope="col">Player</th><th scope="col" title="<?=e($leagueTable['week_label'])?>">Week</th><th scope="col">Pts</th></tr></thead><tbody>
<?php foreach($members as $m):?><tr><td><?=(int)$m['rank']?></td><td><?=e($m['display_name'])?><?php if(!empty($m['is_bot'])):?> <span class="simbadge">SIM<?=max(1,min(5,(int)$m['bot_grade']))?></span><?php endif;?></td><td><?=(int)$m['round_pts']?></td><td><b><?=(int)$m['pts']?></b></td></tr>
<?php endforeach;?></tbody></table></div><?php endif;?><p class="note">Season points from finished matches. “Week” is the latest completed week (<?=e($leagueTable['week_label'])?>).</p></section>
<section class="panel"><h2>This week</h2>
<?php if($open):$next=$open[0];$saved=fn($uid)=>count(array_filter($open,fn($f)=>isset($leaguePicks[$uid][(int)$f['id']])));?>
<p>Next kick-off: <strong><?=e($next['home'].' v '.$next['away'])?></strong>, <?=e(uk($next['kickoff_utc']))?> UK. Picks are revealed at kick-off.</p><ul class="pickprogress">
<?php foreach($members as $m):$c=$saved((int)$m['id']);?><li class="<?=$c===count($open)?'done':''?>"><span><?=e($m['display_name'])?></span><b><?=$c===count($open)?'✓ ':''?><?=$c?> / <?=count($open)?></b></li><?php endforeach;?></ul>
<?php endif;?>
<?php if($started):?><h3>Picks for matches that have kicked off</h3><div class="pickgridwrap"><table class="pickgrid"><thead><tr><th scope="col">Player</th>
<?php foreach($started as $f):?><th scope="col"><a href="<?=matchLink($f)?>"><?=e(pcShortName($f['home']))?><br><?=$done($f)?'<b>'.(int)$f['home_score'].'–'.(int)$f['away_score'].'</b>':'<small>Live</small>'?><br><?=e(pcShortName($f['away']))?></a></th><?php endforeach;?></tr></thead><tbody>
<?php foreach($members as $m):?><tr><th scope="row"><?=e($m['display_name'])?></th><?php foreach($started as $f):$p=$leaguePicks[(int)$m['id']][(int)$f['id']]??null;$cls=$p&&$p['points']!==null?['0'=>'miss','3'=>'result','5'=>'exact'][(string)(int)$p['points']]??'':'';?><td class="<?=$cls?>"><?=$p?(int)$p['home_score'].'–'.(int)$p['away_score']:'—'?></td><?php endforeach;?></tr>
<?php endforeach;?></tbody></table></div><p class="note"><span class="exact">Exact score 5</span> · <span class="result">Right result 3</span> · <span class="miss">Wrong 0</span></p>
<?php elseif(!$open):?><p>No matches this week.</p><?php endif;?></section>
<?php beatSim1Block($db,$leagueWeek,'This week vs SIM1','',array_map(fn($m)=>(int)$m['id'],array_filter($members,fn($m)=>empty($m['is_bot']))));?>
<script src="/assets/share.js?v=1"></script>
<?php elseif($kind==='fixtures'||$kind==='results'):?><p class="fixtures-intro">Premier League · <?=$kind==='results'?'Completed matches · Newest first':'Upcoming matches · All times UK'?></p><?php if(!$rows):?><p><?=$kind==='results'?'No completed results available yet.':'No upcoming fixtures available yet.'?></p><?php else:fixtureRows($rows);endif;?><nav class="pager" aria-label="<?=$kind==='results'?'Result':'Fixture'?> pages"><?php if($page>1):?><a href="/<?=$kind?>?p=<?=$page-1?>">← Previous</a><?php endif;?><span>Page <?=$page?> of <?=$pages?></span><?php if($page<$pages):?><a href="/<?=$kind?>?p=<?=$page+1?>">Next →</a><?php endif;?></nav>
<?php elseif($kind==='guides'):?><p>Prepare your free Premier League game predictions with factual match previews. Each guide groups fixtures from Friday to Thursday in UK time; rearranged matches appear in the week they are scheduled to take place.</p><div class="grid"><?php foreach($guideWeeks as $key=>$games):?><a class="panel" href="/prediction-guides/<?=e($key)?>"><strong>Week of <?=e(date('j M Y',strtotime($key)))?></strong><p><?=count($games)?> fixtures · Previews &amp; results</p></a><?php endforeach;?></div>
<?php elseif($kind==='guide'):?><p>Compare recent team form, make your own score predictions and review the results after the matches. These summaries use recorded league results rather than injury news or predicted line-ups.</p><a class="cta" href="/#predict">Play the free prediction game</a><p><a href="/prediction-guides">All weekly guides</a> · <button type="button" class="shareguide" onclick="shareGuide(this)">Share this guide</button><span id="shareStatus" role="status"></span></p><?php weekReviewBlock($db,$rows);beatSim1Block($db,$rows);foreach($rows as $f):previewBlock($db,$f,true);endforeach;?><p class="note">Form summaries use up to five completed league matches in our records before each fixture. Missing results can limit the sample.</p>
<?php elseif($kind==='match'):?><section class="panel"><p><strong><?=e(uk($match['kickoff_utc']))?> UK</strong> · <?=e(fixtureStatusLabel($match))?></p><?php if($match['home_score']!==null&&$match['away_score']!==null):?><h2>Result: <?=e($match['home_score'].'–'.$match['away_score'])?></h2><?php endif;?><p>Predict <?=e($match['home'])?> v <?=e($match['away'])?> and compare your judgement with the bots. The challenge is to choose the score, with 5 points for an exact score or 3 for the correct result.</p><?php if(strtotime($match['kickoff_utc'])>time()):?><a class="cta" href="/?fixture=<?=(int)$match['id']?>#predict">Predict this match</a><p class="note">No sign-up needed: enter your score and pick a name for the table. Predictions stay private until kick-off.</p><?php else:?><a class="cta" href="/#predict">Predict upcoming matches</a><?php endif;?></section><?=pcOddsHtml($db,(int)$match['id'],$match['kickoff_utc'])?><?php previewBlock($db,$match);botConsensusBlock($db,$match);keyNumbersBlock($db,$match);insightBlock($db,$match);?><p><a href="/prediction-guides/<?=e(weekKey($match['kickoff_utc']))?>" class="compactlink">Weekly guide <span aria-hidden="true">→</span></a></p><h2>Team form before this fixture</h2><div class="columns"><?php foreach([$match['home'],$match['away']] as $team):?><section><h3><a href="/clubs/<?=e(slug($team))?>"><?=e($team)?></a></h3><?php $q=$db->prepare("SELECT * FROM fixtures WHERE (home=? OR away=?) AND status IN ('FT','AET','PEN') AND home_score IS NOT NULL AND away_score IS NOT NULL AND julianday(kickoff_utc)<julianday(?) ORDER BY julianday(kickoff_utc) DESC LIMIT 5");$q->execute([$team,$team,$match['kickoff_utc']]);$form=$q->fetchAll(PDO::FETCH_ASSOC);if(!$form):?><p>No earlier completed results available.</p><?php else:fixtureRows($form);endif;?></section><?php endforeach;?></div><p class="note">Form uses completed results stored for this season. It is context for your prediction, not a guarantee of a result.</p>
<?php else:?><p>This page could not be found. <a href="/fixtures">Browse fixtures</a>.</p><?php endif;?>
<?php if($kind!=='missing'):?><p class="note">Fixture schedules: <a href="https://www.premierleague.com/en/news/4675097/all-380-fixtures-for-202627-premier-league-season" rel="noopener">Premier League published fixture list</a>. Updated nightly when the source is available; dates and times can change. Results may take time to appear.</p><?php endif;?>
</main><footer><nav><a href="/">Home</a><a href="/clubs">Clubs</a><a href="/table" <?=($kind==='table')?'aria-current="page"':''?>>PL Table</a><a href="/fixtures">Fixtures</a><a href="/results">Results</a><a href="/about">About</a><a href="/privacy">Privacy</a><a href="/contact">Contact</a></nav>Independent prediction game. Not affiliated with the Premier League or its clubs.<p class="sistersite" style="margin:10px 0 0;font-size:12px;line-height:1.6;color:inherit">More football fun: <a href="https://clubdailyfive.com/" style="color:inherit;text-decoration:underline;text-underline-offset:3px">ClubDailyFive</a> · daily club quizzes &amp; Player Wordle</p></footer><?php playerNavigation();?><script>async function shareGuide(button){const data={title:document.title,text:'Compare the fixtures and team form, then join our free Premier League prediction game.',url:document.querySelector('link[rel="canonical"]').href};try{if(navigator.share){await navigator.share(data)}else{await navigator.clipboard.writeText(data.url);document.getElementById('shareStatus').textContent='Link copied'}}catch(e){if(e.name!=='AbortError')window.prompt('Copy this guide link:',data.url)}}</script></body></html>