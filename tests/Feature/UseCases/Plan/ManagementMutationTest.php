<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Exceptions\Plan\PlanOperationConflictException;
use App\Models\Plan;
use App\Models\User;
use App\UseCases\Plan\DestroyAction;
use App\UseCases\Plan\TransitionAction;
use App\UseCases\Plan\UpdateAction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ManagementMutationTest extends TestCase
{
    use RefreshDatabase;

    public function test_transition_rejects_a_second_publish_using_a_stale_model(): void
    {
        $admin = User::factory()->admin()->create();
        $stale = Plan::factory()->draft()->create();
        $current = $stale->fresh();
        $transition = app(TransitionAction::class);
        $transition($admin, $current, PlanStatus::Published);

        $this->expectException(PlanOperationConflictException::class);

        $transition($admin, $stale, PlanStatus::Published);
    }

    public function test_update_uses_the_current_plan_when_an_old_model_has_different_values(): void
    {
        $admin = User::factory()->admin()->create();
        $stale = Plan::factory()->draft()->create(['name' => '初期値']);
        $stale->fresh()->update(['name' => '先行更新', 'status' => PlanStatus::Published]);

        $updated = app(UpdateAction::class)($admin, $stale, [
            'name' => '初期値',
            'duration_days' => 30,
            'default_meeting_quota' => 4,
        ]);

        $this->assertSame('初期値', $updated->fresh()->name);
        $this->assertSame(PlanStatus::Published, $updated->status);
    }

    public function test_update_does_not_recreate_a_deleted_plan(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create();
        app(DestroyAction::class)($plan);
        $this->expectException(ModelNotFoundException::class);

        app(UpdateAction::class)($admin, $plan, ['name' => '更新', 'duration_days' => 30, 'default_meeting_quota' => 0]);
    }

    public function test_destroy_checks_current_references_even_if_the_model_has_loaded_an_empty_relation(): void
    {
        $stale = Plan::factory()->draft()->create()->load('users');
        User::factory()->invited()->withPlan($stale)->create();

        $this->expectException(PlanOperationConflictException::class);
        $this->expectExceptionMessage('ユーザーが紐づいているプランは削除できません。');

        app(DestroyAction::class)($stale);
    }

    public function test_destroy_converts_a_plan_foreign_key_failure_and_rolls_back(): void
    {
        $plan = Plan::factory()->draft()->create();
        $student = User::factory()->student()->create();
        Event::listen('eloquent.deleting: '.Plan::class, static function (Plan $deleting) use ($student): void {
            User::whereKey($student->id)->update(['plan_id' => $deleting->id]);
        });

        try {
            app(DestroyAction::class)($plan);
            $this->fail('The plan must not be deleted while referenced.');
        } catch (PlanOperationConflictException $exception) {
            $this->assertSame('関連データが存在するため、プランを削除できません。', $exception->getMessage());
            $this->assertInstanceOf(QueryException::class, $exception->getPrevious());
        }

        $this->assertModelExists($plan);
        $this->assertNull($student->fresh()->plan_id);
    }

    public function test_destroy_does_not_convert_an_unrelated_database_failure(): void
    {
        $plan = Plan::factory()->draft()->create();
        Event::listen('eloquent.deleting: '.Plan::class, static function (): void {
            DB::select('SELECT * FROM missing_plan_test_table');
        });

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('missing_plan_test_table');

        app(DestroyAction::class)($plan);
    }
}
