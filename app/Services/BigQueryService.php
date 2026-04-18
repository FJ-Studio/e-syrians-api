<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;
use Illuminate\Support\Facades\Log;
use Google\Cloud\BigQuery\BigQueryClient;

class BigQueryService
{
    private ?BigQueryClient $client = null;

    /**
     * Check if BigQuery integration is enabled.
     */
    public function isEnabled(): bool
    {
        return (bool) config('services.bigquery.enabled');
    }

    /**
     * Insert a row into a BigQuery table.
     */
    public function insert(string $table, array $data): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        try {
            $dataset = $this->getClient()->dataset(config('services.bigquery.dataset'));
            $bqTable = $dataset->table(config("services.bigquery.tables.{$table}", $table));
            $insertResponse = $bqTable->insertRows([['data' => $data]]);

            if (! $insertResponse->isSuccessful()) {
                foreach ($insertResponse->failedRows() as $row) {
                    foreach ($row['errors'] as $error) {
                        Log::warning('BigQuery insert error', [
                            'table' => $table,
                            'reason' => $error['reason'] ?? 'unknown',
                            'message' => $error['message'] ?? '',
                        ]);
                    }
                }
            }
        } catch (Throwable $e) {
            Log::warning('BigQuery insert failed', [
                'table' => $table,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Insert multiple rows into a BigQuery table.
     */
    public function insertBatch(string $table, array $rows): void
    {
        if (! $this->isEnabled() || empty($rows)) {
            return;
        }

        try {
            $dataset = $this->getClient()->dataset(config('services.bigquery.dataset'));
            $bqTable = $dataset->table(config("services.bigquery.tables.{$table}", $table));
            $insertRows = array_map(fn (array $row) => ['data' => $row], $rows);
            $insertResponse = $bqTable->insertRows($insertRows);

            if (! $insertResponse->isSuccessful()) {
                Log::warning('BigQuery batch insert had failures', [
                    'table' => $table,
                    'failed_count' => count($insertResponse->failedRows()),
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('BigQuery batch insert failed', [
                'table' => $table,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function getClient(): BigQueryClient
    {
        if ($this->client === null) {
            $options = [
                'projectId' => config('services.bigquery.project_id'),
            ];

            $credentials = config('services.bigquery.credentials');
            if ($credentials) {
                $decoded = json_decode($credentials, true);
                if (is_array($decoded)) {
                    // JSON string stored directly in .env
                    $options['keyFile'] = $decoded;
                } else {
                    // File path
                    $options['keyFilePath'] = $credentials;
                }
            }

            $this->client = new BigQueryClient($options);
        }

        return $this->client;
    }
}
