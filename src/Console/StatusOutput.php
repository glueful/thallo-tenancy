<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Console;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The tenancy status commands' two outputs: a table a person reads, or with `--json` the raw
 * status for a script.
 */
final class StatusOutput
{
    /** @param array<string,mixed> $status */
    public static function write(OutputInterface $output, array $status, bool $json): void
    {
        if ($json) {
            $output->writeln((string) json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $table = new Table($output);
        foreach ($status as $key => $value) {
            $table->addRow([str_replace('_', ' ', (string) $key), self::value($value)]);
        }
        $table->setStyle('compact')->render();
    }

    private static function value(mixed $value): string
    {
        return match (true) {
            $value === null, $value === [], $value === '' => '—',
            is_bool($value) => $value ? 'yes' : 'no',
            is_scalar($value) => (string) $value,
            is_array($value) && array_is_list($value) => implode("\n", array_map(self::value(...), $value)),
            is_array($value) => implode("\n", array_map(
                static fn(string|int $k, mixed $v): string => $k . ': ' . self::value($v),
                array_keys($value),
                $value,
            )),
            default => (string) json_encode($value),
        };
    }
}
