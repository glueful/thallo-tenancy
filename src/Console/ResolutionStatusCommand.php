<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Console;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Tenancy\Resolution\FullResolutionActivation;

#[AsCommand(name: 'thallo:tenancy:resolution:status', description: 'Show full-resolution status.')]
final class ResolutionStatusCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the raw status as JSON, for scripts');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = $this->getService(FullResolutionActivation::class)->status();
        StatusOutput::write($output, $status, (bool) $input->getOption('json'));

        return $status['step'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
