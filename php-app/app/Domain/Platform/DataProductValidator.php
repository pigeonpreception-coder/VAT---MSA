<?php

namespace App\Domain\Platform;

use App\Exceptions\PlatformValidationException;

/**
 * Direct port of lib/domain/platform.ts's validateRunModelCommand/
 * validatePublishDataProductCommand -- Module 7 Phase D's data-products/
 * analytics command payloads. `report_run_id`/`model_run_id` are checked
 * against the same general-purpose `ID_PATTERN` as
 * App\Domain\Platform\OfflineSyncValidator's own `device_id` (not a strict
 * UUID pattern) -- the source's own seed ids for these tables are
 * human-readable slugs ("report-def-vat", "dp-vat-trends"), not always
 * real UUIDs, matching db/runtime.ts exactly.
 */
class DataProductValidator
{
    private const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{1,99}$/';

    /** @return array{schema_version: string, report_run_id: string} */
    public static function runModel(array $payload): array
    {
        $messages = [];
        if (($payload['schema_version'] ?? null) !== '1.0.0') {
            $messages[] = ['code' => 'SCHEMA_VERSION_UNSUPPORTED', 'path' => '/schema_version', 'message' => 'schema_version must be 1.0.0.'];
        }
        $reportRunId = is_string($payload['report_run_id'] ?? null) ? trim($payload['report_run_id']) : '';
        if (! preg_match(self::ID_PATTERN, $reportRunId)) {
            $messages[] = ['code' => 'REPORT_RUN_ID_INVALID', 'path' => '/report_run_id', 'message' => 'report_run_id is invalid.'];
        }
        if (count($messages) > 0) {
            throw new PlatformValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'report_run_id' => $reportRunId];
    }

    /** @return array{schema_version: string, model_run_id: string} */
    public static function publishDataProduct(array $payload): array
    {
        $messages = [];
        if (($payload['schema_version'] ?? null) !== '1.0.0') {
            $messages[] = ['code' => 'SCHEMA_VERSION_UNSUPPORTED', 'path' => '/schema_version', 'message' => 'schema_version must be 1.0.0.'];
        }
        $modelRunId = is_string($payload['model_run_id'] ?? null) ? trim($payload['model_run_id']) : '';
        if (! preg_match(self::ID_PATTERN, $modelRunId)) {
            $messages[] = ['code' => 'MODEL_RUN_ID_INVALID', 'path' => '/model_run_id', 'message' => 'model_run_id is invalid.'];
        }
        if (count($messages) > 0) {
            throw new PlatformValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'model_run_id' => $modelRunId];
    }
}
