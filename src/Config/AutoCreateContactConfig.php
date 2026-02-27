<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Config;

final readonly class AutoCreateContactConfig
{
    /**
     * @param string[] $skipIfExistsFields CC field names for dedup check
     * @param string[] $skipIfEmpty        CC field names — skip creation when all are empty
     */
    public function __construct(
        public string $mappingFile,
        public array $skipIfExistsFields = [],
        public SkipIfExistsMode $skipIfExistsMode = SkipIfExistsMode::All,
        public array $skipIfEmpty = [],
    ) {
    }
}
