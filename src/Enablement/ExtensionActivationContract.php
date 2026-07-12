<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Enablement;

interface ExtensionActivationContract
{
    public function isInstalled(): bool;

    public function isActivated(): bool;

    /** @return array{status:string,blocked:bool,reason:?string,cli:?string,output:string} */
    public function install(): array;

    public function activate(): void;

    public function deactivate(): void;

    /** @return array{applied:list<string>,failed:list<string>} */
    public function migrate(): array;
}
