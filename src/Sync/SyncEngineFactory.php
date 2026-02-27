<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Sync;

use Daktela\CrmSync\Adapter\ContactCentreAdapterInterface;
use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Adapter\Daktela\DaktelaAdapter;
use Daktela\CrmSync\Config\YamlConfigLoader;
use Daktela\CrmSync\Crm\Raynet\RaynetClient;
use Daktela\CrmSync\Crm\Raynet\RaynetConfigLoader;
use Daktela\CrmSync\Crm\Raynet\RaynetCrmAdapter;
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
        ?LoggerInterface $logger = null,
        ?string $stateStorePath = null,
    ): self {
        $logger ??= new StderrLogger();

        $syncConfig = (new YamlConfigLoader())->load($configPath);
        $raynetConfig = (new RaynetConfigLoader())->load($configPath);

        $raynetClient = new RaynetClient($raynetConfig, logger: $logger);
        $crmAdapter = new RaynetCrmAdapter($raynetClient, $raynetConfig, $logger);
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
