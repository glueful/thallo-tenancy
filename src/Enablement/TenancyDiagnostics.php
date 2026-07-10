<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Enablement;

use Glueful\Database\Connection;
use Thallo\Tenancy\Contracts\StarterCoverageCheck;
use Thallo\Tenancy\Contracts\StaticWriteAudit;
use Thallo\Tenancy\Resolution\ResolutionActivationStep;
use Thallo\Tenancy\Resolution\ResolutionActivationStore;
use Thallo\Tenancy\Retrofit\RetrofitDiagnostics;
use Thallo\Tenancy\System\SystemFlags;

/** Read-only operational diagnosis for the tenancy schema, state tuple, provenance, and write audit. */
final class TenancyDiagnostics
{
    public function __construct(
        private readonly RetrofitDiagnostics $retrofit,
        private readonly SystemFlags $flags,
        private readonly EnablementStore $enablement,
        private readonly ResolutionActivationStore $resolution,
        private readonly Connection $connection,
        private readonly StaticWriteAudit $writeAudit,
        private readonly ?StarterCoverageCheck $coverage = null,
    ) {
    }

    /** @return array{sections:array<string,array{status:string,detail:mixed}>,ok:bool} */
    public function report(): array
    {
        $tables = $this->retrofit->checkTables();
        $agreement = $this->retrofit->checkAgreement();
        $tablesCoherent = true;
        foreach ($tables as $table) {
            $tablesCoherent = $tablesCoherent && $table['ok'];
        }
        $sections = [
            'schema' => $this->section(
                $agreement['ok'] && (
                    $this->flags->schemaState() === 'none'
                    || $tablesCoherent
                ),
                ['tables' => $tables, 'agreement' => $agreement],
            ),
            'state' => $this->stateSection(),
            'enforcement' => $this->enforcementSection(),
            'provenance' => $this->provenanceSection(),
            'collections' => $this->collectionsSection(),
            'static_write_audit' => $this->auditSection(),
        ];

        $ok = true;
        foreach ($sections as $section) {
            $ok = $ok && $section['status'] !== 'fail';
        }

        return [
            'sections' => $sections,
            'ok' => $ok,
        ];
    }

    /** @return array{status:string,detail:mixed} */
    private function stateSection(): array
    {
        $step = $this->enablement->step();
        $enabled = $this->flags->tenancyEnabled();
        $barrier = $this->flags->get('tenancy.retrofit_active') === '1';
        $valid = match ($step) {
            EnablementStep::OFF => !$enabled && $this->flags->schemaState() === 'none',
            EnablementStep::ON => $enabled && !$barrier,
            EnablementStep::DISABLING => $enabled,
            EnablementStep::DISABLED_WIDENED => !$enabled,
            EnablementStep::RELOADING, EnablementStep::FINALIZING => $enabled && $barrier,
            EnablementStep::FAILED => true,
            default => !$enabled || $barrier,
        };
        $resolution = $this->resolution->step();
        $valid = $valid && ($resolution !== ResolutionActivationStep::FULL || $enabled);
        $transitional = in_array(
            $step,
            [EnablementStep::DISABLING, EnablementStep::RELOADING, EnablementStep::FINALIZING],
            true,
        ) || ($step === EnablementStep::DISABLED_WIDENED && $barrier);

        return [
            'status' => $valid ? ($transitional ? 'warn' : 'ok') : 'fail',
            'detail' => [
                'step' => $step->value,
                'enabled' => $enabled,
                'barrier' => $barrier,
                'resolution' => $resolution->value,
            ],
        ];
    }

    /** @return array{status:string,detail:mixed} */
    private function enforcementSection(): array
    {
        if ($this->flags->tenancyEnabled()) {
            return ['status' => 'ok', 'detail' => 'Tenant enforcement is enabled.'];
        }
        if ($this->enablement->step() !== EnablementStep::DISABLED_WIDENED) {
            return ['status' => 'info', 'detail' => 'Tenancy has not been enabled.'];
        }

        $tenantUuid = $this->flags->defaultTenantUuid();
        $payload = Connection::applyInsertHooks('content_types', ['title' => 'diagnostic']);
        $hook = $tenantUuid !== null && ($payload['tenant_uuid'] ?? null) === $tenantUuid;
        $read = $this->connection->table('content_types')->limit(1)->first();
        return $this->section($hook, ['compat_hook' => $hook, 'unscoped_read' => $read !== null]);
    }

    /** @return array{status:string,detail:mixed} */
    private function provenanceSection(): array
    {
        if (!$this->connection->getSchemaBuilder()->hasTable('starter_provenance')) {
            return ['status' => 'info', 'detail' => 'Starter provenance table is not installed.'];
        }
        if ($this->coverage === null) {
            return ['status' => 'info', 'detail' => 'Starter coverage checker unavailable.'];
        }
        $violations = $this->coverage->coverageViolations();
        return ['status' => $violations === [] ? 'ok' : 'warn', 'detail' => $violations];
    }

    /** @return array{status:string,detail:mixed} */
    private function collectionsSection(): array
    {
        if (!$this->connection->getSchemaBuilder()->hasTable('collection_definitions')) {
            return ['status' => 'ok', 'detail' => 'Collections pack is absent.'];
        }
        $count = $this->connection->table('collection_definitions')->count();
        return [
            'status' => $count === 0 ? 'ok' : ($this->flags->tenancyEnabled() ? 'warn' : 'info'),
            'detail' => ['definitions' => $count, 'tenant_support' => 'deferred'],
        ];
    }

    /** @return array{status:string,detail:mixed} */
    private function auditSection(): array
    {
        $audit = $this->writeAudit->run();
        if (!$audit['available']) {
            return ['status' => 'info', 'detail' => 'Static audit unavailable in this deployment.'];
        }
        $ok = $audit['unclassified'] === []
            && $audit['bucketViolations'] === []
            && $audit['wrapperMismatches'] === [];
        return $this->section($ok, $audit);
    }

    /** @return array{status:string,detail:mixed} */
    private function section(bool $ok, mixed $detail): array
    {
        return ['status' => $ok ? 'ok' : 'fail', 'detail' => $detail];
    }
}
