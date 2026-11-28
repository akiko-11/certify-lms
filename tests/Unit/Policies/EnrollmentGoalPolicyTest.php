<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Enums\EnrollmentStatus;
use App\Enums\UserStatus;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use App\Policies\EnrollmentGoalPolicy;
use App\Policies\EnrollmentPolicy;
use Tests\TestCase;

class EnrollmentGoalPolicyTest extends TestCase
{
    public function test_owner_write_ability_depends_on_user_status_and_parent_existence_only(): void
    {
        $owner = User::factory()->student()->inProgress()->make(['id' => 'owner']);
        $enrollment = new Enrollment(['user_id' => $owner->id]);
        $goal = (new EnrollmentGoal)->setRelation('enrollment', $enrollment);
        $policy = new EnrollmentGoalPolicy(new EnrollmentPolicy);

        foreach (EnrollmentStatus::cases() as $status) {
            $enrollment->status = $status;
            $this->assertTrue($policy->create($owner, $enrollment));
            $this->assertTrue($policy->update($owner, $goal));
            $this->assertTrue($policy->delete($owner, $goal));
            $this->assertTrue($policy->markAchieved($owner, $goal));
            $this->assertTrue($policy->unmarkAchieved($owner, $goal));
        }

        foreach ([UserStatus::Invited, UserStatus::Graduated, UserStatus::Withdrawn] as $status) {
            $owner->status = $status;
            $this->assertFalse($policy->view($owner, $enrollment));
            $this->assertFalse($policy->create($owner, $enrollment));
            $this->assertFalse($policy->update($owner, $goal));
        }

        $owner->status = UserStatus::InProgress;
        $enrollment->deleted_at = now();
        $this->assertFalse($policy->view($owner, $enrollment));
        $this->assertFalse($policy->update($owner, $goal));
        $goal->setRelation('enrollment', null);
        $this->assertFalse($policy->update($owner, $goal));
    }
}
