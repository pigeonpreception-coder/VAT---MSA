<?php

namespace App\Domain\Operations;

use App\Exceptions\OperationsValidationException;

/**
 * Direct port of lib/domain/logistics.ts -- Operations > Logistics Module
 * (NamRA e-VAT MS master prompt section 16E): pure validation and the
 * create -> dispatch -> deliver lifecycle (or cancel, from either PENDING
 * or IN_TRANSIT) for a delivery of goods already sold -- a delivery always
 * references the tax invoice (or a POS sale) it is fulfilling; it never
 * invents its own separate sale record.
 */
class LogisticsValidator
{
    private const REFERENCE_TYPE_VALUES = ['INVOICE', 'POS_SALE', 'OTHER'];

    /**
     * @return array{schema_version: string, delivery_number: string, reference_type: string, reference_id: ?string,
     *   origin: string, destination: string, vehicle_asset_id: ?string, notes: ?string}
     */
    public static function creation(array $input): array
    {
        $messages = [];
        self::schemaVersion($input, $messages);
        $deliveryNumber = mb_strtoupper(self::textField($input['delivery_number'] ?? null, '/delivery_number', 'Delivery number', 2, 40, $messages));
        $referenceType = mb_strtoupper(self::textValue($input['reference_type'] ?? null));
        if (! in_array($referenceType, self::REFERENCE_TYPE_VALUES, true)) {
            $messages[] = ['code' => 'REFERENCE_TYPE_INVALID', 'path' => '/reference_type', 'message' => 'reference_type must be one of: '.implode(', ', self::REFERENCE_TYPE_VALUES).'.'];
        }
        $referenceId = self::optionalText($input['reference_id'] ?? null, '/reference_id', 'Reference', 80, $messages);
        if ($referenceType !== 'OTHER' && ! $referenceId) {
            $messages[] = ['code' => 'REFERENCE_ID_REQUIRED', 'path' => '/reference_id', 'message' => 'reference_id is required unless reference_type is OTHER.'];
        }
        $origin = self::textField($input['origin'] ?? null, '/origin', 'Origin', 2, 300, $messages);
        $destination = self::textField($input['destination'] ?? null, '/destination', 'Destination', 2, 300, $messages);
        $vehicleAssetId = self::optionalText($input['vehicle_asset_id'] ?? null, '/vehicle_asset_id', 'Vehicle asset', 80, $messages);
        $notes = self::optionalText($input['notes'] ?? null, '/notes', 'Notes', 500, $messages);
        if (count($messages) > 0) {
            throw new OperationsValidationException($messages);
        }

        return [
            'schema_version' => '1.0.0', 'delivery_number' => $deliveryNumber, 'reference_type' => $referenceType,
            'reference_id' => $referenceId, 'origin' => $origin, 'destination' => $destination,
            'vehicle_asset_id' => $vehicleAssetId, 'notes' => $notes,
        ];
    }

    /** @return array{schema_version: string, reason: string} */
    public static function cancellation(array $input): array
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
        'PENDING' => ['DISPATCH' => 'IN_TRANSIT', 'CANCEL' => 'CANCELLED'],
        'IN_TRANSIT' => ['DELIVER' => 'DELIVERED', 'CANCEL' => 'CANCELLED'],
        'DELIVERED' => [],
        'CANCELLED' => [],
    ];

    public static function assertTransition(string $action, string $current): string
    {
        $target = self::TRANSITIONS[$current][$action] ?? null;
        if (! $target) {
            throw new OperationsValidationException([
                ['code' => 'LOGISTICS_DELIVERY_TRANSITION_INVALID', 'path' => '/action', 'message' => 'Cannot '.mb_strtolower($action)." a delivery currently {$current}."],
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
}
