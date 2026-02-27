<?php

/**
 * Shared bootstrap for Raynet example scripts.
 *
 * Uses SyncEngineFactory to wire everything from a single YAML config file.
 * No manual adapter or registry setup needed.
 *
 * Copy this file to your project (e.g. bin/bootstrap.php) and adjust the config path.
 *
 * Expected project layout:
 *
 *   your-project/
 *   ├── composer.json          # requires daktela/daktela-v6-crm-sync
 *   ├── config/
 *   │   ├── sync.yaml          # single config file (copied from config/raynet/)
 *   │   └── mappings/
 *   │       ├── contacts.yaml
 *   │       ├── accounts.yaml
 *   │       └── activities.yaml
 *   ├── var/
 *   │   └── sync-state.json    # auto-created by state store
 *   └── bin/
 *       └── bootstrap.php      # this file
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Daktela\CrmSync\Logging\StderrLogger;
use Daktela\CrmSync\Sync\SyncEngineFactory;

$configPath = getenv('SYNC_CONFIG_PATH') ?: __DIR__ . '/../../config/raynet/sync.yaml';
$stateStorePath = __DIR__ . '/../../var/sync-state.json';

$logger = new StderrLogger();
$factory = SyncEngineFactory::fromYaml($configPath, $logger, $stateStorePath);
$engine = $factory->getEngine();

$engine->testConnections();
