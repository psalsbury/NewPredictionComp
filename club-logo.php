<?php
$team=$_GET['team']??'';
$map=[
'Arsenal'=>'https://resources.premierleague.com/premierleague/badges/50/t3.png',
'Aston Villa'=>'https://resources.premierleague.com/premierleague/badges/50/t7.png',
'AFC Bournemouth'=>'https://resources.premierleague.com/premierleague/badges/50/t91.png',
'Bournemouth'=>'https://resources.premierleague.com/premierleague/badges/50/t91.png',
'Brentford'=>'https://resources.premierleague.com/premierleague/badges/50/t94.png',
'Brighton & Hove Albion'=>'https://resources.premierleague.com/premierleague/badges/50/t36.png',
'Burnley'=>'https://resources.premierleague.com/premierleague/badges/50/t90.png',
'Chelsea'=>'https://resources.premierleague.com/premierleague/badges/50/t8.png',
'Crystal Palace'=>'https://resources.premierleague.com/premierleague/badges/50/t31.png',
'Everton'=>'https://resources.premierleague.com/premierleague/badges/50/t11.png',
'Fulham'=>'https://resources.premierleague.com/premierleague/badges/50/t54.png',
'Leeds United'=>'https://resources.premierleague.com/premierleague/badges/50/t2.png',
'Liverpool'=>'https://resources.premierleague.com/premierleague/badges/50/t14.png',
'Manchester City'=>'https://resources.premierleague.com/premierleague/badges/50/t43.png',
'Manchester United'=>'https://resources.premierleague.com/premierleague/badges/50/t1.png',
'Newcastle United'=>'https://resources.premierleague.com/premierleague/badges/50/t4.png',
'Nottingham Forest'=>'https://resources.premierleague.com/premierleague/badges/50/t17.png',
'Sunderland'=>'https://resources.premierleague.com/premierleague/badges/50/t56.png',
'Tottenham Hotspur'=>'https://resources.premierleague.com/premierleague/badges/50/t6.png',
'West Ham United'=>'https://resources.premierleague.com/premierleague/badges/50/t21.png',
'Wolverhampton Wanderers'=>'https://resources.premierleague.com/premierleague/badges/50/t39.png'
];
if(isset($map[$team])){header('Location: '.$map[$team],true,302);exit;}
header('Content-Type: image/svg+xml');echo '<svg xmlns="http://www.w3.org/2000/svg" width="50" height="50"><circle cx="25" cy="25" r="22" fill="#e5eaed"/><text x="25" y="31" text-anchor="middle" font-size="20">⚽</text></svg>';
