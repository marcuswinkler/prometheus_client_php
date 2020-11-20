<?php

declare(strict_types=1);

namespace Prometheus\Storage;

use Prometheus\MetricFamilySamples;

final class Sqlite implements Adapter
{
    /**
     * @var string
     */
    private $databaseFilename;

    /**
     * @var \PDO|null
     */
    private $pdo;

    public function __construct(string $databaseFilename)
    {
        $this->databaseFilename = $databaseFilename;
    }

    public function collect(): array
    {
        return \array_merge($this->collectHistogram(), $this->collectScalar());
    }

    public function updateHistogram(array $data): void
    {
        $this->insertHistogramMeta($data);

        $this->insertBucket($data['name'], $data['labelValues'], 'sum', $data['value']);

        $matchingBucket = '+Inf';
        foreach ($data['buckets'] as $bucket) {
            if ($data['value'] <= $bucket) {
                $matchingBucket = (string) $bucket;
                break;
            }
        }

        $this->insertBucket($data['name'], $data['labelValues'], $matchingBucket, 1);
    }

    public function updateGauge(array $data): void
    {
        $this->insertMeta($data);
        $this->insertSample($data);
    }

    public function updateCounter(array $data): void
    {
        $this->insertMeta($data);

        if ($data['command'] === Adapter::COMMAND_SET) {
            $data['value'] = 0;
        }

        $this->insertSample($data);
    }

    public function wipeStorage(): void
    {
        $this->pdo = null;

        if ($this->databaseFilename === ':memory:') {
            return;
        }

        if (@unlink($this->databaseFilename)) {
            return;
        }

        if (! @file_exists($this->databaseFilename)) {
            return;
        }

        throw new \RuntimeException('Failed to delete database file: ' . print_r(error_get_last(), true));
    }

    private function collectHistogram(): array
    {
        $result = $this->pdo()->query('SELECT * FROM histogram');

        $histograms = [];

        while ($record = $result->fetch(\PDO::FETCH_ASSOC)) {
            /** @var array<string, string> $record */
            $name = $record['name'];

            $histograms[$name] = [
                'name' => $record['name'],
                'type' => 'histogram',
                'help' => $record['help'],
                'buckets' => json_decode($record['buckets'], true),
                'labelNames' => json_decode($record['label_names'], true),
                'samples' => [],
            ];

            \sort($histograms[$name]['buckets']);
            $histograms[$name]['buckets'][] = '+Inf';
        }

        $result = $this->pdo()->query('SELECT * FROM histogram_bucket');

        $collectedSamples = [];

        while ($record = $result->fetch(\PDO::FETCH_ASSOC)) {
            /** @var array<string, string> $record */
            $name = $record['name'];
            $bucket = $record['bucket'];
            $labelValues = $record['label_values'];

            $collectedSamples[$name][$labelValues][$bucket] = $record['value'];
        }

        $samples = [];

        foreach ($collectedSamples as $name => $bucketsByLabels) {
            $labels = array_keys($bucketsByLabels);
            sort($labels);

            foreach ($labels as $labelValues) {
                $sampledBuckets = $bucketsByLabels[$labelValues];
                $decodedLabelValues = json_decode($labelValues, true);
                $acc = 0;

                foreach ($histograms[$name]['buckets'] as $bucket) {
                    $acc += (float) ($sampledBuckets[(string) $bucket] ?? 0.0);

                    $histograms[$name]['samples'][] = [
                        'name' => $name . '_bucket',
                        'labelNames' => ['le'],
                        'labelValues' => \array_merge($decodedLabelValues, [$bucket]),
                        'value' => $acc,
                    ];
                }

                $histograms[$name]['samples'][] = [
                    'name' => $name . '_count',
                    'labelNames' => [],
                    'labelValues' => $decodedLabelValues,
                    'value' => $acc,
                ];

                $histograms[$name]['samples'][] = [
                    'name' => $name . '_sum',
                    'labelNames' => [],
                    'labelValues' => $decodedLabelValues,
                    'value' => $sampledBuckets['sum'],
                ];
            }

            $samples[] = new MetricFamilySamples($histograms[$name]);
        }

        return $samples;
    }

    private function collectScalar(): array
    {
        $result = $this->pdo()->query('SELECT * FROM metric');

        $data = [];

        while ($record = $result->fetch(\PDO::FETCH_ASSOC)) {
            /** @var array<string, string> $record */
            $type = $record['type'];
            $name = $record['name'];

            $data[$type][$name] = [
                'name' => $record['name'],
                'help' => $record['help'],
                'type' => $record['type'],
                'labelNames' => json_decode($record['label_names'], true),
                'samples' => [],
            ];
        }

        $result = $this->pdo()->query('SELECT * FROM sample');

        while ($record = $result->fetch(\PDO::FETCH_ASSOC)) {
            /** @var array<string, string> $record */
            $type = $record['type'];
            $name = $record['name'];

            $data[$type][$name]['samples'][] = [
                'name' => $record['name'],
                'labelNames' => [],
                'labelValues' => json_decode($record['label_values'], true),
                'value' => $record['value'],
            ];
        }

        $metricFamilySamples = [];

        foreach ($data as $typeValues) {
            foreach ($typeValues as $values) {
                $this->sortSamples($values['samples']);
                $metricFamilySamples[] = new MetricFamilySamples($values);
            }
        }

        return $metricFamilySamples;
    }

    private function sortSamples(array &$samples): void
    {
        \usort($samples, static function (array $a, array $b) {
            return \strcmp(\implode('', $a['labelValues']), \implode('', $b['labelValues']));
        });
    }

    private function insertMeta(array $data): void
    {
        $stmt = $this->pdo()->prepare(
            /** @lang SQLite */
            'INSERT INTO metric(name, type, help, label_names) VALUES(:name, :type, :help, :label_names)'
        );
        $stmt->bindValue(':name', $data['name'], \PDO::PARAM_STR);
        $stmt->bindValue(':type', $data['type'], \PDO::PARAM_STR);
        $stmt->bindValue(':help', $data['help'], \PDO::PARAM_STR);
        $stmt->bindValue(':label_names', json_encode($data['labelNames']), \PDO::PARAM_STR);
        $stmt->execute();
    }

    private function insertHistogramMeta(array $data): void
    {
        $stmt = $this->pdo()->prepare(
            /** @lang SQLite */
            'INSERT INTO histogram(name, help, buckets, label_names) VALUES(:name, :help, :buckets, :label_names)'
        );
        $stmt->bindValue(':name', $data['name'], \PDO::PARAM_STR);
        $stmt->bindValue(':help', $data['help'], \PDO::PARAM_STR);
        $stmt->bindValue(':buckets', json_encode($data['buckets'], \JSON_PRESERVE_ZERO_FRACTION), \PDO::PARAM_STR);
        $stmt->bindValue(':label_names', json_encode($data['labelNames']), \PDO::PARAM_STR);
        $stmt->execute();
    }

    private function insertSample(array $data): void
    {
        if (isset($data['command']) && $data['command'] === Adapter::COMMAND_SET) {
            $insertSql =
                /** @lang SQLite */
                'INSERT INTO sample(name, type, label_values, value) VALUES(:name, :type, :label_values, :value)
                 ON CONFLICT(name, type, label_values) DO UPDATE SET value = excluded.value';
        } else {
            $insertSql =
                /** @lang SQLite */
                'INSERT INTO sample(name, type, label_values, value) VALUES(:name, :type, :label_values, :value)
                 ON CONFLICT(name, type, label_values) DO UPDATE SET value = value + excluded.value';
        }

        $stmt = $this->pdo()->prepare($insertSql);
        $stmt->bindValue(':name', $data['name'], \PDO::PARAM_STR);
        $stmt->bindValue(':type', $data['type'], \PDO::PARAM_STR);
        $stmt->bindValue(':label_values', json_encode($data['labelValues']), \PDO::PARAM_STR);
        $stmt->bindValue(':value', $data['value'], \PDO::PARAM_STR);
        $stmt->execute();
    }

    private function insertBucket(string $name, array $labelValues, string $bucket, float $value): void
    {
        $stmt = $this->pdo()->prepare(
            /** @lang SQLite */
            'INSERT INTO histogram_bucket(name, label_values, bucket, value) VALUES(:name, :label_values, :bucket, :value)
             ON CONFLICT(name, label_values, bucket) DO UPDATE SET value = value + excluded.value
        ');
        $stmt->bindValue(':name', $name, \PDO::PARAM_STR);
        $stmt->bindValue(':label_values', json_encode($labelValues), \PDO::PARAM_STR);
        $stmt->bindValue(':bucket', $bucket, \PDO::PARAM_STR);
        $stmt->bindValue(':value', $value, \PDO::PARAM_STR);
        $stmt->execute();
    }

    private function pdo(): \PDO
    {
        if ($this->pdo === null) {
            $this->initDatabase();
        }

        return $this->pdo;
    }

    private function initDatabase(): void
    {
        $this->pdo = new \PDO('sqlite:' . $this->databaseFilename, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        $this->pdo->exec('PRAGMA journal_mode=WAL;');

        $this->pdo->exec(/** @lang SQLite */
            'CREATE TABLE IF NOT EXISTS metric (
                name TEXT NOT NULL,
                type TEXT NOT NULL,
                help TEXT NOT NULL,
                label_names TEXT NOT NULL,
                UNIQUE (name, type) ON CONFLICT IGNORE
            );'
        );

        $this->pdo->exec(/** @lang SQLite */
            'CREATE TABLE IF NOT EXISTS sample (
                name TEXT NOT NULL,
                type TEXT NOT NULL,
                label_values TEXT NOT NULL,
                value TEXT NOT NULL,
                UNIQUE (name, type, label_values)
            );'
        );

        $this->pdo->exec(/** @lang SQLite */
            'CREATE TABLE IF NOT EXISTS histogram (
                name TEXT NOT NULL,
                help TEXT NOT NULL,
                label_names TEXT NOT NULL,
                buckets TEXT NOT NULL,
                UNIQUE (name) ON CONFLICT IGNORE
            );'
        );

        $this->pdo->exec(/** @lang SQLite */
            'CREATE TABLE IF NOT EXISTS histogram_bucket (
                name TEXT NOT NULL,
                label_values TEXT NOT NULL,
                bucket TEXT NOT NULL,
                value TEXT NOT NULL,
                UNIQUE (name, label_values, bucket)
            );'
        );
    }
}
