<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Sync;

use Daktela\CrmSync\Adapter\ContactCentreAdapterInterface;
use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Adapter\Daktela\DaktelaAdapter;
use Daktela\CrmSync\Config\YamlConfigLoader;
use Daktela\CrmSync\Logging\StderrLogger;
use Daktela\CrmSync\Mapping\Transformer\TransformerRegistry;
use Daktela\CrmSync\State\FileSyncStateStore;
use Psr\Log\LoggerInterface;

final class SyncEngineFactory
{
    private readonly SyncEngine $engine;

    private readonly ContactCentreAdapterInterface $ccAdapter;

    private readonly CrmAdapterInterface $crmAdapter;

    private function __construct(
        SyncEngine $engine,
        ContactCentreAdapterInterface $ccAdapter,
        CrmAdapterInterface $crmAdapter,
    ) {
        $this->engine = $engine;
        $this->ccAdapter = $ccAdapter;
        $this->crmAdapter = $crmAdapter;
    }

    public static function fromYaml(
        string $configPath,
        CrmAdapterInterface $crmAdapter,
        ?LoggerInterface $logger = null,
        ?string $stateStorePath = null,
    ): self {
        $logger ??= new StderrLogger();

        $syncConfig = (new YamlConfigLoader())->load($configPath);
        $ccAdapter = new DaktelaAdapter($syncConfig->instanceUrl, $syncConfig->accessToken, $syncConfig->database, $logger);

        $registry = TransformerRegistry::withDefaults();
        $stateStore = $stateStorePath !== null ? new FileSyncStateStore($stateStorePath) : null;

        $engine = new SyncEngine($ccAdapter, $crmAdapter, $syncConfig, $logger, $registry, $stateStore);

        return new self($engine, $ccAdapter, $crmAdapter);
    }

    public function getEngine(): SyncEngine
    {
        return $this->engine;
    }

    public function getCcAdapter(): ContactCentreAdapterInterface
    {
        return $this->ccAdapter;
    }

    public function getCrmAdapter(): CrmAdapterInterface
    {
        return $this->crmAdapter;
    }
}
