<?php
declare(strict_types=1);
require_once __DIR__.'/clubs.php';
$catalog=pcClubCatalog();
$team=pcClubName((string)($_GET['team']??''));
$badge=$catalog['clubs'][$team]['badge']??null;
header('Cache-Control: public, max-age=21600');
if($badge&&(str_starts_with($badge,'https://')||(str_starts_with($badge,'/')&&!str_starts_with($badge,'//')))){header('Location: '.$badge,true,302);exit;}
header('Content-Type: image/svg+xml');
echo '<svg xmlns="http://www.w3.org/2000/svg" width="50" height="50"><circle cx="25" cy="25" r="22" fill="#e5eaed"/><text x="25" y="31" text-anchor="middle" font-size="20">⚽</text></svg>';
