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
$title=['results'=>'My Results','leagues'=>'Leagues','account'=>'Account'][$view];
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
if($view==='account')$css.='<style>#profileDialog.standalonepanel .profilemodal{max-height:none;overflow:visible;padding-top:24px}.standalonepanel .accountheading{padding-right:0;align-items:center}.standalonepanel .accountheading h2{font-size:26px;line-height:1.2;margin:0}.standalonepanel .accountheading .accountsignout{flex-shrink:0}@media(max-width:720px){.standalonepanel .accountheading h2{font-size:23px}}</style>';
$html=str_replace('</head>',$css.'</head>',$html);
if($view==='results'&&$uid>0)$html=str_replace('<div class="resulttabs"',dataFreshnessHtml($db).'<div class="resulttabs"',$html);
$script='<script>selectPlayerTab('.json_encode($view).');';
if($view==='results'&&$uid>0)$script.="showResultsTab('performance');";
if($target==='leaguesDialog')$script.='function closeLeagues(){}function openLeagues(){document.getElementById("leaguesDialog").scrollIntoView({block:"start"});selectPlayerTab("leagues")}';
$script.='document.querySelectorAll(".playernav a").forEach(a=>a.removeAttribute("onclick"));';
$script.='</script>';
$html=str_replace('</body>',$script.'</body>',$html);
echo $html;
