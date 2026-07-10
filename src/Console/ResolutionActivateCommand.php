<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Console;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Tenancy\Resolution\FullResolutionActivation;

#[AsCommand(name: 'thallo:tenancy:resolution:activate', description: 'Advance full tenant resolution.')]
final class ResolutionActivateCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $activation = $this->getService(FullResolutionActivation::class);
        $before = $activation->status();
        $status = $activation->advance();
        $this->line((string) json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($status['step'] === 'failed') {
            return self::FAILURE;
        }
        if ($status['step'] === 'awaiting_fresh_boot' && $before['step'] !== $status['step']) {
            $this->warning('Re-run this command in a fresh process to finish activation.');
        }

        return self::SUCCESS;
    }
}
