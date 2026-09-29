<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_liveness_endpoint(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_readiness_endpoint_checks_the_database(): void
    {
        $this->getJson('/api/v1/health/ready')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database.ok', true);
    }

    public function test_root_describes_the_api(): void
    {
        $this->getJson('/')->assertOk()->assertJsonStructure(['name', 'api', 'health']);
        $this->getJson('/api/v1')->assertOk()->assertJsonPath('version', 'v1');
    }
}
