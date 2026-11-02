<?php
declare(strict_types=1);
// The one place the scoring rule lives (bin/scoring.py is the Python twin; keep them identical).
// 5 points for the exact score, 3 for the correct result (home win / draw / away win), 0 otherwise.
// A joker (one match per gameweek) doubles that match's points. predictions.points stores the
// competition points, joker included; accuracy stats (exact/correct counts, SIM1 and odds comparisons)
// use the raw 5/3/0 by leaving $joker false.
const PC_POINTS_EXACT=5;
const PC_POINTS_RESULT=3;
function pc_points(int $ph,int $pa,int $h,int $a,bool $joker=false):int{
 $p=$ph===$h&&$pa===$a?PC_POINTS_EXACT:(($ph<=>$pa)===($h<=>$a)?PC_POINTS_RESULT:0);
 return $joker?2*$p:$p;
}
