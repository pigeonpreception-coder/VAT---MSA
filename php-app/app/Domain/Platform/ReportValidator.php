<?php

namespace App\Domain\Platform;

use App\Exceptions\PlatformValidationException;

/**
 * Direct port of lib/domain/platform.ts's validateReportParameters/
 * validateExportCommand/validateExportCancellation -- Module 7's report-run
 * and report-export command payloads (validateRunModelCommand, that same
 * source file's fourth validator, belongs to the still-NOT-STARTED data
 * products/analytics sub-module and is deliberately not ported here).
 *
 * Mirrors App\Domain\Document\DocumentValidator's own established shape:
 * no top-level "must be a JSON object, not a bare array" guard. Laravel's
 * typed `array $payload` parameter, fed from `(array) $request->json()
 * ->all()`, already gives array-shaped input for any genuine JSON object
 * body; the guard only matters for a top-level JSON array/primitive body,
 * an edge case DocumentValidator's own hold()/scanResult() do not guard
 * against either, and PHP's `array_is_list()` would misfire on anyway for
 * a body that happens to decode to an empty array (e.g. `{}`).
 */
class ReportValidator
{
    /** @return array<string, mixed> */
    public static function parameters(array $payload): array
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (mb_strlen((string) $encoded) > 16_384) {
            throw new PlatformValidationException([
                ['code' => 'PARAMETERS_TOO_LARGE', 'path' => '/', 'message' => 'Report parameters must not exceed 16384 characters.'],
            ]);
        }

        return $payload;
    }

    /**
     * Module 7 Phase B RequestExport/ApproveExport (and Phase C
     * PublishReport): all take an empty-but-versioned body, matching the
     * no-fields-needed commands elsewhere in this codebase.
     *
     * @return array{schema_version: string}
     */
    public static function exportCommand(array $payload): array
    {
        if (($payload['schema_version'] ?? null) !== '1.0.0') {
            throw new PlatformValidationException([
                ['code' => 'SCHEMA_VERSION_UNSUPPORTED', 'path' => '/schema_version', 'message' => 'schema_version must be 1.0.0.'],
            ]);
        }

        return ['schema_version' => '1.0.0'];
    }

    /**
     * Module 7 Phase B CancelReport (cancels a still-pending export
     * request).
     *
     * @return array{schema_version: string, reason: string}
     */
    public static function exportCancellation(array $payload): array
    {
        $messages = [];
        if (($payload['schema_version'] ?? null) !== '1.0.0') {
            $messages[] = ['code' => 'SCHEMA_VERSION_UNSUPPORTED', 'path' => '/schema_version', 'message' => 'schema_version must be 1.0.0.'];
        }
        $reason = is_string($payload['reason'] ?? null) ? trim($payload['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            $messages[] = ['code' => 'REASON_INVALID', 'path' => '/reason', 'message' => 'reason must contain 5 to 500 characters.'];
        }
        if (count($messages) > 0) {
            throw new PlatformValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'reason' => $reason];
    }
}
