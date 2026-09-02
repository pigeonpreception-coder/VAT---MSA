<?php

namespace App\Domain\Document;

use App\Exceptions\PlatformValidationException;

/**
 * Direct port of lib/domain/platform.ts's safeFileName/
 * validateDocumentScanResult -- the genuinely domain-pure functions Module
 * 22 (Documents & Records) needs for the minimal Upload -> Quarantine ->
 * ScanDecision chain this slice pulls forward as the real prerequisite
 * for closing Phase 11's own `DOCUMENT`-sourced evidence citation gap.
 * `uploadDocument`'s own owner_domain/owner_resource_id/classification
 * checks are deliberately NOT here -- the source validates those inline
 * in the repository function itself (a single-message
 * `PlatformResourceError`, not this file's list-shaped
 * `PlatformValidationError`), so `App\Services\Document\DocumentService::
 * upload()` mirrors that inline placement exactly rather than inventing a
 * normalizer the source doesn't have.
 */
class DocumentValidator
{
    private const SCAN_OUTCOMES = ['CLEAN', 'INFECTED'];

    /** @return string a safe leaf filename: no path segments, no control/exotic characters, capped at 180 characters, never empty. */
    public static function safeFileName(string $value): string
    {
        $normalized = str_replace('\\', '/', $value);
        $segments = explode('/', $normalized);
        $leaf = trim((string) end($segments));
        $safe = mb_substr((string) preg_replace('/[^A-Za-z0-9._ -]/', '_', $leaf), 0, 180);

        return $safe !== '' ? $safe : 'evidence';
    }

    /** @return array{schema_version: string, outcome: string, notes: ?string} */
    public static function scanResult(array $input): array
    {
        $messages = [];
        if (($input['schema_version'] ?? null) !== '1.0.0') {
            $messages[] = ['code' => 'SCHEMA_VERSION_UNSUPPORTED', 'path' => '/schema_version', 'message' => 'schema_version must be 1.0.0.'];
        }
        $outcome = mb_strtoupper(trim((string) ($input['outcome'] ?? '')));
        if (! in_array($outcome, self::SCAN_OUTCOMES, true)) {
            $messages[] = ['code' => 'OUTCOME_INVALID', 'path' => '/outcome', 'message' => 'outcome must be CLEAN or INFECTED.'];
        }
        $notesRaw = trim((string) ($input['notes'] ?? ''));
        if (mb_strlen($notesRaw) > 1_000) {
            $messages[] = ['code' => 'NOTES_TOO_LONG', 'path' => '/notes', 'message' => 'notes must not exceed 1000 characters.'];
        }
        if (count($messages) > 0) {
            throw new PlatformValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'outcome' => $outcome, 'notes' => $notesRaw !== '' ? $notesRaw : null];
    }
}
