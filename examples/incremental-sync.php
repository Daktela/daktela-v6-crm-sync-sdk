<?php

/**
 * Incremental sync with state tracking.
 *
 * Only syncs records that changed since the last run. State is persisted
 * in a JSON file so subsequent runs pick up where the previous one left off.
 *
 * This example is self-contained (does not require bootstrap.php) because
 * it needs different engine wiring with a state store.
 *
 * Usage:
 *   php examples/incremental-sync.php                  # incremental sync
 *   php examples/incremental-sync.php --force-full     # ignore state, sync everything
 *   php examples/incremental-sync.php --reset-state    # clear state and exit
 *
 * @see examples/raynet/incremental-sync.php for a ready-to-run Raynet version
 * @see docs/09-production-deployment.md for a production-ready version
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Daktela\CrmSync\Adapter\Daktela\DaktelaAdapter;
use Daktela\CrmSync\Config\YamlConfigLoader;
use Daktela\CrmSync\Logging\StderrLogger;
use Daktela\CrmSync\State\FileSyncStateStore;
use Daktela\CrmSync\Sync\SyncEngine;

// --- Logger ---
$logger = new StderrLogger();

// --- Parse CLI flags ---
$forceFull = in_array('--force-full', $argv, true);
$resetState = in_array('--reset-state', $argv, true);

// --- Load configuration ---
$configPath = getenv('SYNC_CONFIG_PATH') ?: __DIR__ . '/../config/sync.yaml';
$config = (new YamlConfigLoader())->load($configPath);

// --- State store ---
$stateFilePath = __DIR__ . '/../var/sync-state.json';
$stateStore = new FileSyncStateStore($stateFilePath);

if ($resetState) {
    $stateStore->clearAll();
    $logger->info('Sync state has been reset');
    exit(0);
}

// --- Create adapters ---
$ccAdapter = new DaktelaAdapter(
    $config->instanceUrl,
    $config->accessToken,
    $config->database,
    $logger,
);

// Replace this with your CRM adapter implementation
$crmAdapter = null;

if ($crmAdapter === null) {
    throw new RuntimeException(
        'No CRM adapter configured. Edit examples/incremental-sync.php and replace '
        . 'the $crmAdapter placeholder with your CrmAdapterInterface implementation. '
        . 'See docs/04-implementing-crm-adapter.md for guidance.'
    );
}

// --- Create engine with state store for incremental sync ---
$engine = new SyncEngine($ccAdapter, $crmAdapter, $config, $logger, stateStore: $stateStore);

$engine->testConnections();

// --- Run sync ---
if ($forceFull) {
    $logger->info('Starting forced full sync (ignoring state)...');
} else {
    $logger->info('Starting incremental sync...');
}

$results = $engine->fullSync(forceFullSync: $forceFull);

foreach ($results as $type => $result) {
    $logger->info($result->getSummary(ucfirst($type)));
}

$logger->info('Sync complete');
