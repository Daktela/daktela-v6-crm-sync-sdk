<?php

/**
 * Shared bootstrap for example scripts.
 *
 * Creates the Daktela adapter, logger, and sync engine — everything except the
 * CRM adapter, which you must provide by replacing the placeholder below.
 *
 * Usage:
 *   1. Copy config/example/ to config/ and adjust values
 *   2. Replace the $crmAdapter placeholder with your own implementation
 *   3. Run any example: php examples/full-sync.php
 *
 * @see examples/raynet/ for ready-to-run Raynet examples using SyncEngineFactory
 * @see docs/04-implementing-crm-adapter.md for how to build a CRM adapter
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Daktela\CrmSync\Adapter\Daktela\DaktelaAdapter;
use Daktela\CrmSync\Config\YamlConfigLoader;
use Daktela\CrmSync\Logging\StderrLogger;
use Daktela\CrmSync\Sync\SyncEngine;

// --- Logger (replace with Monolog or your PSR-3 logger in production) ---
$logger = new StderrLogger();

// --- Load configuration ---
$configPath = getenv('SYNC_CONFIG_PATH') ?: __DIR__ . '/../config/sync.yaml';
$config = (new YamlConfigLoader())->load($configPath);

// --- Create Daktela Contact Centre adapter ---
$ccAdapter = new DaktelaAdapter(
    $config->instanceUrl,
    $config->accessToken,
    $config->database,
    $logger,
);

// --- CRM adapter placeholder ---
// Replace this with your CRM adapter implementation, e.g.:
//   $crmAdapter = new YourCrmAdapter(/* ... */);
$crmAdapter = null;

if ($crmAdapter === null) {
    throw new RuntimeException(
        'No CRM adapter configured. Edit examples/bootstrap.php and replace the '
        . '$crmAdapter placeholder with your CrmAdapterInterface implementation. '
        . 'See docs/04-implementing-crm-adapter.md for guidance.'
    );
}

// --- Create the sync engine ---
$engine = new SyncEngine($ccAdapter, $crmAdapter, $config, $logger);
