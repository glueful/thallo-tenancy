<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Enablement;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Extensions\EnabledProviders;
use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\ExtensionResolver;
use Glueful\Extensions\ExtensionStateWriter;
use Glueful\Extensions\Install\ExtensionInstaller;
use Glueful\Extensions\Install\HostNotWritableException;
use Glueful\Extensions\Install\InstallDisabledException;
use Glueful\Extensions\Install\PackageNotAllowedException;
use Glueful\Extensions\PackageManifest;
use Glueful\Support\Version;

final class ExtensionActivation implements ExtensionActivationContract
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
        if ($this->isActivated()) {
            return;
        }

        $candidates = $this->candidates();
        if (!isset($candidates[self::PACKAGE])) {
            throw new \RuntimeException('glueful/tenancy is not installed.');
        }

        $enabled = [...EnabledProviders::from($this->context), self::PROVIDER];
        $resolution = (new ExtensionResolver())->resolve($candidates, $enabled, Version::VERSION);
        if ($resolution->hasErrors()) {
            throw new \RuntimeException(implode('; ', array_map(
                static fn ($error): string => $error->message,
                $resolution->errors,
            )));
        }

        (new ExtensionStateWriter())->enable(config_path($this->context, 'extensions.php'), self::PROVIDER);
        app($this->context, ExtensionManager::class)->writeCacheNow($resolution->providers);
    }

    /** @return array{applied:list<string>,failed:list<string>} */
    public function migrate(): array
    {
        return app($this->context, MigrationManager::class)->migrate();
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
