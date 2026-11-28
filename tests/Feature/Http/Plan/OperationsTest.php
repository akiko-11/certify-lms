<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Enums\PlanStatus;
use App\Models\Invitation;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserPlanLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_lifecycle_allows_the_three_transitions_with_existing_references(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create(['default_meeting_quota' => 0, 'updated_at' => now()->subDay()]);
        $user = User::factory()->invited()->withPlan($plan)->create();
        $invitation = Invitation::factory()->forUser($user)->pending()->create();
        $log = UserPlanLog::factory()->create(['plan_id' => $plan->id, 'user_id' => $user->id]);
        $user->refresh();
        $invitation->refresh();
        $log->refresh();
        $url = '/admin/plans/'.$plan->id;
        $this->actingAs($admin);

        foreach (['publish' => PlanStatus::Published, 'archive' => PlanStatus::Archived, 'unarchive' => PlanStatus::Draft] as $action => $status) {
            $this->post($url.'/'.$action)->assertRedirect($url)->assertSessionHas('success');
            $this->assertSame($status, $plan->fresh()->status);
            $this->assertSame($admin->id, $plan->fresh()->updated_by_user_id);
        }

        $this->assertTrue($plan->fresh()->updated_at->gt($plan->updated_at));
        $this->assertSame($user->getRawOriginal(), $user->fresh()->getRawOriginal());
        $this->assertSame($invitation->getRawOriginal(), $invitation->fresh()->getRawOriginal());
        $this->assertSame($log->getRawOriginal(), $log->fresh()->getRawOriginal());
        $this->assertDatabaseCount('user_plan_logs', 1);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_disallowed_transitions_and_repeated_operations_do_not_modify_data(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        foreach (['draft' => ['archive', 'unarchive'], 'published' => ['publish', 'unarchive'], 'archived' => ['publish', 'archive']] as $status => $actions) {
            $plan = Plan::factory()->create(['status' => $status])->refresh();
            $url = '/admin/plans/'.$plan->id;

            foreach ($actions as $action) {
                $this->from('/admin/plans')->post($url.'/'.$action)->assertRedirect($url)->assertSessionHas('error');
                $this->postJson($url.'/'.$action)->assertConflict();
            }
            $this->assertSame($plan->getRawOriginal(), $plan->fresh()->getRawOriginal());
        }
    }

    public function test_publish_validates_basic_information_and_hides_unavailable_button(): void
    {
        $plan = Plan::factory()->draft()->create(['duration_days' => 0, 'default_meeting_quota' => 1001]);
        $url = '/admin/plans/'.$plan->id;
        $this->actingAs(User::factory()->admin()->create())
            ->get($url)->assertOk()->assertDontSee($url.'/publish');
        $this->postJson($url.'/publish')->assertConflict();
        $this->post($url.'/publish')->assertRedirect($url)->assertSessionHas('error');
        $this->assertSame(PlanStatus::Draft, $plan->fresh()->status);
    }

    public function test_delete_uses_all_references_and_only_allows_unreferenced_drafts(): void
    {
        $available = Plan::factory()->draft()->create();
        $published = Plan::factory()->published()->create();
        $archived = Plan::factory()->archived()->create();
        $invited = Plan::factory()->draft()->create();
        $softDeleted = Plan::factory()->draft()->create();
        $pastPlan = Plan::factory()->draft()->create();
        $invitedUser = User::factory()->invited()->withPlan($invited)->create();
        $invitation = Invitation::factory()->forUser($invitedUser)->pending()->create();
        $deletedUser = User::factory()->inProgress()->withPlan($softDeleted)->create();
        $deletedUser->delete();
        $history = UserPlanLog::factory()->create(['plan_id' => $pastPlan->id]);
        $this->actingAs(User::factory()->admin()->create());

        foreach ([$published, $archived, $invited, $softDeleted, $pastPlan] as $blocked) {
            $blocked->refresh();
            $url = '/admin/plans/'.$blocked->id;
            $this->get($url)->assertOk()->assertDontSee('value="DELETE"', false);
            $this->from('/admin/plans')->delete($url)->assertRedirect($url)->assertSessionHas('error');
            $this->deleteJson($url)->assertConflict();
            $this->assertSame($blocked->getRawOriginal(), $blocked->fresh()->getRawOriginal());
        }

        $url = '/admin/plans/'.$available->id;
        $this->get($url)->assertOk()->assertSee('value="DELETE"', false);
        $this->delete($url)->assertRedirect('/admin/plans')->assertSessionHas('success');
        $this->assertModelMissing($available);
        $this->assertModelExists($invitedUser);
        $this->assertModelExists($invitation);
        $this->assertSoftDeleted($deletedUser);
        $this->assertModelExists($history);
        $this->deleteJson($url)->assertNotFound();
    }
}
