<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EnrollmentGoal> */
class EnrollmentGoalFactory extends Factory
{
    protected $model = EnrollmentGoal::class;

    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'title' => fake()->sentence(4),
            'description' => null,
            'target_date' => null,
            'achieved_at' => null,
        ];
    }

    public function achieved(): static
    {
        return $this->state(fn () => ['achieved_at' => now()]);
    }
}
