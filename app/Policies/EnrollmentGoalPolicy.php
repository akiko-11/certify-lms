<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;

class EnrollmentGoalPolicy
{
    public function __construct(private readonly EnrollmentPolicy $enrollmentPolicy) {}

    public function view(User $user, Enrollment $enrollment): bool
    {
        if ($enrollment->trashed()) {
            return false;
        }

        return $user->role === UserRole::Student
            ? $this->create($user, $enrollment)
            : $this->enrollmentPolicy->view($user, $enrollment);
    }

    public function create(User $user, Enrollment $enrollment): bool
    {
        return $user->role === UserRole::Student
            && $user->status === UserStatus::InProgress
            && $enrollment->user_id === $user->id
            && ! $enrollment->trashed();
    }

    public function update(User $user, EnrollmentGoal $goal): bool
    {
        $enrollment = $goal->enrollment;

        return $enrollment !== null && $this->create($user, $enrollment);
    }

    public function delete(User $user, EnrollmentGoal $goal): bool
    {
        return $this->update($user, $goal);
    }

    public function markAchieved(User $user, EnrollmentGoal $goal): bool
    {
        return $this->update($user, $goal);
    }

    public function unmarkAchieved(User $user, EnrollmentGoal $goal): bool
    {
        return $this->update($user, $goal);
    }
}
