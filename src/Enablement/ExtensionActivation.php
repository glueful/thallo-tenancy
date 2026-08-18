<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Enablement;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\EnabledProviders;
use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\ExtensionResolver;
use Glueful\Extensions\ExtensionStateWriter;
use Glueful\Extensions\Install\ExtensionInstaller;
use Glueful\Extensions\Install\HostNotWritableException;
use Glueful\Extensions\Install\InstallDisabledException;
use Glueful\Extensions\Install\PackageNotAllowedException;
use Glueful\Extensions\PackageManifest;
use Glueful\Extensions\Schema\ExtensionOperation;
use Glueful\Extensions\Schema\ExtensionSchemaExecutor;
use Glueful\Support\Version;

class ExtensionActivation implements ExtensionActivationContract
{
    public const PACKAGE = 'glueful/tenancy';
    public const PROVIDER = 'Glueful\\Extensions\\Tenancy\\TenancyServiceProvider';

    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function isInstalled(): bool
    {
        return isset($this->candidates()[self::PACKAGE]);
    }

    public function isActivated(): bool
    {
        return in_array(self::PROVIDER, EnabledProviders::from($this->context), true);
    }

    /** @return array{status:string,blocked:bool,reason:?string,cli:?string,output:string} */
    public function install(): array
    {
        if ($this->isInstalled()) {
            return $this->installResult('installed', false, null, null, '');
        }

        try {
            $result = app($this->context, ExtensionInstaller::class)->install(self::PACKAGE);
        } catch (InstallDisabledException | HostNotWritableException | PackageNotAllowedException $exception) {
            return $this->installResult(
                'blocked',
                true,
                $exception->getMessage(),
                'composer require ' . self::PACKAGE,
                '',
            );
        }

        if ($result['status'] !== 'installed') {
            return $this->installResult(
                'failed',
                false,
                $result['error'] ?? 'composer require failed',
                null,
                $result['output'],
            );
        }

        return $this->installResult('installed', false, null, null, $result['output']);
    }

    public function activate(): void
    {
        $candidates = $this->candidates();
        if (!isset($candidates[self::PACKAGE])) {
            throw new \RuntimeException('glueful/tenancy is not installed.');
        }

        $current = EnabledProviders::from($this->context);
        $enabled = in_array(self::PROVIDER, $current, true)
            ? $current
            : [...$current, self::PROVIDER];
        $resolution = (new ExtensionResolver())->resolve($candidates, $enabled, Version::VERSION);
        if ($resolution->hasErrors()) {
            throw new \RuntimeException(implode('; ', array_map(
                static fn ($error): string => $error->message,
                $resolution->errors,
            )));
        }

        if (!in_array(self::PROVIDER, $current, true)) {
            (new ExtensionStateWriter())->enable(config_path($this->context, 'extensions.php'), self::PROVIDER);
        }
        // No-arg on purpose: the cache must carry the FULL provider list (app modules from
        // serviceproviders.php + extensions). Passing $resolution->providers (extensions only)
        // would persist a cache without the always-on thallo modules — a production boot with
        // no CMS organs. The resolver above remains the validation gate; the cache write
        // re-resolves the combined, ordered list itself. The clear makes that re-resolve see
        // the JUST-WRITTEN extensions.php instead of the enabled list cached before the write
        // (framework 1.72.0's writeCacheNow() resolves through the context config cache).
        $this->context->clearConfigCache();
        app($this->context, ExtensionManager::class)->writeCacheNow();
    }

    public function deactivate(): void
    {
        $current = EnabledProviders::from($this->context);
        $enabled = array_values(array_diff($current, [self::PROVIDER]));
        $resolution = (new ExtensionResolver())->resolve($this->candidates(), $enabled, Version::VERSION);
        if ($resolution->hasErrors()) {
            throw new \RuntimeException(implode('; ', array_map(
                static fn ($error): string => $error->message,
                $resolution->errors,
            )));
        }

        if (in_array(self::PROVIDER, $current, true)) {
            (new ExtensionStateWriter())->disable(config_path($this->context, 'extensions.php'), self::PROVIDER);
        }
        // No-arg + cache clear for the same reasons as activate(): the cache must include the
        // app modules, resolved from the just-written (not pre-write cached) enabled list.
        $this->context->clearConfigCache();
        app($this->context, ExtensionManager::class)->writeCacheNow();
    }

    /**
     * The protected migration lane (schema program Task 8): the shared executor migrates any
     * still-pending CORE prerequisites (which covers the glueful/thallo-tenancy descriptor
     * without a name-derived source list here) plus glueful/tenancy's own sources, under the
     * same bootstrap/lock/readiness custody as every enable — recorded as a core-owned
     * protected_migrate operation. It NEVER touches provider state; the later protected
     * activation step keeps sole custody of that write. Failures surface as the contract's
     * failed list, carrying the basename, the operation id/status, and the error, so the
     * enablement state machine's recordFailure path stays exactly as it is. A held lock or a
     * missing bootstrap throws inside the executor within its bounded wait and lands here as a
     * step failure, never a hang.
     *
     * @return array{applied:list<string>,failed:list<string>}
     */
    public function migrate(): array
    {
        try {
            $operation = $this->executor()->migrateProtected(self::PACKAGE, 'tenancy-enablement');
        } catch (\Throwable $exception) {
            return ['applied' => [], 'failed' => [$exception->getMessage()]];
        }

        if ($operation->status !== ExtensionOperation::STATUS_SUCCEEDED) {
            $detail = ($operation->failedMigration ?? 'migration')
                . " (operation #{$operation->id}: {$operation->status})"
                . ($operation->error !== null ? ' — ' . $operation->error : '');
            return ['applied' => [], 'failed' => [$detail]];
        }

        return ['applied' => [], 'failed' => []];
    }

    /** Overridable seam: the shared schema executor from the app container. */
    protected function executor(): ExtensionSchemaExecutor
    {
        return app($this->context, ExtensionSchemaExecutor::class);
    }

    /** @return array<string,\Glueful\Extensions\ExtensionCandidate> */
    private function candidates(): array
    {
        return (new PackageManifest($this->context))->getCandidates();
    }

    /** @return array{status:string,blocked:bool,reason:?string,cli:?string,output:string} */
    private function installResult(
        string $status,
        bool $blocked,
        ?string $reason,
        ?string $cli,
        string $output,
    ): array {
        return compact('status', 'blocked', 'reason', 'cli', 'output');
    }
}
