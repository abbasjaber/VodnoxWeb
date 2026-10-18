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

    public function test_the_incoming_call_uses_an_in_page_modal_dialog(): void
    {
        $response = $this->actingAs($this->agent)->get(route('user.index'))->assertOk();

        // An in-page <dialog> marked as a modal alert dialog (not a banner and
        // not a window.open popup), carrying the caller identity and both
        // actions so the window is usable on its own.
        $response->assertSee('id="phoneIncoming" class="phone-incoming" role="alertdialog" aria-modal="true"', false);
        $response->assertSee('aria-labelledby="phoneIncomingTitle"', false);
        $response->assertSee('id="phoneIncomingLabel"', false);
        $response->assertSee('id="phoneIncomingExtension"', false);
        $response->assertSee('id="phoneIncomingAvatar"', false);
        $response->assertSee('id="phoneIncomingSoundBtn"', false);
        $response->assertSee('id="phoneIncomingSound"', false);
        $response->assertSee('id="phoneAnswerBtn"', false);
        $response->assertSee('id="phoneRejectBtn"', false);
        $response->assertDontSee('role="alert" aria-live="assertive"', false);
    }

    public function test_the_incoming_window_is_opened_natively_and_cannot_be_dismissed(): void
    {
        $script = file_get_contents(public_path('assets/vodnox/telephony.js'));

        // The native modal API is what puts the window in the top layer, centres
        // it and traps the focus; window.open is deliberately never used.
        $this->assertStringContainsString('showModal()', $script);
        $this->assertStringNotContainsString('window.open(', $script);
        // Escape is refused (and a backdrop click is inert), so an answerable
        // call can never be left ringing with no interface.
        $this->assertStringContainsString("addEventListener('cancel'", $script);
        $this->assertStringContainsString('preventDefault()', $script);
    }

    public function test_the_incoming_call_guards_against_double_answers_and_duplicate_windows(): void
    {
        $script = file_get_contents(public_path('assets/vodnox/telephony.js'));

        // One window per call: showModal is only reached through a guard that
        // skips an already-open dialog, and both actions share an "answering"
        // latch so a double click cannot answer or reject twice.
        $this->assertStringContainsString('if (!ui.incoming.open)', $script);
        $this->assertStringContainsString('answering', $script);
        $this->assertStringContainsString('if (!incomingInvitation || answering)', $script);
        // A second INVITE while busy is still rejected cleanly.
        $this->assertStringContainsString('statusCode: 486', $script);
    }

    public function test_the_ring_tone_is_generated_locally_with_no_external_asset(): void
    {
        $script = file_get_contents(public_path('assets/vodnox/telephony.js'));

        $this->assertStringContainsString('AudioContext', $script);
        $this->assertStringNotContainsString('new Audio(', $script);
        $this->assertDoesNotMatchRegularExpression('~https?://[^"\')\s]+\.(mp3|wav|ogg)~i', $script);
        // A repeating two-note motif, not a single beep, and the notes are
        // released between cycles so nothing accumulates.
        $this->assertStringContainsString('RING_MOTIF', $script);
        $this->assertStringContainsString('RING_CADENCE', $script);
        $this->assertStringContainsString('releaseToneNodes', $script);
    }

    public function test_a_blocked_ring_offers_an_explicit_enable_sound_action_without_blocking_the_call(): void
    {
        $script = file_get_contents(public_path('assets/vodnox/telephony.js'));

        // startTone reports whether the browser let the sound start; when it did
        // not, the window shows the notice and its button, and answering still
        // works because the ring is cosmetic.
        $this->assertStringContainsString("if (!startTone('ringtone'))", $script);
        $this->assertStringContainsString('showSoundNotice', $script);
        $this->assertStringContainsString("ui.incomingSoundBtn.addEventListener('click'", $script);
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
        // Every terminal path also takes the incoming window down with it,
        // including a caller who cancels before the answer.
        $this->assertGreaterThanOrEqual(3, substr_count($script, 'closeIncomingDialog();'));
        $this->assertStringContainsString('closeIncomingNotification();', $script);
    }

    public function test_notifications_are_opt_in_and_background_only(): void
    {
        $script = file_get_contents(public_path('assets/vodnox/telephony.js'));

        $this->assertStringContainsString("permission !== 'granted'", $script);
        $this->assertStringContainsString('requestNotificationPermission', $script);
        // Asked at most once per page load, and only raised while the tab is in
        // the background so the in-page window is never duplicated on screen.
        $this->assertStringContainsString('notificationAsked', $script);
        $this->assertStringContainsString("document.visibilityState === 'visible'", $script);
    }
}
