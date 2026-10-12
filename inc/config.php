<?php
// Paths can be overridden by environment variables so the site can run against a test database.
function pc_db_path(): string { return getenv('PREDICTIONCOMP_DB') ?: '/var/lib/predictioncomp/predictioncomp.sqlite'; }
function pc_cache_path(): string { return getenv('PREDICTIONCOMP_CLUB_CACHE') ?: '/var/cache/predictioncomp/clubs.json'; }
