<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Ported from app/api/health/{live,ready}/route.ts -- liveness/readiness probes. */
class HealthCheckTest extends TestCase
{
    public function test_liveness_reports_up_without_touching_the_database(): void
    {
        $response = $this->getJson('/api/health/live');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'UP')
            ->assertJsonPath('service', 'vat-msa-web');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_readiness_reports_ready_when_the_database_is_reachable(): void
    {
        $response = $this->getJson('/api/health/ready');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'READY')
            ->assertJsonPath('service', 'vat-msa-web');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_readiness_reports_not_ready_when_the_database_is_unreachable(): void
    {
        DB::shouldReceive('select')->once()->andThrow(new \RuntimeException('connection refused'));

        $response = $this->getJson('/api/health/ready');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'NOT_READY')
            ->assertHeader('Retry-After', '5');
    }

    public function test_both_probes_are_reachable_without_authentication(): void
    {
        // No actingAs() anywhere in this file -- these must work for an
        // unauthenticated load balancer, same posture as
        // PublicVerificationController.
        $this->getJson('/api/health/live')->assertStatus(200);
        $this->getJson('/api/health/ready')->assertStatus(200);
    }
}
