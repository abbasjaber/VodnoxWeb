<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_locale_is_arabic(): void
    {
        $this->assertEquals('ar', config('app.locale'));
    }

    public function test_fallback_locale_is_english(): void
    {
        $this->assertEquals('en', config('app.fallback_locale'));
    }

    public function test_api_locale_from_accept_language_header_ar(): void
    {
        $response = $this->withHeaders(['Accept-Language' => 'ar'])
            ->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJson(['message' => 'الخدمة تعمل.']);
    }

    public function test_api_locale_from_accept_language_header_en(): void
    {
        $response = $this->withHeaders(['Accept-Language' => 'en'])
            ->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJson(['message' => 'Service is running.']);
    }

    public function test_api_unsupported_locale_falls_back_to_default(): void
    {
        $response = $this->withHeaders(['Accept-Language' => 'fr'])
            ->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJson(['message' => 'الخدمة تعمل.']);
    }

    public function test_web_locale_from_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['locale' => 'en'])
            ->getJson('/logout');

        // After setting locale in session, subsequent requests should use it.
        $response = $this->withSession(['locale' => 'en'])
            ->getJson('/');

        $response->assertStatus(200);
        $this->assertEquals('en', app()->getLocale());
    }
}
