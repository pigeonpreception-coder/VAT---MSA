<?php

namespace App\Domain\Licensing;

use Illuminate\Support\Carbon;

/**
 * Direct port of lib/domain/control-plane.ts's quarterlyAccessReviewWindow
 * -- the calendar-quarter window both `App\Support\Licensing\
 * EntitlementGate::assertEntitledOperation`'s `ADMIN_WRITE` gate and
 * `openQuarterlyAccessReview` resolve "the current quarter" from,
 * single-sourced rather than duplicated.
 */
class AccessReviewWindow
{
    /** @return array{key: string, periodStart: string, dueAt: Carbon} */
    public static function current(?Carbon $date = null): array
    {
        $date = ($date ?? Carbon::now('UTC'))->copy()->setTimezone('UTC');
        $year = (int) $date->format('Y');
        $quarter = intdiv(((int) $date->format('n') - 1), 3) + 1;
        $startMonth = ($quarter - 1) * 3; // 0-indexed, matching the source's own JS Date month convention

        $periodStart = Carbon::create($year, $startMonth + 1, 1, 0, 0, 0, 'UTC');
        $dueAt = Carbon::create($year, $startMonth + 4, 1, 0, 0, 0, 'UTC')->subSecond();

        return ['key' => "{$year}-Q{$quarter}", 'periodStart' => $periodStart->toDateString(), 'dueAt' => $dueAt];
    }
}
