<?php

namespace App\Domain\Navigation;

use App\Exceptions\LicensingValidationException;

/**
 * Direct port of lib/domain/control-plane.ts's
 * normalizeNavigationChildrenQuery/normalizeNavigationPreference -- Phase
 * 12's portal-navigation slice. Reuses `App\Exceptions\
 * LicensingValidationException` rather than a new exception class, exactly
 * matching the source's own single `ControlPlaneValidationError` shared
 * across this whole file (licensing, organisation administration, and
 * navigation all throw the identical {code, message} pair).
 */
class NavigationValidator
{
    /** @return array{parentType: string, parentId: string} */
    public static function childrenQuery(mixed $parentType, mixed $parentId): array
    {
        $type = mb_strtolower(trim((string) ($parentType ?? '')));
        if (! in_array($type, ['workspace', 'folder'], true)) {
            throw new LicensingValidationException('PARENT_TYPE_INVALID', 'parent_type must be workspace or folder.');
        }
        $id = trim((string) ($parentId ?? ''));
        if ($id === '') {
            throw new LicensingValidationException('PARENT_ID_REQUIRED', 'parent_id is required.');
        }

        return ['parentType' => $type, 'parentId' => $id];
    }

    /**
     * Stores `value` as a JSON string, matching how other JSON-blob
     * columns in this schema are stored.
     *
     * @return array{preferenceType: string, value: string}
     */
    public static function preference(mixed $input): array
    {
        if (! is_array($input) || array_is_list($input)) {
            throw new LicensingValidationException('PAYLOAD_INVALID', 'A navigation preference object is required.');
        }
        $preferenceType = mb_strtolower(trim((string) ($input['preference_type'] ?? '')));
        if (! preg_match('/^[a-z][a-z0-9_]{1,59}$/', $preferenceType)) {
            throw new LicensingValidationException('PREFERENCE_TYPE_INVALID', 'preference_type must contain 2 to 60 lowercase letters, numbers or underscores, starting with a letter.');
        }
        if (! array_key_exists('value', $input)) {
            throw new LicensingValidationException('VALUE_REQUIRED', 'value is required.');
        }
        $serialized = json_encode($input['value']);
        if ($serialized === false) {
            throw new LicensingValidationException('VALUE_NOT_SERIALIZABLE', 'value must be JSON-serializable.');
        }
        if ($serialized === '' || mb_strlen($serialized) > 8_192) {
            throw new LicensingValidationException('VALUE_TOO_LARGE', 'value must serialize to at most 8192 characters.');
        }

        return ['preferenceType' => $preferenceType, 'value' => $serialized];
    }
}
