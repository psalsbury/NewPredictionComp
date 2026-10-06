<?php
function playerNavigation(bool $home=false):void {
 $icons=['predictions'=>'<path d="M6 3v3m12-3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v14H4V6a1 1 0 0 1 1-1Z"/><path d="m8 15 2 2 5-5"/>','results'=>'<path d="M5 20V10m7 10V4m7 16v-7"/>','leagues'=>'<path d="M8 4h8v5a4 4 0 0 1-8 0V4Zm4 9v6m-4 1h8M8 6H4v2a4 4 0 0 0 4 4m8-6h4v2a4 4 0 0 1-4 4"/>','account'=>'<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>'];
 echo '<nav class="playernav" aria-label="Player navigation">';
 foreach(['predictions'=>'Predict','results'=>'My Results','leagues'=>'Leagues'] as $key=>$label){
 $url=['predictions'=>'/#predict','results'=>'/my-results','leagues'=>'/leagues','account'=>'/account'][$key];
 echo '<a href="'.$url.'" data-player-tab="'.$key.'"'.($home?' onclick="return navigatePlayer(event,this)"':'').($home&&$key==='predictions'?' aria-current="page"':'').'><svg viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$icons[$key].'</svg><span>'.$label.'</span></a>';
 }
 echo footballMenu('mobilefootball').'</nav>';
}

function footballMenu(string $class=''):string {
 return '<details class="footballmenu '.$class.'"><summary>Football <span aria-hidden="true">⌄</span></summary><div class="footballitems"><a href="/table">Premier League table</a><a href="/fixtures">Fixtures &amp; results</a><a href="/clubs">Clubs</a><a href="/prediction-guides">Prediction guides</a><a href="/how-it-works">How it works</a></div></details>';
}

function siteHeaderNavigation(string $accountLabel='Account'):void {
 echo '<nav class="sitenavigation" aria-label="Main navigation"><a class="sitebrand" href="/" aria-label="PredictionComp home"><svg class="brandmark" viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="8" fill="#f4c542"/><path d="M9 10.5h14M9 16h14M9 21.5h8" stroke="#1b1405" stroke-width="2.6" stroke-linecap="round"/><circle cx="22.5" cy="21.5" r="2.2" fill="#1b1405"/></svg><span>Prediction<b>Comp</b></span></a><div class="siteprimary"><a href="/#predict">Predict</a><a href="/my-results">My Results</a><a href="/leagues">Leagues</a>'.footballMenu().'</div><a class="siteaccount" href="/account">'.htmlspecialchars($accountLabel,ENT_QUOTES,'UTF-8').'</a></nav>';
}
