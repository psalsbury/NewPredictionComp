"""The one place the scoring rule lives for the Python jobs (inc/scoring.php is the PHP twin).
5 points for the exact score, 3 for the correct result, 0 otherwise; a joker doubles the match."""
POINTS_EXACT = 5
POINTS_RESULT = 3


def outcome(h, a):
    return (h > a) - (h < a)


def points(ph, pa, h, a, joker=False):
    p = POINTS_EXACT if (ph == h and pa == a) else (POINTS_RESULT if outcome(ph, pa) == outcome(h, a) else 0)
    return 2 * p if joker else p
