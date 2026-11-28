<?php

declare(strict_types=1);

namespace Tests\Feature\Http\EnrollmentGoal;

use App\Enums\EnrollmentStatus;
use App\Enums\UserStatus;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_add_a_goal_from_the_enrollment_detail(): void
    {
        $enrollment = Enrollment::factory()->create();
        $url = '/enrollments/'.$enrollment->id.'/goals';
        $detail = route('enrollments.show', $enrollment);

        $this->actingAs($enrollment->user)->get($detail)->assertOk();
        $this->post($url, ['title' => '追加する学習目標'])
            ->assertRedirect($detail)
            ->assertSessionHas('success', '目標を追加しました。');
        $this->get($detail)->assertOk()->assertSeeText('追加する学習目標');
    }

    public function test_detail_orders_all_goals_and_student_index_reports_the_count(): void
    {
        $enrollment = Enrollment::factory()->create();
        $old = now()->subDays(2);
        $recent = now()->subDay();
        $goals = [
            ['未達同時刻ID大', '2027-01-01', null, $recent, '01H00000000000000000000002'],
            ['未達同時刻ID小', '2027-01-01', null, $recent, '01H00000000000000000000001'],
            ['未達同一期日古い', '2027-01-01', null, $old],
            ['未達後期期日', '2027-02-01', null, $recent],
            ['未達期日なし', null, null, $recent],
            ['達成早期期日', '2026-01-01', $old, $old],
            ['達成同一期日新しい', '2026-02-01', $old, $recent],
            ['達成同一期日古い', '2026-02-01', $recent, $old],
            ['達成期日なし', null, $recent, $recent],
        ];

        foreach (array_reverse($goals) as $fixture) {
            [$title, $date, $achieved, $created] = $fixture;
            EnrollmentGoal::factory()->for($enrollment)->create([
                'id' => $fixture[4] ?? (string) Str::ulid(),
                'title' => $title,
                'description' => $title === '未達期日なし' ? '0' : null,
                'target_date' => $date,
                'achieved_at' => $achieved,
                'created_at' => $created,
            ]);
        }

        EnrollmentGoal::factory()->for(Enrollment::factory()->for($enrollment->user))
            ->create(['title' => '同じ受講生の別受講登録の目標']);

        $this->actingAs($enrollment->user)
            ->get(route('enrollments.show', $enrollment))
            ->assertOk()
            ->assertSeeTextInOrder(array_column($goals, 0))
            ->assertSeeText('期日: 2027-01-01')
            ->assertSee('>0</div>', false)
            ->assertDontSeeText('同じ受講生の別受講登録の目標');

        $this->get(route('enrollments.index'))
            ->assertOk()
            ->assertViewHas('enrollments', fn ($rows) => $rows->firstWhere('id', $enrollment->id)->goals_count === count($goals));
    }

    public function test_empty_messages_staff_read_only_and_stopped_owner_hidden(): void
    {
        $enrollment = Enrollment::factory()->create();
        $coach = $this->assignCoach($enrollment);
        $admin = User::factory()->admin()->create();
        $detail = route('enrollments.show', $enrollment);

        $this->actingAs($enrollment->user)->get($detail)->assertOk()->assertSeeText('まだ目標が登録されていません。');
        $this->actingAs($coach)->get($detail)->assertOk()->assertSeeText('この受講生はまだ目標を登録していません。');
        $goal = EnrollmentGoal::factory()->for($enrollment)->create([
            'title' => '閲覧専用の目標',
            'description' => null,
            'target_date' => null,
        ]);

        foreach ([UserStatus::Graduated, UserStatus::Withdrawn, UserStatus::Invited] as $status) {
            $enrollment->user->update(['status' => $status]);
            $this->actingAs($enrollment->user)->get($detail)->assertOk()->assertDontSeeText('個人目標');
            foreach ([$coach, $admin] as $staff) {
                $this->actingAs($staff)->get($detail)->assertOk()
                    ->assertSeeText('閲覧専用の目標')
                    ->assertDontSeeText('期日:')
                    ->assertDontSeeText('達成:')
                    ->assertDontSee(route('enrollments.goals.store', $enrollment))
                    ->assertDontSee(route('enrollment-goals.edit', $goal))
                    ->assertDontSee(route('enrollment-goals.markAchieved', $goal))
                    ->assertDontSee(route('enrollment-goals.destroy', $goal));
            }
        }

        $enrollment->delete();
        $this->actingAs($admin)->get($detail)->assertOk()->assertDontSeeText('個人目標');
    }

    public function test_all_goal_routes_require_the_active_owner_and_an_existing_target(): void
    {
        $enrollment = Enrollment::factory()->create();
        $goal = EnrollmentGoal::factory()->for($enrollment)->create();
        $routes = $this->operations($enrollment->id, $goal->id);
        $before = $goal->fresh()->getAttributes();

        foreach ($routes as [$method, $url]) {
            $this->call($method, $url, ['title' => '認可テスト'])->assertRedirect(route('login'));
        }

        $otherStudent = User::factory()->student()->inProgress()->create();
        $outsideCoach = User::factory()->coach()->create();
        foreach ([$otherStudent, $this->assignCoach($enrollment), $outsideCoach, User::factory()->admin()->create()] as $user) {
            $this->actingAs($user);
            foreach ($routes as [$method, $url]) {
                $this->call($method, $url, ['title' => '認可テスト'])->assertForbidden();
            }
        }
        foreach ([$otherStudent, $outsideCoach] as $user) {
            $this->actingAs($user)->get(route('enrollments.show', $enrollment))->assertForbidden();
        }

        foreach ([UserStatus::Graduated, UserStatus::Withdrawn, UserStatus::Invited] as $status) {
            $enrollment->user->update(['status' => $status]);
            $this->actingAs($enrollment->user);
            foreach ($routes as [$method, $url]) {
                $this->call($method, $url, ['title' => '認可テスト'])->assertForbidden();
            }
        }

        $this->assertDatabaseCount('enrollment_goals', 1);
        $this->assertSame($before, $goal->fresh()->getAttributes());

        $enrollment->user->update(['status' => UserStatus::InProgress]);
        $this->actingAs($enrollment->user);
        foreach ([(string) Str::ulid(), 'invalid-id'] as $missing) {
            foreach ($this->operations($missing, $missing) as [$method, $url]) {
                $this->call($method, $url)->assertNotFound();
            }
        }

        $enrollment->delete();
        foreach ($routes as [$method, $url]) {
            $this->call($method, $url)->assertNotFound();
        }
    }

    public function test_owner_can_use_all_six_routes_in_each_enrollment_status(): void
    {
        foreach (EnrollmentStatus::cases() as $status) {
            $enrollment = Enrollment::factory()->create(['status' => $status]);
            $goal = EnrollmentGoal::factory()->for($enrollment)->create();
            $this->actingAs($enrollment->user);
            foreach ($this->operations($enrollment->id, $goal->id) as [$method, $url]) {
                $response = $this->call($method, $url, ['title' => '状態に関係なく保存']);
                if ($method === 'GET') {
                    $response->assertOk()->assertSeeText('目標を編集');
                } else {
                    $response->assertRedirect(route('enrollments.show', $enrollment))->assertSessionHas('success');
                }
            }
        }
    }

    public function test_dashboard_renders_own_goals_and_does_not_query_per_goal(): void
    {
        $enrollment = Enrollment::factory()->passed()->create();
        $first = EnrollmentGoal::factory()->for($enrollment)->create(['title' => 'ダッシュボードの本人目標']);
        EnrollmentGoal::factory()->create(['title' => 'ダッシュボードの他人目標']);
        $trashed = Enrollment::factory()->for($enrollment->user)->create();
        EnrollmentGoal::factory()->for($trashed)->create(['title' => '削除済み受講登録の目標']);
        $trashed->delete();

        $this->actingAs($enrollment->user);
        DB::enableQueryLog();
        try {
            $this->get(route('dashboard.index'))
                ->assertOk()
                ->assertSeeText($first->title)
                ->assertSee(route('enrollments.show', $enrollment))
                ->assertDontSeeText('ダッシュボードの他人目標')
                ->assertDontSeeText('削除済み受講登録の目標')
                ->assertDontSeeText('個人目標を取得できませんでした。');
            $baseline = count(DB::getQueryLog());

            EnrollmentGoal::factory()->for($enrollment)->count(12)->create();
            DB::flushQueryLog();
            $this->get(route('dashboard.index'))->assertOk()->assertSeeText($first->title);
            $this->assertLessThanOrEqual($baseline, count(DB::getQueryLog()));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    private function operations(string $enrollmentId, string $goalId): array
    {
        $goal = '/enrollment-goals/'.$goalId;

        return [
            ['POST', '/enrollments/'.$enrollmentId.'/goals'],
            ['GET', $goal.'/edit'],
            ['PATCH', $goal],
            ['POST', $goal.'/achieve'],
            ['DELETE', $goal.'/achieve'],
            ['DELETE', $goal],
        ];
    }

    private function assignCoach(Enrollment $enrollment): User
    {
        $coach = User::factory()->coach()->inProgress()->create();
        CertificationCoachAssignment::factory()->create([
            'certification_id' => $enrollment->certification_id,
            'user_id' => $coach->id,
            'unassigned_at' => null,
        ]);

        return $coach;
    }
}
