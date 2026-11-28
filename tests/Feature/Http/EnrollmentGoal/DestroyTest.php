<?php

declare(strict_types=1);

namespace Tests\Feature\Http\EnrollmentGoal;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\MockExam;
use App\Models\MockExamSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_physically_deletes_goals_in_both_achievement_states(): void
    {
        $enrollment = Enrollment::factory()->create();
        $this->actingAs($enrollment->user);

        foreach ([EnrollmentGoal::factory(), EnrollmentGoal::factory()->achieved()] as $factory) {
            $goal = $factory->for($enrollment)->create();
            $this->delete(route('enrollment-goals.destroy', $goal))
                ->assertRedirect(route('enrollments.show', $enrollment))
                ->assertSessionHas('success', '目標を削除しました。');
            $this->assertDatabaseMissing('enrollment_goals', ['id' => $goal->id]);
        }
    }

    public function test_unenrollment_deletes_all_its_goals_and_moves_the_default(): void
    {
        $enrollment = Enrollment::factory()->create();
        $student = $enrollment->user;
        $student->update(['default_enrollment_id' => $enrollment->id]);
        $remaining = Enrollment::factory()->for($student)->passed()->create();
        EnrollmentGoal::factory()->for($enrollment)->create();
        EnrollmentGoal::factory()->for($enrollment)->achieved()->create();
        $otherGoal = EnrollmentGoal::factory()->for($remaining)->create();

        $this->actingAs($student)->delete(route('enrollments.destroy', $enrollment))->assertRedirect(route('enrollments.index'));
        $this->assertSoftDeleted($enrollment);
        $this->assertDatabaseMissing('enrollment_goals', ['enrollment_id' => $enrollment->id]);
        $this->assertDatabaseHas('enrollment_goals', ['id' => $otherGoal->id]);
        $this->assertSame($remaining->id, $student->fresh()->default_enrollment_id);
    }

    public function test_hard_delete_cascades_from_enrollment(): void
    {
        $goal = EnrollmentGoal::factory()->create();

        $goal->enrollment->forceDelete();

        $this->assertDatabaseMissing('enrollment_goals', ['id' => $goal->id]);
    }

    public function test_unenrollment_rolls_back_parent_and_goals_when_default_transfer_fails(): void
    {
        $goal = EnrollmentGoal::factory()->create();
        $enrollment = $goal->enrollment;
        $student = $enrollment->user;
        $student->update(['default_enrollment_id' => $enrollment->id]);
        Enrollment::factory()->for($student)->create();

        $dispatcher = User::getEventDispatcher();
        User::setEventDispatcher(clone $dispatcher);
        User::updating(static function (User $user) use ($student): void {
            if ($user->is($student) && $user->isDirty('default_enrollment_id')) {
                throw new RuntimeException('Default transfer failed');
            }
        });

        try {
            $this->withoutExceptionHandling()->actingAs($student)->delete(route('enrollments.destroy', $enrollment));
            $this->fail('The expected default transfer exception was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Default transfer failed', $exception->getMessage());
        } finally {
            User::setEventDispatcher($dispatcher);
        }

        $this->assertNotSoftDeleted($enrollment);
        $this->assertDatabaseHas('enrollment_goals', ['id' => $goal->id]);
        $this->assertSame($enrollment->id, $student->fresh()->default_enrollment_id);
    }

    public function test_completion_failure_and_resume_keep_goals(): void
    {
        $goal = EnrollmentGoal::factory()->achieved()->create();
        $enrollment = $goal->enrollment;
        $original = $goal->fresh()->getAttributes();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.enrollments.fail', $enrollment), ['reason' => '学習中止テスト'])->assertRedirect();
        $this->assertSame(EnrollmentStatus::Failed, $enrollment->fresh()->status);
        $this->assertSame($original, $goal->fresh()->getAttributes());

        $this->actingAs($enrollment->user)->post(route('enrollments.resume', $enrollment))->assertRedirect();
        $this->assertSame(EnrollmentStatus::Learning, $enrollment->fresh()->status);
        $this->assertSame($original, $goal->fresh()->getAttributes());

        $exam = MockExam::factory()->for($enrollment->certification)->published()->create();
        MockExamSession::factory()->for($enrollment)->for($exam)->create(['pass' => true]);
        $this->post(route('enrollments.receiveCertificate', $enrollment))->assertRedirect();
        $this->assertSame(EnrollmentStatus::Passed, $enrollment->fresh()->status);
        $this->assertSame($original, $goal->fresh()->getAttributes());
    }
}
