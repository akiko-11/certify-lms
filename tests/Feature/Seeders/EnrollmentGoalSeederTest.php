<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\EnrollmentGoalSeeder;
use Database\Seeders\EnrollmentSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrollmentGoalSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_seeding_adds_goals_to_the_fixed_and_new_demo_enrollments_only(): void
    {
        Certification::factory()->published()->create(['name' => '基本情報技術者試験']);
        $manual = Enrollment::factory()->for(Certification::factory()->draft())->create();
        $existing = EnrollmentGoal::factory()->for($manual)->create(['title' => '既存の個人目標']);
        $seeder = new class extends DatabaseSeeder
        {
            protected function resolve($class): Seeder
            {
                // 実際の呼び出し順で、個人目標に関係するSeederだけを起動する。
                return match ($class) {
                    UserSeeder::class, EnrollmentSeeder::class, EnrollmentGoalSeeder::class => parent::resolve($class),
                    default => new class extends Seeder
                    {
                        public function run(): void {}
                    },
                };
            }
        };

        $seeder->setContainer($this->app)();

        $fixed = Enrollment::query()
            ->whereHas('user', fn ($query) => $query->where('email', 'student@certify-lms.test'))
            ->sole();
        $this->assertSame(2, $fixed->goals()->count());
        $this->assertSame(1, $fixed->goals()->whereNotNull('achieved_at')->count());

        $demoEnrollments = Enrollment::query()
            ->where('user_id', '!=', $manual->user_id)
            ->whereHas('user', fn ($query) => $query->whereNotIn('email', ['student@certify-lms.test', 'student-noquota@certify-lms.test']))
            ->withCount('goals')
            ->get();

        $this->assertTrue($demoEnrollments->isNotEmpty());
        $this->assertSame([], $demoEnrollments->where('goals_count', '!=', 1)->modelKeys());
        $this->assertCount(8, $demoEnrollments);
        $this->assertSame([$manual->id], $manual->user->enrollments()->pluck('id')->all());
        $this->assertSame([$existing->id], $manual->goals()->pluck('id')->all());
    }

    public function test_standalone_seeder_does_not_guess_demo_enrollments(): void
    {
        $nonDemo = Enrollment::factory()->create();

        $this->seed(EnrollmentGoalSeeder::class);

        $this->assertDatabaseMissing('enrollment_goals', ['enrollment_id' => $nonDemo->id]);
    }

    public function test_goal_seeder_can_run_again_without_overwriting_sample_edits_or_existing_goals(): void
    {
        $student = User::factory()->student()->create(['email' => 'student@certify-lms.test']);
        $certification = Certification::factory()->published()->create(['name' => '基本情報技術者試験']);
        $fixed = Enrollment::factory()->for($student)->for($certification)->create();
        $demo = Enrollment::factory()->passed()->create();
        $anotherEnrollment = Enrollment::factory()->for($demo->user)->create();
        $deleted = Enrollment::factory()->create();
        $deleted->delete();
        $existing = EnrollmentGoal::factory()->for($fixed)->create(['title' => '本人が作成した目標']);

        $this->seed(EnrollmentGoalSeeder::class);
        $this->assertDatabaseMissing('enrollment_goals', ['enrollment_id' => $demo->id]);
        $seeder = app(EnrollmentGoalSeeder::class);
        $demoIds = [$demo->id, $deleted->id];
        $seeder->run($demoIds);

        $pendingSample = $fixed->goals()->where('title', '過去問を5年分解く')->sole();
        $this->assertFalse($pendingSample->isAchieved());
        $this->assertTrue($fixed->goals()->where('title', '基礎分野の教材を一周する')->sole()->isAchieved());
        $this->assertTrue($demo->goals()->sole()->isAchieved());
        $pendingSample->update(['title' => '本人が編集したサンプル目標', 'target_date' => null]);

        $seeder->run($demoIds);

        $this->assertDatabaseCount('enrollment_goals', 4);
        $this->assertDatabaseMissing('enrollment_goals', ['enrollment_id' => $anotherEnrollment->id]);
        $this->assertDatabaseMissing('enrollment_goals', ['enrollment_id' => $deleted->id]);
        $this->assertSame('本人が作成した目標', $existing->fresh()->title);
        $this->assertSame('本人が編集したサンプル目標', $pendingSample->fresh()->title);
        $this->assertNull($pendingSample->fresh()->target_date);
    }
}
