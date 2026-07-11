<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Purge;

final class PurgeResourceRegistry
{
    /** @var array<string, PurgeHandler> */
    private array $handlers = [];

    public function register(PurgeHandler $handler): void
    {
        if (isset($this->handlers[$handler->id()])) {
            throw new \LogicException("Duplicate purge handler '{$handler->id()}'.");
        }
        $this->handlers[$handler->id()] = $handler;
    }

    /** @return list<PurgeHandler> */
    public function all(): array
    {
        return array_values($this->handlers);
    }

    /** @return list<PurgeHandler> */
    public function ordered(): array
    {
        $ordered = [];
        $state = [];
        $visit = function (string $id) use (&$visit, &$ordered, &$state): void {
            if (($state[$id] ?? null) === 'done') {
                return;
            }
            if (($state[$id] ?? null) === 'visiting') {
                throw new \RuntimeException("Cyclic purge-handler dependency at '{$id}'.");
            }
            if (!isset($this->handlers[$id])) {
                throw new \RuntimeException("Unknown purge-handler dependency '{$id}'.");
            }
            $state[$id] = 'visiting';
            $dependencies = $this->handlers[$id]->dependsOn();
            sort($dependencies, SORT_STRING);
            foreach ($dependencies as $dependency) {
                $visit($dependency);
            }
            $state[$id] = 'done';
            $ordered[] = $this->handlers[$id];
        };

        $ids = array_keys($this->handlers);
        sort($ids, SORT_STRING);
        foreach ($ids as $id) {
            $visit($id);
        }
        return $ordered;
    }
}
