<?php

namespace App\Domain\Platform;

use App\Exceptions\PlatformValidationException;

/**
 * Direct port of lib/domain/platform.ts's validateOfflineBatch -- Module
 * 22's offline-invoicing sync-batch envelope, the domain-pure validator
 * App\Services\Platform\OfflineSyncService::receive() calls before ever
 * touching the database. That same source file's other exported
 * validators (safeFileName/validateDocumentScanResult/validateDocumentHold,
 * plus validateReportParameters) already live in
 * App\Domain\Document\DocumentValidator and a future reports slice; this
 * class is scoped to exactly the offline-sync envelope, matching this
 * migration's "namespace domain validators by module" convention.
 */
class OfflineSyncValidator
{
    private const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{1,99}$/';

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    private const HASH_PATTERN = '/^[a-f0-9]{64}$/i';

    private const ISO_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{3})?Z$/';

    /**
     * @return array{device_id: string, batch_id: string, sequence_from: int, sequence_to: int, created_at: string, previous_batch_hash: ?string, documents: list<mixed>, device_signature: string}
     */
    public static function batch(mixed $payload): array
    {
        if (! is_array($payload) || array_is_list($payload)) {
            throw new PlatformValidationException([
                ['code' => 'DOCUMENT_INVALID', 'path' => '/', 'message' => 'The request body must be an offline batch object.'],
            ]);
        }
        $messages = [];

        $deviceId = self::text($payload['device_id'] ?? null);
        if (! preg_match(self::ID_PATTERN, $deviceId)) {
            $messages[] = ['code' => 'DEVICE_ID_INVALID', 'path' => '/device_id', 'message' => 'Device id is invalid.'];
        }

        $batchId = self::text($payload['batch_id'] ?? null);
        if (! preg_match(self::UUID_PATTERN, $batchId)) {
            $messages[] = ['code' => 'BATCH_ID_INVALID', 'path' => '/batch_id', 'message' => 'Batch id must be a UUID.'];
        }

        $sequenceFrom = self::safeInt($payload['sequence_from'] ?? null);
        $sequenceTo = self::safeInt($payload['sequence_to'] ?? null);
        if ($sequenceFrom === null || $sequenceFrom < 1) {
            $messages[] = ['code' => 'SEQUENCE_INVALID', 'path' => '/sequence_from', 'message' => 'sequence_from must be a positive safe integer.'];
        }
        if ($sequenceTo === null || $sequenceFrom === null || $sequenceTo < $sequenceFrom) {
            $messages[] = ['code' => 'SEQUENCE_INVALID', 'path' => '/sequence_to', 'message' => 'sequence_to must be greater than or equal to sequence_from.'];
        }

        $createdAt = self::text($payload['created_at'] ?? null);
        if (! preg_match(self::ISO_PATTERN, $createdAt) || strtotime($createdAt) === false) {
            $messages[] = ['code' => 'TIMESTAMP_INVALID', 'path' => '/created_at', 'message' => 'created_at must be an ISO UTC timestamp.'];
        }

        $previousHashRaw = self::text($payload['previous_batch_hash'] ?? null);
        $previousHash = $previousHashRaw !== '' ? $previousHashRaw : null;
        if ($previousHash !== null && ! preg_match(self::HASH_PATTERN, $previousHash)) {
            $messages[] = ['code' => 'HASH_INVALID', 'path' => '/previous_batch_hash', 'message' => 'Previous batch hash must contain 64 hexadecimal characters.'];
        }

        $documentsRaw = $payload['documents'] ?? null;
        $documents = is_array($documentsRaw) && array_is_list($documentsRaw) ? $documentsRaw : [];
        if (count($documents) < 1 || count($documents) > 1_000) {
            $messages[] = ['code' => 'DOCUMENT_COUNT_INVALID', 'path' => '/documents', 'message' => 'An offline batch must contain 1 to 1000 documents.'];
        }
        if ($sequenceFrom !== null && $sequenceTo !== null && count($documents) !== $sequenceTo - $sequenceFrom + 1) {
            $messages[] = ['code' => 'SEQUENCE_DOCUMENT_MISMATCH', 'path' => '/documents', 'message' => 'Document count must match the inclusive sequence range.'];
        }

        $signature = self::text($payload['device_signature'] ?? null);
        if (mb_strlen($signature) < 32 || mb_strlen($signature) > 8_192) {
            $messages[] = ['code' => 'SIGNATURE_INVALID', 'path' => '/device_signature', 'message' => 'Device signature length is invalid.'];
        }

        if (count($messages) > 0) {
            throw new PlatformValidationException($messages);
        }

        return [
            'device_id' => $deviceId, 'batch_id' => $batchId, 'sequence_from' => $sequenceFrom, 'sequence_to' => $sequenceTo,
            'created_at' => $createdAt, 'previous_batch_hash' => $previousHash, 'documents' => array_values($documents),
            'device_signature' => $signature,
        ];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * Mirrors JS's `Number(value)` followed by `Number.isSafeInteger` --
     * null (the PHP stand-in for NaN/"not a safe integer") for anything
     * that isn't a genuine whole number within +/-2^53-1, accepting a
     * numeric JSON string the same way `Number("5")` would.
     */
    private static function safeInt(mixed $value): ?int
    {
        if (is_string($value)) {
            if (! is_numeric($value)) {
                return null;
            }
            $value += 0;
        }
        if (is_int($value)) {
            return abs($value) <= 9_007_199_254_740_991 ? $value : null;
        }
        if (is_float($value) && ! is_infinite($value) && ! is_nan($value) && floor($value) === $value && abs($value) <= 9_007_199_254_740_991) {
            return (int) $value;
        }

        return null;
    }
}
