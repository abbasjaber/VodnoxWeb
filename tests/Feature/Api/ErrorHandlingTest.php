<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class ErrorHandlingTest extends TestCase
{
    public function test_not_found_returns_json_404(): void
    {
        $response = $this->getJson('/api/v1/nonexistent');

        $response->assertStatus(404)
            ->assertJsonStructure(['success', 'message', 'data', 'errors'])
            ->assertJson(['success' => false]);
    }

    public function test_method_not_allowed_returns_json_405(): void
    {
        $response = $this->postJson('/api/v1/health');

        $response->assertStatus(405)
            ->assertJsonStructure(['success', 'message', 'data', 'errors'])
            ->assertJson(['success' => false]);
    }

    public function test_api_errors_never_return_html(): void
    {
        $response = $this->getJson('/api/v1/nonexistent');

        $this->assertEquals('application/json', $response->headers->get('Content-Type'));
    }

    public function test_api_errors_do_not_leak_stack_trace(): void
    {
        $response = $this->getJson('/api/v1/nonexistent');

        $content = $response->getContent();

        $this->assertStringNotContainsString('stack', $content);
        $this->assertStringNotContainsString('Exception', $content);
    }
}
