<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Mapping;

/**
 * Describes a cross-entity reference that needs resolution during sync.
 *
 * Example: A CRM contact has company_id="crm-acc-1" which needs to resolve
 * to the Daktela account name "acme". The relation config tells the mapper:
 *
 *   entity: account           — the related entity type
 *   resolve_from: id          — match the CRM account by this CRM field
 *   resolve_to: name          — use this Daktela field as the resolved value
 *
 * The SyncEngine builds a resolution map (CRM account.id → Daktela account.name)
 * during account sync, then uses it when syncing contacts.
 */
final readonly class RelationConfig
{
    public function __construct(
        /** The related entity type (e.g., "account") */
        public string $entity,
        /** The field in the source entity to match against (CRM side) */
        public string $resolveFrom,
        /** The field in the target entity to use as the resolved value (CC side) */
        public string $resolveTo,
    ) {
    }
}
