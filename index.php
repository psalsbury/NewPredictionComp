<?php
declare(strict_types=1);
// Homepage and JSON API. The work is split across inc/ (logic) and templates/ (markup).
const PC_ROOT = __DIR__;
require_once PC_ROOT.'/inc/config.php';
require PC_ROOT.'/inc/home-bootstrap.php';
require PC_ROOT.'/inc/home-auth.php';
require PC_ROOT.'/league-progress.php';
require PC_ROOT.'/inc/home-api.php';
require PC_ROOT.'/inc/home-data.php';
require PC_ROOT.'/templates/home.php';
