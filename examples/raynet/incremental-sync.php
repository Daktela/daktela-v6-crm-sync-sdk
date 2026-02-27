<?php

/**
 * Incremental sync with state tracking (Raynet <-> Daktela).
 *
 * Only syncs records that changed since the last run. State is persisted
 * in a JSON file so subsequent runs pick up where the previous one left off.
 *
 * Usage:
 *   php examples/raynet/incremental-sync.php                  # incremental sync
 *   php examples/raynet/incremental-sync.php --force-full     # ignore state, sync everything
 *   php examples/raynet/incremental-sync.php --reset-state    # clear state and exit
 *
 * @see docs/09-production-deployment.md for a production-ready version
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// --- Parse CLI flags ---
$forceFull = in_array('--force-full', $argv, true);
$resetState = in_array('--reset-state', $argv, true);

if ($resetState) {
    $engine->resetState();
    $logger->info('Sync state has been reset');
    exit(0);
}

// --- Run sync ---
if ($forceFull) {
    $logger->info('Starting forced full sync (ignoring state)...');
} else {
    $logger->info('Starting incremental sync...');
}

$results = $engine->fullSync(forceFullSync: $forceFull);

foreach ($results->toArray() as $type => $result) {
    $logger->info($result->getSummary(ucfirst($type)));
}

$logger->info('Sync complete');
