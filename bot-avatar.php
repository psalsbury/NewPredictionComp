<?php
declare(strict_types=1);
header('Content-Type: image/svg+xml; charset=UTF-8');
header('Cache-Control: public, max-age=86400');
$id=max(0,(int)($_GET['id']??0));
$db=new PDO('sqlite:/var/lib/predictioncomp/predictioncomp.sqlite');
$q=$db->prepare('SELECT display_name,COALESCE(bot_grade,5) grade FROM users WHERE id=? AND is_bot=1');
$q->execute([$id]);$bot=$q->fetch(PDO::FETCH_ASSOC);
if(!$bot){http_response_code(404);exit;}
$name=(string)$bot['display_name'];$grade=max(1,min(5,(int)$bot['grade']));
$palette=[1=>['#35df7b','#063c2b'],2=>['#5ad7ff','#0a3850'],3=>['#ffd166','#563c00'],4=>['#ff9f68','#542108'],5=>['#c6a7ff','#32165c']];
[$accent,$dark]=$palette[$grade];
$initials=strtoupper(substr(preg_replace('/[^A-Za-z0-9]/','',$name),0,2));
$eye=$grade===1?'▰':($grade===5?'–':'●');
$escaped=htmlspecialchars($name,ENT_QUOTES|ENT_XML1,'UTF-8');
?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120" role="img" aria-labelledby="t d">
<title id="t"><?=$escaped?>, SIM<?=$grade?></title><desc id="d">A friendly illustrated football prediction robot</desc>
<rect width="120" height="120" rx="24" fill="<?=$dark?>"/>
<circle cx="60" cy="58" r="37" fill="#eef7fa" stroke="<?=$accent?>" stroke-width="5"/>
<path d="M60 19V9M52 9h16" stroke="<?=$accent?>" stroke-width="5" stroke-linecap="round"/>
<rect x="34" y="42" width="52" height="29" rx="10" fill="<?=$dark?>"/>
<text x="45" y="62" fill="<?=$accent?>" font-size="19" text-anchor="middle" font-family="Arial"><?=$eye?></text>
<text x="75" y="62" fill="<?=$accent?>" font-size="19" text-anchor="middle" font-family="Arial"><?=$eye?></text>
<path d="M45 82q15 11 30 0" fill="none" stroke="<?=$dark?>" stroke-width="5" stroke-linecap="round"/>
<circle cx="25" cy="59" r="7" fill="<?=$accent?>"/><circle cx="95" cy="59" r="7" fill="<?=$accent?>"/>
<rect x="38" y="101" width="44" height="15" rx="7" fill="<?=$accent?>"/>
<text x="60" y="112" fill="<?=$dark?>" font-size="10" font-weight="700" text-anchor="middle" font-family="Arial"><?=$initials?></text>
</svg>