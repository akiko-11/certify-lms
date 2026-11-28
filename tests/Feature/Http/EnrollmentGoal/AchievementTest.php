<?php

declare(strict_types=1);

namespace Tests\Feature\Http\EnrollmentGoal;

use App\Models\EnrollmentGoal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AchievementTest extends TestCase
{
    use RefreshDatabase;

    public function test_achievement_transitions_and_repeated_operations_keep_all_timestamps(): void
    {
        $this->freezeSecond();
        $goal = EnrollmentGoal::factory()->create();
        $this->actingAs($goal->enrollment->user);
        $detail = route('enrollments.show', $goal->enrollment);
        $achieve = route('enrollment-goals.markAchieved', $goal);
        $undo = route('enrollment-goals.unmarkAchieved', $goal);
        $this->assertSame($undo, $achieve);

        $this->travel(1)->minutes();
        $before = $goal->fresh()->getAttributes();
        $this->delete($undo)->assertRedirect($detail)->assertSessionHas('info', 'この目標はすでに未達成です。');
        $this->assertSame($before, $goal->fresh()->getAttributes());

        $this->post($achieve)->assertRedirect($detail)->assertSessionHas('success', '目標を達成済みにしました。');
        $achievedAt = $goal->fresh()->achieved_at;
        $this->assertEquals(now(), $achievedAt);
        $after = $goal->fresh()->getAttributes();

        $this->travel(1)->minutes();
        $this->post($achieve)->assertRedirect($detail)->assertSessionHas('info', 'この目標はすでに達成済みです。');
        $this->assertSame($after, $goal->fresh()->getAttributes());
        $this->delete($undo)->assertRedirect($detail)->assertSessionHas('success', '目標の達成を取り消しました。');
        $this->assertNull($goal->fresh()->achieved_at);

        $this->travel(1)->minutes();
        $this->post($achieve)->assertRedirect($detail)->assertSessionHas('success', '目標を達成済みにしました。');
        $this->assertEquals(now(), $goal->fresh()->achieved_at);
        $this->assertTrue($goal->fresh()->achieved_at->greaterThan($achievedAt));
    }
}
