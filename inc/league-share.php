<?php
declare(strict_types=1);
// One source for league invite links and share wording. The homepage passes the template to its JS as PC.leagueShare.
const PC_LEAGUE_SHARE_TEMPLATE='Join my PredictionComp league "{name}": free Premier League score predictions. Can you beat us (and the bots)?';
const PC_LEAGUE_INVITE_BASE='https://predictioncomp.com/?invite=';
function pcLeagueInviteUrl(string $code):string{return PC_LEAGUE_INVITE_BASE.rawurlencode($code);}
function pcLeagueShareText(string $name):string{return str_replace('{name}',$name,PC_LEAGUE_SHARE_TEMPLATE);}
function pcLeagueWhatsAppUrl(string $name,string $code):string{return 'https://wa.me/?text='.rawurlencode(pcLeagueShareText($name).' '.pcLeagueInviteUrl($code));}
