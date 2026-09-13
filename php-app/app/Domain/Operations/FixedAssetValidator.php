<?php

namespace App\Domain\Operations;

use App\Exceptions\OperationsValidationException;

/**
 * Direct port of lib/domain/fixed-asset.ts -- Operations > Immovable Asset
 * Management and Movable Asset Management (NamRA e-VAT MS master prompt
 * section 16E): pure validation and the register -> (active <-> under
 * maintenance) -> disposed lifecycle for an organisation's own immovable
 * (land/buildings) and movable (vehicles, equipment, furniture, IT
 * hardware) assets. One shared table/model/service backs both -- the two
 * are the same lifecycle with a different asset_class discriminator, not
 * two separate concepts, so the UI pages simply filter on asset_class
 * rather than duplicating this validator.
 */
class FixedAssetValidator
{
    private const ASSET_CLASS_VALUES = ['IMMOVABLE', 'MOVABLE'];
    private const IMMOVABLE_CATEGORY_VALUES = ['LAND', 'BUILDING', 'OTHER'];
    private const MOVABLE_CATEGORY_VALUES = ['VEHICLE', 'EQUIPMENT', 'FURNITURE', 'IT_HARDWARE', 'OTHER'];
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    /**
     * @return array{schema_version: string, asset_class: string, asset_code: string, category: string,
     *   description: string, serial_or_registration_number: ?string, location_or_address: string,
     *   custodian_employee_id: ?string, acquisition_date: string, acquisition_cost_cents: int, current_value_cents: ?int}
     */
    public static function registration(array $input): array
    {
        $messages = [];
        self::schemaVersion($input, $messages);
        $assetClass = mb_strtoupper(self::textValue($input['asset_class'] ?? null));
        if (! in_array($assetClass, self::ASSET_CLASS_VALUES, true)) {
            $messages[] = ['code' => 'ASSET_CLASS_INVALID', 'path' => '/asset_class', 'message' => 'asset_class must be IMMOVABLE or MOVABLE.'];
        }
        $assetCode = mb_strtoupper(self::textField($input['asset_code'] ?? null, '/asset_code', 'Asset code', 2, 40, $messages));
        $category = mb_strtoupper(self::textValue($input['category'] ?? null));
        $validCategories = $assetClass === 'IMMOVABLE' ? self::IMMOVABLE_CATEGORY_VALUES : self::MOVABLE_CATEGORY_VALUES;
        if (in_array($assetClass, self::ASSET_CLASS_VALUES, true) && ! in_array($category, $validCategories, true)) {
            $messages[] = ['code' => 'CATEGORY_INVALID', 'path' => '/category', 'message' => 'category must be one of: '.implode(', ', $validCategories).'.'];
        }
        $description = self::textField($input['description'] ?? null, '/description', 'Description', 2, 300, $messages);
        $serialOrRegistrationNumber = self::optionalText($input['serial_or_registration_number'] ?? null, '/serial_or_registration_number', 'Serial or registration number', 80, $messages);
        $locationOrAddress = self::textField($input['location_or_address'] ?? null, '/location_or_address', 'Location or address', 2, 300, $messages);
        $custodianEmployeeId = self::optionalText($input['custodian_employee_id'] ?? null, '/custodian_employee_id', 'Custodian employee', 80, $messages);
        $acquisitionDate = self::textValue($input['acquisition_date'] ?? null);
        if (! preg_match(self::DATE_PATTERN, $acquisitionDate) || strtotime("{$acquisitionDate}T00:00:00Z") === false) {
            $messages[] = ['code' => 'ACQUISITION_DATE_INVALID', 'path' => '/acquisition_date', 'message' => 'acquisition_date must be an ISO date (YYYY-MM-DD).'];
        }
        $acquisitionCostCents = self::money($input['acquisition_cost_cents'] ?? null, '/acquisition_cost_cents', 'Acquisition cost', $messages) ?? 0;
        $currentValueCents = self::money($input['current_value_cents'] ?? null, '/current_value_cents', 'Current value', $messages, true);
        if (count($messages) > 0) {
            throw new OperationsValidationException($messages);
        }

        return [
            'schema_version' => '1.0.0', 'asset_class' => $assetClass, 'asset_code' => $assetCode, 'category' => $category,
            'description' => $description, 'serial_or_registration_number' => $serialOrRegistrationNumber,
            'location_or_address' => $locationOrAddress, 'custodian_employee_id' => $custodianEmployeeId,
            'acquisition_date' => $acquisitionDate, 'acquisition_cost_cents' => $acquisitionCostCents, 'current_value_cents' => $currentValueCents,
        ];
    }

    /** @return array{schema_version: string, current_value_cents: int} */
    public static function valuation(array $input): array
    {
        $messages = [];
        self::schemaVersion($input, $messages);
        $currentValueCents = self::money($input['current_value_cents'] ?? null, '/current_value_cents', 'Current value', $messages) ?? 0;
        if (count($messages) > 0) {
            throw new OperationsValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'current_value_cents' => $currentValueCents];
    }

    /** @return array{schema_version: string, reason: string} */
    public static function disposal(array $input): array
    {
        $messages = [];
        self::schemaVersion($input, $messages);
        $reason = self::textField($input['reason'] ?? null, '/reason', 'Reason', 10, 500, $messages);
        if (count($messages) > 0) {
            throw new OperationsValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'reason' => $reason];
    }

    private const TRANSITIONS = [
        'ACTIVE' => ['FLAG_MAINTENANCE' => 'UNDER_MAINTENANCE', 'DISPOSE' => 'DISPOSED'],
        'UNDER_MAINTENANCE' => ['RESTORE' => 'ACTIVE', 'DISPOSE' => 'DISPOSED'],
        'DISPOSED' => [],
    ];

    public static function assertTransition(string $action, string $current): string
    {
        $target = self::TRANSITIONS[$current][$action] ?? null;
        if (! $target) {
            $verb = mb_strtolower(str_replace('_', ' ', $action));
            throw new OperationsValidationException([
                ['code' => 'FIXED_ASSET_TRANSITION_INVALID', 'path' => '/action', 'message' => "Cannot {$verb} an asset currently {$current}."],
            ]);
        }

        return $target;
    }

    // -- shared field helpers, matching App\Domain\Business\BusinessValidator's own style --

    private static function schemaVersion(array $input, array &$messages): void
    {
        if (($input['schema_version'] ?? null) !== '1.0.0') {
            $messages[] = ['code' => 'SCHEMA_VERSION_UNSUPPORTED', 'path' => '/schema_version', 'message' => 'schema_version must be 1.0.0.'];
        }
    }

    private static function textValue(mixed $value): string
    {
        return is_string($value) ? trim(preg_replace('/\s+/', ' ', $value)) : '';
    }

    private static function textField(mixed $value, string $path, string $label, int $min, int $max, array &$messages): string
    {
        $normalized = self::textValue($value);
        $length = mb_strlen($normalized);
        if ($length < $min || $length > $max) {
            $messages[] = ['code' => 'FIELD_LENGTH_INVALID', 'path' => $path, 'message' => "{$label} must contain {$min} to {$max} characters."];
        }

        return $normalized;
    }

    private static function optionalText(mixed $value, string $path, string $label, int $max, array &$messages): ?string
    {
        $normalized = self::textValue($value);
        if ($normalized === '') {
            return null;
        }
        if (mb_strlen($normalized) > $max) {
            $messages[] = ['code' => 'FIELD_LENGTH_INVALID', 'path' => $path, 'message' => "{$label} must not exceed {$max} characters."];
        }

        return $normalized;
    }

    private static function money(mixed $value, string $path, string $label, array &$messages, bool $allowNull = false): ?int
    {
        if ($value === null || $value === '') {
            if ($allowNull) {
                return null;
            }
            $messages[] = ['code' => 'FIELD_REQUIRED', 'path' => $path, 'message' => "{$label} is required."];

            return 0;
        }
        $cents = filter_var($value, FILTER_VALIDATE_INT);
        if ($cents === false || $cents < 0) {
            $messages[] = ['code' => 'AMOUNT_INVALID', 'path' => $path, 'message' => "{$label} must be a non-negative integer number of cents."];

            return 0;
        }

        return $cents;
    }
}
