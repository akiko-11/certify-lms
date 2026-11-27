<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Http\Requests\MeetingPack\SaveRequest;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ValidationResponseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @dataProvider saveRouteProvider
     */
    public function test_invalid_form_recovers_with_safe_old_input(string $method, string $formRoute, string $saveRoute): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->create(['sort_order' => 87])->refresh();
        $before = $pack->getRawOriginal();
        $params = $method === 'PATCH' ? [$pack] : [];
        $form = route($formRoute, $params);

        $response = $this->actingAs($admin)->from($form)
            ->call($method, route($saveRoute, $params), [
                'name' => '修正中の面談パック',
                'description' => ['配列に含まれる値'],
                'meeting_count' => ['配列に含まれる値'],
                'price' => '-1',
                'stripe_price_id' => 'price_to_keep',
                'sort_order' => null,
                'status' => 'published',
                'password' => 'unrelated-value',
                'api_token' => 'unrelated-value',
                'updated_by_user_id' => 'unrelated-value',
            ]);

        $response->assertRedirect($form)->assertSessionMissing('_old_input');
        $oldInput = session(SaveRequest::formStateKey($params[0] ?? null).'.input');

        $formResponse = $this->get($form)
            ->assertOk()
            ->assertSee([
                '修正中の面談パック',
                'price_to_keep',
                '説明 は文字列で指定してください。',
                '面談回数 は整数で指定してください。',
                '価格 は 0 以上の値を指定してください。',
                'ステータスは送信できません。',
            ])
            ->assertDontSee(['配列に含まれる値', 'unrelated-value']);

        $this->assertDoesNotMatchRegularExpression('/name="sort_order"[^>]*\bvalue="/', $formResponse->getContent());
        $this->assertSame([
            'name' => '修正中の面談パック',
            'price' => '-1',
            'stripe_price_id' => 'price_to_keep',
            'sort_order' => null,
        ], $oldInput);

        $this->assertDatabaseCount('meeting_packs', 1);
        $this->assertSame($before, $pack->refresh()->getRawOriginal());
    }

    /**
     * @dataProvider unrelatedOldInputProvider
     */
    public function test_form_ignores_old_input_and_errors_from_another_feature(string $formRoute, mixed $unrelatedName): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->create(['name' => '編集対象の面談パック']);
        $categories = route('admin.certification-categories.index');
        $form = route($formRoute, $formRoute === 'admin.meeting-packs.edit' ? [$pack] : []);

        $this->actingAs($admin)->from($categories)
            ->post(route('admin.certification-categories.store'), ['name' => $unrelatedName])
            ->assertRedirect($categories)
            ->assertSessionHasErrors('name')
            ->assertSessionHas('_old_input', ['name' => $unrelatedName]);

        $response = $this->get($form)->assertOk()
            ->assertDontSee(['他機能の旧入力', '分類名 は']);

        if ($formRoute === 'admin.meeting-packs.edit') {
            $response->assertSee('value="編集対象の面談パック"', false);
        }
    }

    public static function unrelatedOldInputProvider(): array
    {
        $text = str_repeat('他機能の旧入力', 8);

        return [
            'create with string' => ['admin.meeting-packs.create', $text],
            'create with array' => ['admin.meeting-packs.create', [$text]],
            'edit with string' => ['admin.meeting-packs.edit', $text],
            'edit with array' => ['admin.meeting-packs.edit', [$text]],
        ];
    }

    /**
     * @dataProvider saveRouteProvider
     */
    public function test_form_state_is_specific_to_the_form_and_pack(string $method, string $formRoute, string $saveRoute): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->create();
        $otherPack = MeetingPack::factory()->create(['name' => '別の面談パックの保存済み名称']);
        $params = $method === 'PATCH' ? [$pack] : [];
        $form = route($formRoute, $params);

        $this->actingAs($admin)->from($form)
            ->call($method, route($saveRoute, $params), ['name' => '別フォームの送信値'])
            ->assertRedirect($form);

        $this->get(route('admin.meeting-packs.edit', $otherPack))
            ->assertOk()
            ->assertSee('value="別の面談パックの保存済み名称"', false)
            ->assertDontSee(['別フォームの送信値', '面談回数 を入力してください。']);
    }

    /**
     * @dataProvider saveRouteProvider
     */
    public function test_invalid_json_returns_localized_errors_without_flashing_input(string $method, string $formRoute, string $saveRoute): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->create()->refresh();
        $before = $pack->getRawOriginal();
        $params = $method === 'PATCH' ? [$pack] : [];

        $this->actingAs($admin)->json($method, route($saveRoute, $params), [
            'name' => '面談パック',
            'meeting_count' => true,
            'price' => 100.0,
            'status' => null,
        ], [], JSON_PRESERVE_ZERO_FRACTION)
            ->assertUnprocessable()
            ->assertJsonPath('errors.meeting_count.0', '面談回数 は整数で指定してください。')
            ->assertJsonPath('errors.price.0', '価格 は整数で指定してください。')
            ->assertJsonPath('errors.status.0', 'ステータスは送信できません。')
            ->assertSessionMissing('_old_input')
            ->assertSessionMissing(SaveRequest::formStateKey($params[0] ?? null));

        $this->assertDatabaseCount('meeting_packs', 1);
        $this->assertSame($before, $pack->refresh()->getRawOriginal());
    }

    /**
     * @dataProvider saveRouteProvider
     */
    public function test_form_only_restores_values_of_supported_types(string $method, string $formRoute, string $saveRoute): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->create();
        $params = $method === 'PATCH' ? [$pack] : [];
        $form = route($formRoute, $params);

        $this->actingAs($admin)->from($form)->json($method, route($saveRoute, $params), [
            'name' => 934869,
            'description' => '保持する説明',
            'meeting_count' => true,
            'price' => 0,
            'sort_order' => 432.0,
        ], ['Accept' => 'text/html'], JSON_PRESERVE_ZERO_FRACTION)
            ->assertRedirect($form)
            ->assertSessionHas(SaveRequest::formStateKey($params[0] ?? null).'.input', [
                'description' => '保持する説明',
                'price' => 0,
            ]);

        $this->get($form)->assertOk()
            ->assertSee('保持する説明')
            ->assertSee('value="0"', false)
            ->assertDontSee(['934869', 'value="432"'], false);
    }

    public static function saveRouteProvider(): array
    {
        return [
            'create' => ['POST', 'admin.meeting-packs.create', 'admin.meeting-packs.store'],
            'edit' => ['PATCH', 'admin.meeting-packs.edit', 'admin.meeting-packs.update'],
        ];
    }
}
