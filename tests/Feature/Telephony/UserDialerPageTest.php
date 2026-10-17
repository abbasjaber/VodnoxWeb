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
}
