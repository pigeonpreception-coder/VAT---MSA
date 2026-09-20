<?php

namespace App\Domain\Security;

use App\Exceptions\SecurityValidationException;

/**
 * Direct port of lib/domain/security.ts's
 * validateIncidentCreate/validateIncidentAction/validateIncidentClosure.
 * The source's own `messages` array (every failing field at once) is
 * collapsed to the first failure, matching this migration's own
 * established single-error-at-a-time *ValidationException precedent
 * (see App\Exceptions\SecurityValidationException's own doc comment).
 */
class SecurityValidator
{
    private const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{1,99}$/';
    private const SEVERITIES = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /** @return array{schema_version: string, title: string, severity: string, source_event_id: ?string, subject_user_id: ?string, details: string} */
    public static function incidentCreate(array $payload): array
    {
        if (($payload['schema_version'] ?? null) !== '1.0.0') {
            throw new SecurityValidationException('SCHEMA_VERSION_UNSUPPORTED', 'schema_version must be 1.0.0.');
        }
        $title = self::text($payload['title'] ?? null);
        if (mb_strlen($title) < 5 || mb_strlen($title) > 200) {
            throw new SecurityValidationException('TITLE_INVALID', 'title must contain 5 to 200 characters.');
        }
        $severity = mb_strtoupper(self::text($payload['severity'] ?? null));
        if (! in_array($severity, self::SEVERITIES, true)) {
            throw new SecurityValidationException('SEVERITY_INVALID', 'severity must be LOW, MEDIUM, HIGH or CRITICAL.');
        }
        $sourceEventId = self::text($payload['source_event_id'] ?? null) ?: null;
        if ($sourceEventId !== null && ! preg_match(self::ID_PATTERN, $sourceEventId)) {
            throw new SecurityValidationException('SOURCE_EVENT_ID_INVALID', 'source_event_id is invalid.');
        }
        $subjectUserId = self::text($payload['subject_user_id'] ?? null) ?: null;
        if ($subjectUserId !== null && ! preg_match(self::ID_PATTERN, $subjectUserId)) {
            throw new SecurityValidationException('SUBJECT_USER_ID_INVALID', 'subject_user_id is invalid.');
        }
        $details = self::text($payload['details'] ?? null);
        if (mb_strlen($details) < 5 || mb_strlen($details) > 1_000) {
            throw new SecurityValidationException('DETAILS_INVALID', 'details must contain 5 to 1000 characters.');
        }

        return [
            'schema_version' => '1.0.0', 'title' => $title, 'severity' => $severity,
            'source_event_id' => $sourceEventId, 'subject_user_id' => $subjectUserId, 'details' => $details,
        ];
    }

    /** @return array{schema_version: string, notes: string} */
    public static function incidentAction(array $payload): array
    {
        if (($payload['schema_version'] ?? null) !== '1.0.0') {
            throw new SecurityValidationException('SCHEMA_VERSION_UNSUPPORTED', 'schema_version must be 1.0.0.');
        }
        $notes = self::text($payload['notes'] ?? null);
        if (mb_strlen($notes) < 5 || mb_strlen($notes) > 500) {
            throw new SecurityValidationException('NOTES_INVALID', 'notes must contain 5 to 500 characters.');
        }

        return ['schema_version' => '1.0.0', 'notes' => $notes];
    }

    /** @return array{schema_version: string, resolution_notes: string} */
    public static function incidentClosure(array $payload): array
    {
        if (($payload['schema_version'] ?? null) !== '1.0.0') {
            throw new SecurityValidationException('SCHEMA_VERSION_UNSUPPORTED', 'schema_version must be 1.0.0.');
        }
        $resolutionNotes = self::text($payload['resolution_notes'] ?? null);
        if (mb_strlen($resolutionNotes) < 10 || mb_strlen($resolutionNotes) > 1_000) {
            throw new SecurityValidationException('RESOLUTION_NOTES_INVALID', 'resolution_notes must contain 10 to 1000 characters.');
        }

        return ['schema_version' => '1.0.0', 'resolution_notes' => $resolutionNotes];
    }
}
