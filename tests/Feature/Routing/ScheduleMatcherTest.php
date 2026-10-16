<?php

namespace Tests\Feature\Routing;

use App\Models\RoutingSchedule;
use App\Services\Routing\ScheduleMatcher;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsTenant;
use Tests\TestCase;

/**
 * Communication routing schedules — acceptance rules R7, R8, R9.
 *
 * Day index: 0 = Sunday ... 6 = Saturday (Asia/Baghdad local time).
 */
class ScheduleMatcherTest extends TestCase
{
    use BuildsTenant;

    private ScheduleMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = app(ScheduleMatcher::class);
    }

    /** R7: Sunday 09:00-17:00 matches 09:00 but not 17:00 (exclusive end). */
    public function test_end_of_window_is_exclusive(): void
    {
        $this->assertTrue($this->matcher->dayWindowMatches(0, '09:00:00', '17:00:00', 0, '09:00:00'));
        $this->assertTrue($this->matcher->dayWindowMatches(0, '09:00:00', '17:00:00', 0, '16:59:00'));
        $this->assertFalse($this->matcher->dayWindowMatches(0, '09:00:00', '17:00:00', 0, '17:00:00'));
        $this->assertFalse($this->matcher->dayWindowMatches(0, '09:00:00', '17:00:00', 0, '08:59:00'));
    }

    /** R8: Saturday 22:00 -> Sunday 02:00 matches Sunday 01:00 but not 02:00. */
    public function test_window_crossing_midnight(): void
    {
        // Saturday (6) 22:00 -> Sunday (0) 02:00
        $this->assertTrue($this->matcher->dayWindowMatches(6, '22:00:00', '02:00:00', 6, '23:30:00'));
        $this->assertTrue($this->matcher->dayWindowMatches(6, '22:00:00', '02:00:00', 0, '01:00:00'));
        $this->assertFalse($this->matcher->dayWindowMatches(6, '22:00:00', '02:00:00', 0, '02:00:00'));
        $this->assertFalse($this->matcher->dayWindowMatches(6, '22:00:00', '02:00:00', 6, '21:59:00'));
        $this->assertFalse($this->matcher->dayWindowMatches(6, '22:00:00', '02:00:00', 1, '01:00:00'));
    }

    /** A window matching requires the schedule (with its days) to match. */
    public function test_schedule_matches_through_relation(): void
    {
        $organization = $this->organization(['timezone' => 'Asia/Baghdad']);
        $user = $this->orgUser($organization, 'agent');

        $schedule = RoutingSchedule::create([
            'organization_id' => $organization->id,
            'name_ar' => 'دوام',
            'name_en' => 'Shift',
            'strategy' => 'simultaneous',
            'is_active' => true,
            'ring_duration' => 30,
            'no_answer_type' => 'voicemail',
            'outside_hours_type' => 'voicemail',
        ]);

        $schedule->days()->create(['day' => 0, 'start_time' => '09:00:00', 'end_time' => '17:00:00']);
        $schedule->targets()->create(['user_id' => $user->id, 'order' => 1]);

        $schedule->load('days', 'targets.user', 'organization');

        // Sunday 2026-10-18 10:00 Asia/Baghdad
        $sunday = \Carbon\Carbon::parse('2026-10-18 10:00:00', 'Asia/Baghdad');
        $this->assertTrue($this->matcher->matches($schedule, $sunday));
        $this->assertCount(1, $this->matcher->activeTargets($schedule, $sunday));

        // Monday 2026-10-19 10:00 -> same clock time but different weekday.
        $monday = \Carbon\Carbon::parse('2026-10-19 10:00:00', 'Asia/Baghdad');
        $this->assertFalse($this->matcher->matches($schedule, $monday));
        $this->assertCount(0, $this->matcher->activeTargets($schedule, $monday));
    }

    public function test_inactive_schedule_never_matches(): void
    {
        $organization = $this->organization();
        $schedule = RoutingSchedule::create([
            'organization_id' => $organization->id,
            'name_ar' => 'x', 'name_en' => 'x',
            'strategy' => 'simultaneous', 'is_active' => false, 'ring_duration' => 30,
            'no_answer_type' => 'voicemail', 'outside_hours_type' => 'voicemail',
        ]);
        $schedule->days()->create(['day' => 0, 'start_time' => '00:00:00', 'end_time' => '23:59:00']);
        $schedule->load('days', 'organization');

        $this->assertFalse($this->matcher->matches($schedule, \Carbon\Carbon::parse('2026-10-18 10:00:00', 'Asia/Baghdad')));
    }

    public function test_validate_days_rejects_empty(): void
    {
        $this->expectException(ValidationException::class);
        $this->matcher->validateDays([]);
    }

    public function test_validate_days_rejects_start_equals_end(): void
    {
        $this->expectException(ValidationException::class);
        $this->matcher->validateDays([
            ['day' => 0, 'start_time' => '09:00', 'end_time' => '09:00'],
        ]);
    }

    public function test_validate_days_rejects_duplicate(): void
    {
        $this->expectException(ValidationException::class);
        $this->matcher->validateDays([
            ['day' => 0, 'start_time' => '09:00', 'end_time' => '17:00'],
            ['day' => 0, 'start_time' => '09:00', 'end_time' => '17:00'],
        ]);
    }

    /** R9: two overlapping windows are rejected. */
    public function test_validate_days_rejects_overlap_same_day(): void
    {
        $this->expectException(ValidationException::class);
        $this->matcher->validateDays([
            ['day' => 0, 'start_time' => '09:00', 'end_time' => '13:00'],
            ['day' => 0, 'start_time' => '12:00', 'end_time' => '17:00'],
        ]);
    }

    /** R9: overlap across the midnight boundary is also rejected. */
    public function test_validate_days_rejects_overlap_across_midnight(): void
    {
        $this->expectException(ValidationException::class);
        $this->matcher->validateDays([
            ['day' => 6, 'start_time' => '22:00', 'end_time' => '02:00'], // Sat -> Sun
            ['day' => 0, 'start_time' => '01:00', 'end_time' => '03:00'], // Sun
        ]);
    }

    public function test_validate_days_accepts_adjacent_non_overlapping(): void
    {
        $this->matcher->validateDays([
            ['day' => 0, 'start_time' => '09:00', 'end_time' => '13:00'],
            ['day' => 0, 'start_time' => '13:00', 'end_time' => '17:00'],
        ]);
        $this->assertTrue(true);
    }

    public function test_validate_targets_accepts_same_org_active_users(): void
    {
        $organization = $this->organization();
        $a = $this->orgUser($organization, 'agent');
        $b = $this->orgUser($organization, 'agent');

        $this->matcher->validateTargets([$a->id, $b->id], $organization->id, 2);
        $this->assertTrue(true);
    }

    public function test_validate_targets_rejects_duplicate_user(): void
    {
        $organization = $this->organization();
        $a = $this->orgUser($organization, 'agent');

        $this->expectException(ValidationException::class);
        $this->matcher->validateTargets([$a->id, $a->id], $organization->id, 2);
    }

    public function test_validate_targets_rejects_user_from_another_organization(): void
    {
        $orgA = $this->organization();
        $orgB = $this->organization();
        $foreign = $this->orgUser($orgB, 'agent');

        $this->expectException(ValidationException::class);
        $this->matcher->validateTargets([$foreign->id], $orgA->id, 2);
    }

    public function test_validate_targets_rejects_inactive_user(): void
    {
        $organization = $this->organization();
        $inactive = $this->orgUser($organization, 'agent', ['active' => false]);

        $this->expectException(ValidationException::class);
        $this->matcher->validateTargets([$inactive->id], $organization->id, 2);
    }

    public function test_validate_targets_rejects_more_than_two(): void
    {
        $organization = $this->organization();
        $ids = [
            $this->orgUser($organization, 'agent')->id,
            $this->orgUser($organization, 'agent')->id,
            $this->orgUser($organization, 'agent')->id,
        ];

        $this->expectException(ValidationException::class);
        $this->matcher->validateTargets($ids, $organization->id, 2);
    }

    public function test_validate_ring_duration_bounds(): void
    {
        $this->assertSame(5, $this->matcher->validateRingDuration(5));
        $this->assertSame(120, $this->matcher->validateRingDuration(120));
    }

    public function test_validate_ring_duration_rejects_out_of_range(): void
    {
        $this->expectException(ValidationException::class);
        $this->matcher->validateRingDuration(4);
    }

    public function test_validate_ring_duration_rejects_above_max(): void
    {
        $this->expectException(ValidationException::class);
        $this->matcher->validateRingDuration(121);
    }
}
