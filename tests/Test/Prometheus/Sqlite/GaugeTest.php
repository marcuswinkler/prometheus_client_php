<?php

declare(strict_types=1);

namespace Test\Prometheus\Sqlite;

use Prometheus\Storage\InMemory;
use Prometheus\Storage\Sqlite;
use Test\Prometheus\AbstractGaugeTest;

/**
 * See https://prometheus.io/docs/instrumenting/exposition_formats/
 */
class GaugeTest extends AbstractGaugeTest
{

    public function configureAdapter(): void
    {
        $this->adapter = new Sqlite(':memory:');
    }
}
