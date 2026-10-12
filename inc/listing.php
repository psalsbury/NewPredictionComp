<?php
declare(strict_types=1);
/* New guests join the global table once they have predicted in two matchweeks (Friday to Thursday) or added an email; the viewer always sees their own row. */
function pc_listed_sql():string{return "(u.guest_since IS NULL OR u.email IS NOT NULL OR (SELECT COUNT(DISTINCT CAST((julianday(gf.kickoff_utc)-2451550.5)/7 AS INTEGER)) FROM predictions gp JOIN fixtures gf ON gf.id=gp.fixture_id WHERE gp.user_id=u.id)>=2)";}
