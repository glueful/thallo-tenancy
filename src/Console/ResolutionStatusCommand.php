<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Console;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Tenancy\Resolution\FullResolutionActivation;

#[AsCommand(name: 'thallo:tenancy:resolution:status', description: 'Show full-resolution status.')]
final class ResolutionStatusCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = $this->getService(FullResolutionActivation::class)->status();
        $this->line((string) json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $status['step'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
