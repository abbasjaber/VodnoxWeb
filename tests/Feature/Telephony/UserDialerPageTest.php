<?php

namespace Tests\Feature\Telephony;

use App\Models\Organization;
use App\Models\User;
use Tests\Concerns\BuildsTenant;
use Tests\TestCase;

/**
 * The agent dialer page (/user) must ship the real browser phone and nothing
 * that fights with it.
 *
 * Historically the layout also loaded the prototype script
 * public/assets/vodnox/user.js, which rendered a whole mock workspace and bound
 * handlers to elements the server-rendered page does not contain, throwing
 * "Cannot set properties of null (setting 'onclick')" and leaving the call
 * button dead. These assertions lock in the fix: one phone card, the SIP stack,
 * and no prototype script.
 */
class UserDialerPageTest extends TestCase
{
    use BuildsTenant;

    private Organization $organization;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->organization();
        $this->agent = $this->orgUser($this->organization, 'agent', ['extension' => '1001']);
    }

    public function test_the_dialer_renders_the_browser_phone_card(): void
    {
        $response = $this->actingAs($this->agent)->get(route('user.index'))->assertOk();

        $response->assertSee('id="vodnoxPhone"', false);
        $response->assertSee('data-credentials-url="'.route('user.telephony.credentials').'"', false);
        $response->assertSee('data-registration-url="'.route('user.telephony.registration').'"', false);
        $response->assertSee('id="phoneCallBtn"', false);
        $response->assertSee('id="phoneAnswerBtn"', false);
        $response->assertSee('id="phoneHangupBtn"', false);
    }

    public function test_the_dialer_loads_the_sip_stack_and_its_own_script(): void
    {
        $response = $this->actingAs($this->agent)->get(route('user.index'))->assertOk();

        $response->assertSee('assets/vodnox/vendor/sip.min.js', false);
        $response->assertSee('assets/vodnox/telephony.js', false);
        $response->assertSee('assets/vodnox/telephony.css', false);
    }

    public function test_the_dialer_never_loads_the_prototype_workspace_script(): void
    {
        $response = $this->actingAs($this->agent)->get(route('user.index'))->assertOk();

        $response->assertDontSee('assets/vodnox/user.js', false);
        $response->assertDontSee('assets/vodnox/user-icons.js', false);
    }

    public function test_the_phone_card_is_rendered_once_per_page(): void
    {
        $response = $this->actingAs($this->agent)->get(route('user.index'))->assertOk();

        $this->assertSame(1, substr_count($response->getContent(), 'id="vodnoxPhone"'));
        $this->assertSame(1, substr_count($response->getContent(), 'assets/vodnox/telephony.js'));
    }

    public function test_the_workspaces_render_the_same_single_phone_card(): void
    {
        foreach ([route('user.incoming.index'), route('user.reception.index')] as $url) {
            $response = $this->actingAs($this->agent)->get($url)->assertOk();

            $this->assertSame(1, substr_count($response->getContent(), 'id="vodnoxPhone"'), $url);
            $this->assertSame(1, substr_count($response->getContent(), 'assets/vodnox/telephony.js'), $url);
            $response->assertDontSee('assets/vodnox/user.js', false);
        }
    }

    public function test_every_asset_the_phone_card_needs_is_actually_present(): void
    {
        foreach ([
            'assets/vodnox/vendor/sip.min.js',
            'assets/vodnox/telephony.js',
            'assets/vodnox/telephony.css',
        ] as $asset) {
            $this->assertFileExists(public_path($asset), $asset);
        }

        // SIP.js is bundled as an IIFE that publishes a global; telephony.js
        // relies on window.SIP and would refuse to register otherwise.
        $this->assertStringContainsString(
            'window.SIP',
            file_get_contents(public_path('assets/vodnox/vendor/sip.min.js')),
        );
    }

    /**
     * The FTP deploy used to exclude "**&#47;vendor&#47;**", which also swallowed
     * public/assets/vodnox/vendor/ and stripped sip.min.js from every release.
     * The browser phone then died with "SIP is not defined".
     */
    public function test_the_deploy_excludes_only_the_root_composer_vendor_directory(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/main.yml'));

        $this->assertStringNotContainsString('**/vendor/**', $workflow);
        $this->assertStringContainsString("\n            vendor/**", $workflow);
    }

    public function test_the_incoming_alert_is_marked_for_assistive_technology(): void
    {
        $response = $this->actingAs($this->agent)->get(route('user.index'))->assertOk();

        $response->assertSee('id="phoneIncoming" role="alert"', false);
        $response->assertSee('aria-live="assertive"', false);
        $response->assertSee('id="phoneIncomingLabel"', false);
    }

    public function test_the_ring_tone_is_generated_locally_with_no_external_asset(): void
    {
        $script = file_get_contents(public_path('assets/vodnox/telephony.js'));

        $this->assertStringContainsString('AudioContext', $script);
        $this->assertStringNotContainsString('new Audio(', $script);
        $this->assertDoesNotMatchRegularExpression('~https?://[^"\')\s]+\.(mp3|wav|ogg)~i', $script);
    }

    public function test_the_deprecated_unload_listener_is_not_used(): void
    {
        $script = file_get_contents(public_path('assets/vodnox/telephony.js'));

        $this->assertStringNotContainsString("addEventListener('unload'", $script);
        $this->assertStringContainsString("addEventListener('pagehide'", $script);
    }

    public function test_the_ring_stops_on_every_terminal_path(): void
    {
        $script = file_get_contents(public_path('assets/vodnox/telephony.js'));

        // answer, reject, hangup, session-established and end-session.
        $this->assertGreaterThanOrEqual(4, substr_count($script, 'stopTone();'));
    }

    public function test_notifications_are_opt_in_with_an_in_page_fallback(): void
    {
        $script = file_get_contents(public_path('assets/vodnox/telephony.js'));

        $this->assertStringContainsString("permission !== 'granted'", $script);
        $this->assertStringContainsString('requestNotificationPermission', $script);
    }
}
