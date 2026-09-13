<?php

namespace App\Support\Platform;

use Illuminate\Support\Facades\DB;

/**
 * The first real consumer(s) of `platform_config`/`access_policies` values
 * beyond `PlatformChangeService`'s own read/propose/decide command surface
 * -- see docs/MIGRATION_MATRIX.md's own "Platform config & change-
 * management" note: "these seeded config values are illustrative/
 * documentary today -- changing a row here does not yet feed back into any
 * other module's own hardcoded constants ... wiring every consumer to read
 * live from platform_config is a larger, cross-module change deliberately
 * left for when a second consumer actually needs it." This is that change,
 * for the two hardcoded constants that already have a seeded row to read
 * from (`ReportExportService`'s export size cap and minimum-cell-
 * suppression threshold, `StepUp`'s freshness window).
 *
 * Every reader here falls back to the exact literal its caller used to
 * hardcode when no ACTIVE row exists for the given key/code -- so a test
 * suite (or a fresh install) that never seeds `platform_config`/
 * `access_policies` keeps the exact behaviour it already had, and only a
 * real seeded/changed row ever takes effect. Deliberately two plain
 * static reads, not a cached service: these values are consulted at most
 * once per request by their own callers, the same "no premature
 * abstraction" posture the rest of this migration already applies.
 */
final class PlatformConfigReader
{
    /**
     * Reads one `platform_config` row's own `value` column, cast to int.
     * Returns $default if no ACTIVE row exists for $key, or its value
     * isn't numeric.
     */
    public static function int(string $key, int $default): int
    {
        $value = DB::table('platform_config')->where('key', $key)->where('status', 'ACTIVE')->value('value');

        return $value !== null && is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Reads one field out of an `access_policies` row's own JSON
     * `parameters` column, cast to int. Returns $default if no ACTIVE row
     * exists for $code, its `parameters` aren't a JSON object, or the
     * field is missing/not numeric.
     */
    public static function policyInt(string $code, string $field, int $default): int
    {
        $parameters = DB::table('access_policies')->where('code', $code)->where('status', 'ACTIVE')->value('parameters');
        if ($parameters === null) {
            return $default;
        }
        $decoded = json_decode($parameters, true);
        $value = is_array($decoded) ? ($decoded[$field] ?? null) : null;

        return is_numeric($value) ? (int) $value : $default;
    }
}
