<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Config;

use Daktela\CrmSync\Sync\SyncDirection;

/**
 * One entry under sync.custom_entities[]. Pulls records from an adapter-defined CRM-side object
 * ({@see $source}) and upserts them into a Daktela platform entity ({@see $target}).
 *
 * Both `source` and `target` are free-form strings:
 *  - `source` is interpreted by the CRM adapter (e.g. SObject name for Salesforce, resource path
 *    for Pipedrive). The adapter throws NotSupportedException if it can't reach that source.
 *  - `target` is interpreted by SyncEngine when wrapping records and choosing the upsert path.
 *    It is restricted to whatever ContactCentreAdapterInterface can store — currently 'contact'
 *    and 'account' (extending BatchSync::wrapForTarget()/buildUpsertFn() adds more, e.g. 'activity'
 *    once an upsertActivity method is added on the CC side).
 *
 * The {@see $name} is a stable identifier used as the slot key for the per-entry mapping file
 * (mappings_json[$name] on the platform side) and for state-store keys (custom:$name).
 */
final readonly class CustomEntitySyncConfig
{
    /** Convenience constants — common values, NOT a closed set. */
    public const TARGET_CONTACT = 'contact';
    public const TARGET_ACCOUNT = 'account';

    public function __construct(
        public string $name,
        public bool $enabled,
        public SyncDirection $direction,
        public string $source,
        public string $target,
        public string $mappingFile,
    ) {
    }
}
