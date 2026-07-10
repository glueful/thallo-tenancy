<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Console;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Tenancy\Enablement\TenancyDiagnostics;

#[AsCommand(name: 'thallo:tenancy:diagnose', description: 'Run read-only tenancy coherence checks.')]
final class TenancyDiagnoseCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->getService(TenancyDiagnostics::class)->report();
        foreach ($report['sections'] as $name => $section) {
            $this->line(strtoupper($section['status']) . ' ' . $name . ': '
                . json_encode($section['detail'], JSON_UNESCAPED_SLASHES));
        }

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
