<?php

declare(strict_types=1);

namespace Test\Prometheus\Sqlite;

use Prometheus\Storage\InMemory;
use Prometheus\Storage\Sqlite;
use Test\Prometheus\AbstractCollectorRegistryTest;

class CollectorRegistryTest extends AbstractCollectorRegistryTest
{
    public function configureAdapter(): void
    {
        $this->adapter = new Sqlite(':memory:');
    }
}
