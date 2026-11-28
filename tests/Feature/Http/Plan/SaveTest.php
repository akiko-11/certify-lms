<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Enums\PlanStatus;
use App\Models\MeetingQuotaTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserPlanLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SaveTest extends TestCase
{
    use RefreshDatabase;

    private const VALID = [
        'name' => 'プラン',
        'description' => '説明',
        'duration_days' => 30,
        'default_meeting_quota' => 4,
        'sort_order' => 1,
    ];

    public function test_create_uses_draft_and_accepts_zero_quota_and_duplicate_names(): void
    {
        $admin = User::factory()->admin()->create();
        $name = str_repeat('名', 100);
        $existing = Plan::factory()->create(['name' => $name]);
        $this->actingAs($admin)->get('/admin/plans/create')->assertOk()->assertSee('value="30"', false)->assertSee('value="4"', false);

        $response = $this->post('/admin/plans', [
            'name' => '　'.$name.' ',
            'description' => '　 ',
            'duration_days' => '1',
            'default_meeting_quota' => '0',
        ]);
        $created = Plan::whereKeyNot($existing->id)->sole();

        $response->assertRedirect('/admin/plans/'.$created->id)->assertSessionHas('success');
        $this->assertSame($name, $created->name);
        $this->assertNull($created->description);
        $this->assertSame(0, $created->sort_order);
        $this->assertSame(1, $created->duration_days);
        $this->assertSame(0, $created->default_meeting_quota);
        $this->assertSame(PlanStatus::Draft, $created->status);
        $this->assertSame($admin->id, $created->created_by_user_id);
        $this->assertSame($admin->id, $created->updated_by_user_id);
        $this->assertNotNull($created->created_at);
        $this->assertNotNull($created->updated_at);
    }

    #[DataProvider('statuses')]
    public function test_update_replaces_basic_information_and_preserves_contract_and_history(PlanStatus $status): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create(['status' => $status, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $user = User::factory()->inProgress()->withPlan($plan)->create();
        $log = UserPlanLog::factory()->create(['plan_id' => $plan->id, 'user_id' => $user->id]);
        $quota = MeetingQuotaTransaction::factory()->grantedInitial(4)->create(['user_id' => $user->id]);
        $user->refresh();
        $log->refresh();
        $quota->refresh();
        $url = '/admin/plans/'.$plan->id;

        $this->actingAs($admin)->get($url.'/edit')->assertOk()->assertSee($plan->name);
        $this->put($url, ['name' => '新名称', 'duration_days' => 3650, 'default_meeting_quota' => 1000])
            ->assertRedirect($url)
            ->assertSessionHas('success');

        $updated = $plan->fresh();
        $this->assertSame('新名称', $updated->name);
        $this->assertNull($updated->description);
        $this->assertSame(0, $updated->sort_order);
        $this->assertSame(3650, $updated->duration_days);
        $this->assertSame(1000, $updated->default_meeting_quota);
        $this->assertSame($status, $updated->status);
        $this->assertSame($plan->created_by_user_id, $updated->created_by_user_id);
        $this->assertTrue($plan->created_at->eq($updated->created_at));
        $this->assertTrue($updated->updated_at->gt($plan->updated_at));
        $this->assertSame($admin->id, $updated->updated_by_user_id);
        $this->assertSame($user->getRawOriginal(), $user->fresh()->getRawOriginal());
        $this->assertSame($log->getRawOriginal(), $log->fresh()->getRawOriginal());
        $this->assertSame($quota->getRawOriginal(), $quota->fresh()->getRawOriginal());
        $this->assertDatabaseCount('user_plan_logs', 1);
        $this->assertDatabaseCount('meeting_quota_transactions', 1);
    }

    public static function statuses(): array
    {
        return array_map(fn (PlanStatus $status): array => [$status], PlanStatus::cases());
    }

    public function test_save_validates_system_fields_even_if_empty_and_reports_required_fields(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create();
        $plan->refresh();
        $fields = ['id', 'status', 'created_by_user_id', 'updated_by_user_id', 'created_at', 'updated_at'];
        $this->actingAs($admin);

        foreach ([null, ''] as $value) {
            $data = ['name' => '　'] + array_fill_keys($fields, $value);
            $expected = ['name', 'duration_days', 'default_meeting_quota', ...$fields];
            $this->postJson('/admin/plans', $data)->assertUnprocessable()->assertJsonValidationErrors($expected);
            $this->putJson('/admin/plans/'.$plan->id, $data)->assertUnprocessable()->assertJsonValidationErrors($expected);
        }
        $this->assertDatabaseCount('plans', 1);
        $this->assertSame($plan->getRawOriginal(), $plan->fresh()->getRawOriginal());
    }

    public function test_save_checks_numeric_types_and_bounds_and_accepts_unsigned_int_max(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $invalid = [
            'name' => str_repeat('x', 101),
            'description' => str_repeat('x', 2001),
            'duration_days' => 3651,
            'default_meeting_quota' => -1,
            'sort_order' => 4294967296,
        ];

        $this->postJson('/admin/plans', $invalid)->assertUnprocessable()->assertJsonValidationErrors(array_keys($invalid));
        $this->postJson('/admin/plans', ['duration_days' => 30.5, 'default_meeting_quota' => true, 'sort_order' => '1.5'] + self::VALID)
            ->assertUnprocessable()->assertJsonValidationErrors(['duration_days', 'default_meeting_quota', 'sort_order']);
        $this->postJson('/admin/plans', ['duration_days' => 0, 'default_meeting_quota' => 1001, 'sort_order' => -1] + self::VALID)
            ->assertUnprocessable()->assertJsonValidationErrors(['duration_days', 'default_meeting_quota', 'sort_order']);
        $this->assertDatabaseCount('plans', 0);

        $this->post('/admin/plans', ['description' => str_repeat('説', 2000), 'sort_order' => '4294967295'] + self::VALID)
            ->assertSessionHasNoErrors();
        $this->assertSame(4294967295, Plan::sole()->sort_order);
        $this->assertSame(2000, mb_strlen(Plan::sole()->description));
    }

    public function test_invalid_create_preserves_safe_fields_and_displays_each_error_without_500(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/admin/plans', [
                'name' => '安全な入力',
                'description' => ['不正な説明'],
                'duration_days' => '4000',
                'default_meeting_quota' => ['不正な回数'],
                'status' => 'published',
                'password' => 'password-should-not-be-restored',
            ])
            ->assertRedirect('/admin/plans/create')
            ->assertSessionHas('error');

        $this->get('/admin/plans/create')->assertOk()
            ->assertSee('安全な入力')
            ->assertSee('value="4000"', false)
            ->assertSee('id="description-error"', false)
            ->assertSee('id="duration_days-error"', false)
            ->assertSee('id="default_meeting_quota-error"', false)
            ->assertSee('許可されていない項目')
            ->assertDontSee('不正な説明')
            ->assertDontSee('password-should-not-be-restored');
        $this->assertDatabaseCount('plans', 0);
    }

    public function test_create_and_edit_localize_field_errors_and_the_json_summary(): void
    {
        $plan = Plan::factory()->create();
        $this->actingAs(User::factory()->admin()->create());
        $payload = [
            'name' => '　',
            'description' => str_repeat('説', 2001),
            'duration_days' => 3651,
            'default_meeting_quota' => ['4'],
            'sort_order' => '1.5',
            'status' => null,
        ];
        $messages = [
            'name' => 'プラン名 を入力してください。',
            'description' => '説明 は 2000 文字以内で入力してください。',
            'duration_days' => '受講期間 は 1 から 3650 の間で指定してください。',
            'default_meeting_quota' => '初期付与面談回数 は整数で指定してください。',
            'sort_order' => '並び順 は整数で指定してください。',
            'status' => '許可されていない項目が送信されています。',
        ];
        $planUrl = '/admin/plans/'.$plan->id;

        foreach ([['POST', '/admin/plans', '/admin/plans/create'], ['PUT', $planUrl, $planUrl.'/edit']] as [$method, $url, $form]) {
            $this->call($method, $url, $payload)->assertRedirect($form);
            $html = $this->get($form)->assertOk();
            $json = $this->json($method, $url, $payload)->assertUnprocessable();

            foreach ($messages as $field => $message) {
                $html->assertSee($message);
                $json->assertJsonPath('errors.'.$field, [$message]);
            }

            $json->assertJsonPath('message', $messages['name'].'（ほか5件のエラー）');
        }
    }

    public function test_forms_do_not_see_old_input_or_errors_from_other_forms_or_features(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $first = Plan::factory()->create(['name' => '編集対象1']);
        $second = Plan::factory()->create(['name' => '編集対象2']);
        $firstUrl = '/admin/plans/'.$first->id;
        $secondUrl = '/admin/plans/'.$second->id;

        $this->post('/admin/plans', ['name' => '作成専用'])->assertRedirect('/admin/plans/create');
        $this->get($firstUrl.'/edit')->assertOk()->assertSee($first->name)->assertDontSee('作成専用');
        $this->put($firstUrl, ['name' => '編集1専用'])->assertRedirect($firstUrl.'/edit');
        $this->get($secondUrl.'/edit')->assertOk()->assertSee($second->name)->assertDontSee('編集1専用');
        $this->put($firstUrl, ['name' => '編集1専用'])->assertRedirect($firstUrl.'/edit');
        $this->get('/admin/plans/create')->assertOk()->assertDontSee('編集1専用');

        foreach (['/admin/plans/create', $firstUrl.'/edit'] as $url) {
            $this->withSession([
                '_old_input' => ['name' => ['別機能の入力']],
                'errors' => (new ViewErrorBag)->put('default', new MessageBag(['name' => '別機能のエラー'])),
            ])->get($url)->assertOk()->assertDontSee('別機能');
        }
    }
}
