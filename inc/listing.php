<?php
declare(strict_types=1);
/* New guests join the global table once they have predicted in two matchweeks (Friday to Thursday) or added an email; the viewer always sees their own row. */
// users.listed_at is set by database triggers (bin/migrations/2026-11-02-stage1.sql) the moment a player
// qualifies, so leaderboards no longer recount every guest's weeks on each request.
function pc_listed_sql():string{return "(u.listed_at IS NOT NULL)";}
