<?php

namespace Tests\Feature\Public;

use App\Models\ContactInquiry;
use Tests\TestCase;

/**
 * Public website pages (R1) and the contact inquiry flow (spec 2.1).
 */
class PublicPagesTest extends TestCase
{
    public function test_home_page_renders(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_public_pages_render(): void
    {
        foreach (['/services', '/pricing', '/about', '/contact', '/login'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_contact_form_persists_inquiry(): void
    {
        $response = $this->post('/contact', [
            'name' => 'زائر تجريبي',
            'email' => 'visitor@example.test',
            'phone' => '+9647700000000',
            'company' => 'شركة تجريبية',
            'message' => 'أرغب بمعرفة المزيد عن الخدمات.',
        ]);

        $response->assertRedirect(route('contact'));

        $this->assertDatabaseHas('contact_inquiries', [
            'name' => 'زائر تجريبي',
            'email' => 'visitor@example.test',
            'status' => 'new',
        ]);

        $this->assertSame(1, ContactInquiry::query()->count());
    }

    public function test_contact_form_requires_name_and_message(): void
    {
        $response = $this->from('/contact')->post('/contact', [
            'email' => 'visitor@example.test',
        ]);

        $response->assertSessionHasErrors(['name', 'message']);
        $this->assertSame(0, ContactInquiry::query()->count());
    }

    public function test_contact_form_rejects_invalid_email(): void
    {
        $response = $this->from('/contact')->post('/contact', [
            'name' => 'زائر',
            'email' => 'not-an-email',
            'message' => 'رسالة',
        ]);

        $response->assertSessionHasErrors(['email']);
        $this->assertSame(0, ContactInquiry::query()->count());
    }
}
