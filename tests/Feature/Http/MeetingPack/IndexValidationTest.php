<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Http\Requests\MeetingPack\IndexRequest;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndexValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_keyword_with_100_characters_is_accepted(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(
            route('admin.meeting-packs.index', [
                'keyword' => str_repeat('あ', 100),
            ]),
        );

        $response->assertOk();
        $response->assertSessionHasNoErrors();
    }

    /**
     * @dataProvider invalidFilterProvider
     */
    public function test_invalid_filter_is_rejected(
        array $query,
        string $field,
    ): void {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->from(route('admin.meeting-packs.index'))
            ->get(route('admin.meeting-packs.index', $query));

        $response->assertRedirect(
            route('admin.meeting-packs.index'),
        );

        $response->assertSessionHasErrors($field, errorBag: IndexRequest::ERROR_BAG);
    }

    public static function invalidFilterProvider(): array
    {
        return [
            'keyword exceeds 100 characters' => [
                ['keyword' => str_repeat('あ', 101)],
                'keyword',
            ],
            'keyword is array' => [
                ['keyword' => ['test']],
                'keyword',
            ],
            'invalid status' => [
                ['status' => 'invalid'],
                'status',
            ],
            'status is array' => [
                ['status' => ['draft']],
                'status',
            ],
            'page is zero' => [
                ['page' => 0],
                'page',
            ],
            'page is negative' => [
                ['page' => -1],
                'page',
            ],
            'page is string' => [
                ['page' => 'abc'],
                'page',
            ],
            'page is decimal' => [
                ['page' => '1.5'],
                'page',
            ],
            'page is array' => [
                ['page' => [1]],
                'page',
            ],
        ];
    }

    public function test_html_search_error_returns_to_list_and_keeps_safe_filters(): void
    {
        $admin = User::factory()->admin()->create();
        $draft = MeetingPack::factory()->draft()->create(['name' => '検索中のパック']);
        MeetingPack::factory()->published()->create(['name' => '検索中のパック']);
        $validFilters = ['keyword' => '検索中', 'status' => 'draft'];
        $invalidUrl = route('admin.meeting-packs.index', $validFilters + ['page' => ['不正な配列'], 'api_token' => 'unrelated-value']);
        $index = route('admin.meeting-packs.index', $validFilters);

        $response = $this->actingAs($admin)->from($invalidUrl)->get($invalidUrl);
        $response->assertRedirect($index)
            ->assertSessionHas('_old_input', $validFilters)
            ->assertSessionHasErrors('page', errorBag: IndexRequest::ERROR_BAG);

        $response = $this->get($index);
        $response->assertOk()->assertSee(['検索中', 'ページ番号 は整数で指定してください。'])
            ->assertDontSee(['不正な配列', 'unrelated-value']);
        $this->assertSame([$draft->id], $response->viewData('plans')->pluck('id')->all());
    }

    public function test_html_search_errors_render_once_in_their_respective_locations(): void
    {
        $admin = User::factory()->admin()->create();
        $index = route('admin.meeting-packs.index');
        $keyword = str_repeat('あ', 101);
        $keywordError = '検索キーワード は 100 文字以内で入力してください。';
        $statusError = 'ステータス は文字列で指定してください。';
        $pageError = 'ページ番号 は 1 以上の値を指定してください。';

        $redirect = $this->actingAs($admin)->from($index)->get(route('admin.meeting-packs.index', [
            'keyword' => $keyword,
            'status' => ['不正な配列'],
            'page' => 0,
            'api_token' => 'unrelated-value',
        ]));
        $redirect->assertRedirect($index)->assertSessionHas('_old_input', ['keyword' => $keyword]);
        $notification = session('error');

        $response = $this->get($index)->assertOk()
            ->assertSee('aria-describedby="keyword-error"', false)
            ->assertSee('aria-describedby="status-error"', false)
            ->assertSee('id="keyword-error"', false)
            ->assertSee('id="status-error"', false)
            ->assertSee('value="'.$keyword.'"', false)
            ->assertDontSee(['不正な配列', 'unrelated-value']);

        $this->assertSame(2, substr_count($response->getContent(), 'aria-invalid="true"'));
        $this->assertSame($pageError, $notification);
        foreach ([$keywordError, $statusError, $pageError] as $message) {
            $this->assertSame(1, substr_count($response->getContent(), $message), $message);
        }
    }

    /**
     * @dataProvider keywordErrorSequenceProvider
     */
    public function test_html_search_does_not_restore_keyword_from_user_management_errors(bool $indexErrorPending): void
    {
        $admin = User::factory()->admin()->create();
        $index = route('admin.meeting-packs.index');
        $users = route('admin.users.index');
        $keyword = str_repeat('a', 101);

        $this->actingAs($admin);

        if ($indexErrorPending) {
            $this->get(route('admin.meeting-packs.index', ['keyword' => str_repeat('b', 101)]))
                ->assertRedirect($index)
                ->assertSessionHasErrors('keyword', errorBag: IndexRequest::ERROR_BAG);
        }

        $this->from($users)
            ->get(route('admin.users.index', ['keyword' => $keyword]))
            ->assertRedirect($users)
            ->assertSessionHasErrors('keyword')
            ->assertSessionHas('_old_input', ['keyword' => $keyword]);

        $response = $this->get($index)
            ->assertOk()
            ->assertDontSee($keyword);

        if (! $indexErrorPending) {
            $response->assertDontSee('id="keyword-error"', false);
        }
    }

    public static function keywordErrorSequenceProvider(): array
    {
        return [
            'user management only' => [false],
            'user management error after meeting pack error' => [true],
        ];
    }

    public function test_json_search_errors_are_localized_and_do_not_flash_input(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->getJson(route('admin.meeting-packs.index', [
            'keyword' => ['invalid'],
            'status' => 'invalid',
            'page' => 0,
        ]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.keyword.0', '検索キーワード は文字列で指定してください。')
            ->assertJsonPath('errors.status.0', '選択された ステータス は無効です。')
            ->assertJsonPath('errors.page.0', 'ページ番号 は 1 以上の値を指定してください。')
            ->assertSessionMissing('_old_input');
    }
}
