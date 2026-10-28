<?php
declare(strict_types=1);
$view=(string)($_GET['screen']??'');
if(!in_array($view,['results','leagues','account'],true)){http_response_code(404);exit('Page not found');}
unset($_GET['view'],$_GET['api'],$_GET['auth']);
ob_start();require __DIR__.'/index.php';$html=ob_get_clean();
$target=$view==='results'?'historyDialog':($view==='leagues'?'leaguesDialog':'profileDialog');
if($uid<1&&$view!=='leagues')$target='authDialog';
$found=false;
$html=preg_replace_callback('/<dialog id="'.$target.'">(.*?)<\/dialog>/s',function($m)use(&$found,$view){$found=true;$body=preg_replace('/<button[^>]*class="closex"[^>]*>.*?<\/button>/s','<a class="pageback" href="/#predict">← Predictions</a>',$m[1],1);if(in_array($view,['results','leagues'],true)){$body=preg_replace('/<a class="pageback"[^>]*>.*?<\/a>/s','',$body,1);$body=preg_replace('/<div class="sectionlabel">.*?<\/div>/s','',$body,1);$body=preg_replace('/<h2>(.*?)<\/h2>/s','<h1 class="playerpagetitle">$1</h1>',$body,1);}return '<section id="'.htmlspecialchars($GLOBALS['target']).'" class="standalonepanel">'.$body.'</section>';},$html);
if(!$found){http_response_code(500);exit('Could not load page');}
$title=['results'=>'My results','leagues'=>'My leagues','account'=>'Account'][$view];
$html=preg_replace('/<title>.*?<\/title>/s','<title>'.$title.' | PredictionComp</title>',$html,1);
$html=preg_replace('/<link rel="canonical"[^>]*>/','<link rel="canonical" href="https://predictioncomp.com/'.($view==='results'?'my-results':$view).'">',$html,1);
$html=preg_replace('/<meta name="robots"[^>]*>/','<meta name="robots" content="noindex,follow">',$html,1);
$css='<style>body{min-height:100vh;min-height:100dvh;display:flex;flex-direction:column}.top{flex-shrink:0}.main{flex:1 0 auto;width:100%}.footer{margin-top:auto;width:100%;flex-shrink:0}.playernav{flex-shrink:0}.top .hero,.features,.main>section:not(.standalonepanel),.main>aside{display:none}.main{display:block;max-width:760px;margin:20px auto}.standalonepanel{display:block;background:#102330;border:1px solid #2c4555;border-radius:14px}.standalonepanel .profilemodal{width:100%;max-width:none;padding:24px;position:relative}.standalonepanel .closex{display:none}.pageback{display:inline-block;font-size:12px;color:#a6eac0;text-decoration:none;margin-bottom:18px}.standalonepanel .historylist,.standalonepanel .performancebody{max-height:none;overflow:visible}.standalonepanel .profilemodal h2{margin-top:0}@media(max-width:720px){.main{padding:0 12px;margin:12px auto}.standalonepanel .profilemodal{padding:18px}.footer{padding-bottom:20px}}</style>';

if(in_array($view,['results','leagues'],true))$css.='<style>
.main{max-width:980px;margin:0 auto;padding:30px 24px 40px}
.standalonepanel{background:transparent;border:0;border-radius:0;box-shadow:none;min-width:0}
.standalonepanel .profilemodal{padding:0;background:transparent;max-height:none;overflow:visible;border:0;border-radius:0;box-shadow:none}
.playerpagetitle{font-size:32px;line-height:1.2;letter-spacing:-.7px;margin:0 0 12px;font-weight:850}
.standalonepanel .explain{max-width:640px;line-height:1.6}
.standalonepanel .resulttabs{margin-top:20px;padding-bottom:14px}
.standalonepanel .performancebody{padding-top:22px}
.standalonepanel .myleagues{margin:28px 0}
.standalonepanel .myleagues h3{font-size:17px;margin-bottom:14px}
.standalonepanel .leagueactions{grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;margin-top:28px}
.standalonepanel .leagueactions>div{padding:20px;background:#0e1d29;border-color:#263b49}
.standalonepanel .leagueitem{padding:16px;background:#0e1d29}
.standalonepanel .emptyhistory{padding:36px 0;text-align:left}
@media(max-width:720px){.main{padding:24px 16px 30px;margin:0 auto}.standalonepanel .profilemodal{padding:0}.playerpagetitle{font-size:28px}.standalonepanel .leagueactions{grid-template-columns:1fr;gap:16px}.standalonepanel .leagueitem{padding:14px}.standalonepanel .performancegrid{gap:10px}}
</style>';
if($view==='results')$css.='<style>
.standalonepanel .recaplinks{margin:24px 0 28px;padding:22px;border:1px solid var(--line,#2b4353);border-radius:12px;background:var(--ink-2,#102631)}
.standalonepanel .recaplinks-heading h2{margin:0;font-size:22px;line-height:1.25;letter-spacing:-.3px}
.standalonepanel .recaplinks-heading p{margin:8px 0 18px;color:var(--text-2,#abc1ce);font-size:14px;line-height:1.5}
.recaplinks-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.standalonepanel a.recaplinks-card{display:flex;align-items:center;justify-content:space-between;gap:16px;min-height:94px;padding:18px;border:1px solid var(--line,#304655);border-radius:10px;background:var(--ink-1,#0e1d29);color:var(--text,#eef5f8);text-decoration:none}
.recaplinks-copy{display:grid;gap:6px;min-width:0}.recaplinks-copy strong{font-size:17px;line-height:1.3;font-weight:700}.recaplinks-copy>span{color:var(--text-2,#abc1ce);font-size:13px;line-height:1.5}.recaplinks-arrow{flex-shrink:0;font-size:24px;color:var(--gold,#f4c542)}
.standalonepanel a.recaplinks-card:hover{border-color:var(--gold,#f4c542);background:var(--ink-2,#102631)}.standalonepanel a.recaplinks-card:focus-visible{outline:2px solid var(--gold,#f4c542);outline-offset:3px}
@media(max-width:600px){.standalonepanel .recaplinks{padding:18px;margin:20px 0 24px}.recaplinks-grid{grid-template-columns:1fr;gap:10px}.standalonepanel a.recaplinks-card{min-height:88px;padding:16px}.standalonepanel .recaplinks-heading h2{font-size:20px}}
</style>';
if($view==='account')$css.='<style>#profileDialog.standalonepanel .profilemodal{max-height:none;overflow:visible;padding-top:24px}.standalonepanel .accountheading{padding-right:0;align-items:center}.standalonepanel .accountheading h2{font-size:26px;line-height:1.2;margin:0}.standalonepanel .accountheading .accountsignout{flex-shrink:0}@media(max-width:720px){.standalonepanel .accountheading h2{font-size:23px}}</style>';
if($view==='leagues'&&($selectedLeagueId||isset($_GET['global']))){
 $css.='<style>.main>aside{display:block}.main>#leaguesDialog{display:none}.main>aside>.promo{display:none}.main>aside>#leader{border:0;background:transparent;padding:24px 28px 28px}@media(max-width:720px){.main>aside>#leader{padding:20px 16px 24px}}.main>aside>#leader>.head{display:block;padding:0 0 18px}.main>aside>#leader>.side{padding:0}.flowhome{display:none!important}</style>';
 $html=str_replace('<h2>'.htmlspecialchars($selectedLeague?$selectedLeague['name']:'Global league').'</h2>','<p><a href="/leagues">← My leagues</a></p><h1>'.htmlspecialchars($selectedLeague?$selectedLeague['name']:'Overall competition').'</h1>',$html);
}
$html=str_replace('</head>',$css.'</head>',$html);
if($view==='results'&&$uid>0){
 require_once __DIR__.'/inc/results-odds.php';
 require_once __DIR__.'/inc/recap.php';
 $html=str_replace('<div class="resulttabs"',dataFreshnessHtml($db).recapLinks().'<div class="resulttabs"',$html);
 $oddsTab='<button type="button" id="oddsTab" role="tab" aria-controls="oddsPanel" aria-selected="false" tabindex="-1" onclick="showResultsTab(\'odds\')">£1 a match</button>';
 $html=preg_replace_callback('/(<button\b[^>]*id="matchesTab"[^>]*>.*?<\/button>)/s',fn($m)=>$m[1].$oddsTab,$html,1);
 $oddsPeriods=personalOddsPeriods($db,$uid,$seasonYear,is_string($_GET['odds_period']??null)?$_GET['odds_period']:'');
 $oddsPanel='<section id="oddsPanel" class="performancebody" role="tabpanel" aria-labelledby="oddsTab" hidden>'.renderPersonalOddsSelector($oddsPeriods).renderPersonalOddsResults(personalOddsResults($db,$uid,$seasonYear,$oddsPeriods['selected']),$oddsPeriods['selected']['label']).'</section>';
 $html=str_replace('<section id="performancePanel"',$oddsPanel.'<section id="performancePanel"',$html);
}
$script='<script>selectPlayerTab('.json_encode($view).');';
if($view==='results'&&$uid>0)$script.='showResultsTab('.json_encode(($_GET['tab']??'')==='odds'?'odds':'matches').');';
if($target==='leaguesDialog')$script.='function closeLeagues(){}function openLeagues(){document.getElementById("leaguesDialog").scrollIntoView({block:"start"});selectPlayerTab("leagues")}';
$script.='document.querySelectorAll(".playernav a").forEach(a=>a.removeAttribute("onclick"));';
if($view==='leagues'&&($selectedLeagueId||isset($_GET['global'])))$script.='document.querySelectorAll("#leader button.leagueviewbtn").forEach(b=>{b.textContent="Manage league"});';
$script.='</script>';
$html=str_replace('</body>',$script.'</body>',$html);
echo $html;
