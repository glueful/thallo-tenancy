<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Reverification;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration;
use PDO;

/** Selects and re-verifies one due global domain batch. */
final class DomainReverificationSweep
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TenantDomainAdministration $domains,
    ) {
    }

    /** @return list<string> domain UUIDs whose primitive threw */
    public function run(ApplicationContext $context): array
    {
        $batch = (int) config($context, 'tenancy.domains.reverification.batch_size', 100);
        $verifiedHours = (int) config(
            $context,
            'tenancy.domains.reverification.recheck_interval_hours',
            12
        );
        $revokedHours = (int) config(
            $context,
            'tenancy.domains.reverification.revoked_recheck_interval_hours',
            24
        );
        $statement = $this->connection->getPDO()->prepare(
            "SELECT d.uuid FROM tenant_domains d JOIN tenants t ON t.uuid = d.tenant_uuid "
            . "WHERE d.verification_token IS NOT NULL "
            . "AND d.verification_status IN ('verified','revoked') "
            . "AND d.status = 'active' AND t.status IN ('active','suspended') "
            . "AND t.deleted_at IS NULL AND (d.last_checked_at IS NULL OR "
            . "d.last_checked_at < now() - make_interval(hours => CAST(CASE "
            . "d.verification_status WHEN 'revoked' THEN ? ELSE ? END AS integer))) "
            . 'ORDER BY d.last_checked_at ASC NULLS FIRST, d.uuid ASC LIMIT ?'
        );
        $statement->execute([$revokedHours, $verifiedHours, $batch]);

        $errors = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $domainUuid) {
            try {
                $this->domains->reverifyDomain($context, (string) $domainUuid);
            } catch (\Throwable) {
                $errors[] = (string) $domainUuid;
            }
        }

        return $errors;
    }
}
