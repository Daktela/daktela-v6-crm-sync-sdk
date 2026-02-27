<?php

/**
 * Sync individual entity types one at a time (Raynet <-> Daktela).
 *
 * Useful when you need fine-grained control over which entities to sync,
 * or want to sync only a subset (e.g. only contacts).
 *
 * Important: Sync accounts before contacts if contacts reference accounts
 * via relation mappings — the engine needs the account relation map.
 *
 * Usage: php examples/raynet/single-entity-sync.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Daktela\CrmSync\Entity\ActivityType;

// --- 1. Sync accounts first (contacts may reference them) ---
$logger->info('Syncing accounts...');
$accountResult = $engine->syncAccountsBatch();
$logger->info($accountResult->getSummary('Accounts'));

// --- 2. Sync contacts ---
$logger->info('Syncing contacts...');
$contactResult = $engine->syncContactsBatch();
$logger->info($contactResult->getSummary('Contacts'));

// --- 3. Sync activities (only calls and emails) ---
$logger->info('Syncing activities...');
$activityResult = $engine->syncActivitiesBatch([ActivityType::Call, ActivityType::Email]);
$logger->info($activityResult->getSummary('Activities'));

// --- Inspect failures ---
foreach ($activityResult->getFailedRecords() as $record) {
    $logger->error(sprintf(
        'Failed to sync activity %s: %s',
        $record->sourceId ?? '(unknown)',
        $record->errorMessage,
    ));
}
