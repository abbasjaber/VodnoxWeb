<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['status'],
                'errors',
            ])
            ->assertJson([
                'success' => true,
                'data' => ['status' => 'ok'],
            ]);
    }

    public function test_health_endpoint_does_not_expose_versions(): void
    {
        $response = $this->getJson('/api/v1/health');

        $body = $response->json();

        $this->assertStringNotContainsString('Laravel', json_encode($body));
        $this->assertArrayNotHasKey('version', $body['data'] ?? []);
    }
}
