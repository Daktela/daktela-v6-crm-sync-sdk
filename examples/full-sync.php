<?php

/**
 * Full sync of all entity types.
 *
 * Runs accounts, contacts, and activities in the correct dependency order.
 * This is the recommended approach for scheduled (cron) syncs.
 *
 * Usage: php examples/full-sync.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$engine->testConnections();

$results = $engine->fullSync();

foreach ($results->toArray() as $type => $result) {
    $logger->info($result->getSummary(ucfirst($type)));
}

$logger->info('Full sync complete');
