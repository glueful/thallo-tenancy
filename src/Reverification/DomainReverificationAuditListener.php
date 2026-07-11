<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Reverification;

use Glueful\Extensions\Tenancy\Events\DomainReverificationFailed;
use Glueful\Extensions\Tenancy\Events\DomainReverified;
use Glueful\Extensions\Tenancy\Events\DomainRevoked;
use Thallo\Contracts\Tenancy\TenancyLifecycleAudit;

/** Projects engine ownership-proof events into best-effort system audit entries. */
final class DomainReverificationAuditListener
{
    public function __construct(private readonly ?TenancyLifecycleAudit $audit = null)
    {
    }

    public function __invoke(object $event): void
    {
        if ($this->audit === null) {
            return;
        }
        $action = match (true) {
            $event instanceof DomainRevoked => 'domain.revoked',
            $event instanceof DomainReverified => 'domain.reverified',
            $event instanceof DomainReverificationFailed => 'domain.reverification_failed',
            default => null,
        };
        if ($action === null) {
            return;
        }

        try {
            $this->audit->record($action, null, $event->tenantUuid, [
                'domain_uuid' => $event->domainUuid,
                'host' => $event->host,
                'outcome' => $event->outcome,
                'consecutive_failures' => $event->consecutiveFailures,
                'verification_status' => $event->verificationStatus,
            ]);
        } catch (\Throwable) {
            // Audit is deliberately best-effort; the ownership transition is authoritative.
        }
    }
}
