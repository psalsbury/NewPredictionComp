# Responsive layout review — 16 October 2026

Deployed assets/responsive.css, loaded after the shared theme on home, public and information templates. Player pages inherit it from home. League tables are capped at 680 CSS pixels, with aligned rank/player/points columns, larger player controls, alternating row backgrounds and wrapping month tabs. Shared controls have touch-friendly sizes, 16px form fields, constrained scrolling dialogs, safer grid columns and whole-word club names.

Browser checks: 14 routes at 390, 834, 1024 and 1440 CSS pixels (56 layouts), plus predictions, overall league, Premier League table and clubs at 320, 375, 768 and 1180 pixels (16 layouts). No page-level horizontal overflow in the 72 checked layouts. Verified aligned table header/points, league pagination, mobile Football menu bounds, tablet score picker and scrolling score picker at 320x568. PHP lint passed for all three edited templates. Temporary iframe test pages removed after verification.

Routes: home, overall league, My leagues, My results, account, Premier League table, fixtures, results, clubs, Manchester United club page, fixture preview, prediction guides, scoring instructions and bot directory. Public/session-free views tested; My results and account show sign-in without an authenticated session. Signed-in account/history states were reviewed in the shared source but were not fully exercised. These are Chromium responsive browser checks, not certification on physical iPads/Safari or every OS/browser.

Also persisted the earlier home-late.css correction: fixture grid tracks and named areas remain consistent across the 720–900px interval, and touch score pickers hide the duplicate desktop Save button. No predictions or competition data were changed.
