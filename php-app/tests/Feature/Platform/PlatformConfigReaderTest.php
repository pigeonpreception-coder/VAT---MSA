<?php

namespace Tests\Feature\Platform;

use App\Support\Platform\PlatformConfigReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers App\Support\Platform\PlatformConfigReader directly -- the reader
 * class itself, not any one of its callers (those are covered where they
 * live: App\Support\Access\StepUp and App\Services\Platform\
 * ReportExportService's own wiring is proven end-to-end in
 * ReportExportTest).
 */
class PlatformConfigReaderTest extends TestCase
{
    use RefreshDatabase;

    private function insertConfig(string $key, string $value, string $status = 'ACTIVE'): void
    {
        DB::table('platform_config')->insert([
            'id' => (string) Str::uuid(), 'key' => $key, 'category' => 'REPORTS',
            'value' => $value, 'description' => 'Test row.', 'status' => $status, 'updated_at' => now(),
        ]);
    }

    private function insertPolicy(string $code, string $parameters, string $status = 'ACTIVE'): void
    {
        DB::table('access_policies')->insert([
            'id' => (string) Str::uuid(), 'code' => $code, 'name' => 'Test policy', 'policy_type' => 'AUTHENTICATION',
            'description' => 'Test row.', 'parameters' => $parameters, 'status' => $status, 'updated_at' => now(),
        ]);
    }

    public function test_int_returns_the_default_when_no_row_exists(): void
    {
        $this->assertSame(42, PlatformConfigReader::int('no.such.key', 42));
    }

    public function test_int_returns_the_seeded_value_when_an_active_row_exists(): void
    {
        $this->insertConfig('test.key', '123');

        $this->assertSame(123, PlatformConfigReader::int('test.key', 42));
    }

    public function test_int_returns_the_default_when_the_value_is_not_numeric(): void
    {
        $this->insertConfig('test.key', 'not-a-number');

        $this->assertSame(42, PlatformConfigReader::int('test.key', 42));
    }

    public function test_int_ignores_a_row_that_is_not_active(): void
    {
        $this->insertConfig('test.key', '123', 'RETIRED');

        $this->assertSame(42, PlatformConfigReader::int('test.key', 42));
    }

    public function test_policy_int_returns_the_default_when_no_row_exists(): void
    {
        $this->assertSame(10800, PlatformConfigReader::policyInt('NO_SUCH_POLICY', 'window_seconds', 10800));
    }

    public function test_policy_int_returns_the_seeded_field_when_an_active_row_exists(): void
    {
        $this->insertPolicy('TEST_POLICY', json_encode(['window_seconds' => 900]));

        $this->assertSame(900, PlatformConfigReader::policyInt('TEST_POLICY', 'window_seconds', 10800));
    }

    public function test_policy_int_returns_the_default_when_the_field_is_missing(): void
    {
        $this->insertPolicy('TEST_POLICY', json_encode(['other_field' => 1]));

        $this->assertSame(10800, PlatformConfigReader::policyInt('TEST_POLICY', 'window_seconds', 10800));
    }

    public function test_policy_int_returns_the_default_when_the_field_is_not_numeric(): void
    {
        $this->insertPolicy('TEST_POLICY', json_encode(['window_seconds' => 'soon']));

        $this->assertSame(10800, PlatformConfigReader::policyInt('TEST_POLICY', 'window_seconds', 10800));
    }

    public function test_policy_int_ignores_a_row_that_is_not_active(): void
    {
        $this->insertPolicy('TEST_POLICY', json_encode(['window_seconds' => 900]), 'RETIRED');

        $this->assertSame(10800, PlatformConfigReader::policyInt('TEST_POLICY', 'window_seconds', 10800));
    }
}
