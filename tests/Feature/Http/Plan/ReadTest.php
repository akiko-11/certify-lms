<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Models\MeetingQuotaTransaction;
use App\Models\Plan;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_the_index(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/plans')
            ->assertOk()
            ->assertViewIs('plan.management.index');
    }

    public function test_all_routes_use_the_existing_admin_access_order(): void
    {
        $plan = Plan::factory()->create();
        $routes = [
            ['GET', ''],
            ['GET', '/create'],
            ['POST', ''],
            ['GET', '/{id}'],
            ['GET', '/{id}/edit'],
            ['PUT', '/{id}'],
            ['DELETE', '/{id}'],
            ['POST', '/{id}/publish'],
            ['POST', '/{id}/archive'],
            ['POST', '/{id}/unarchive'],
        ];

        foreach ($routes as [$method, $path]) {
            $url = '/admin/plans'.str_replace('{id}', $plan->id, $path);
            $this->call($method, $url)->assertRedirect('/login');
            $this->json($method, $url)->assertUnauthorized();
        }

        foreach ([User::factory()->student()->create(), User::factory()->coach()->create()] as $user) {
            $this->actingAs($user)->get('/admin/plans/'.$plan->id)->assertForbidden();

            foreach ($routes as [$method, $path]) {
                $this->json($method, '/admin/plans'.str_replace('{id}', $plan->id, $path))->assertForbidden();

                if (str_contains($path, '{id}')) {
                    $this->json($method, '/admin/plans'.str_replace('{id}', 'missing', $path))->assertNotFound();
                }
            }
        }
    }

    public function test_index_searches_name_case_insensitively_with_literal_wildcards_and_state(): void
    {
        $admin = User::factory()->admin()->create();
        $match = Plan::factory()->published()->create(['name' => 'Intro AB%_! program']);
        Plan::factory()->draft()->create(['name' => 'AB%_! draft']);
        Plan::factory()->published()->create(['name' => 'different', 'description' => 'AB%_!']);
        Plan::factory()->published()->create(['name' => 'ABzzx!']);

        $response = $this->actingAs($admin)->get('/admin/plans?'.http_build_query([
            'keyword' => '　ab%_! ',
            'status' => 'published',
        ]));

        $response->assertOk();
        $this->assertSame([$match->id], $response->viewData('plans')->modelKeys());
        $this->assertSame('ab%_!', $response->viewData('keyword'));
    }

    public function test_index_prioritizes_published_and_then_sorts_by_order_created_at_and_id(): void
    {
        $admin = User::factory()->admin()->create();
        $date = now()->subDay();
        $rows = [
            Plan::factory()->archived()->create(['sort_order' => 3, 'created_at' => $date]),
            Plan::factory()->draft()->create(['sort_order' => 2, 'created_at' => $date]),
            Plan::factory()->published()->create(['sort_order' => 99, 'created_at' => $date]),
            Plan::factory()->archived()->create(['sort_order' => 2, 'created_at' => $date]),
            Plan::factory()->draft()->create(['sort_order' => 2, 'created_at' => now()]),
        ];

        $response = $this->actingAs($admin)->get('/admin/plans?keyword=%20%20')->assertOk();
        $this->assertSame(
            [$rows[2]->id, $rows[4]->id, max($rows[1]->id, $rows[3]->id), min($rows[1]->id, $rows[3]->id), $rows[0]->id],
            $response->viewData('plans')->modelKeys(),
        );
    }

    public function test_pagination_retains_only_valid_filters_and_redirects_huge_pages(): void
    {
        $admin = User::factory()->admin()->create();
        Plan::factory()->published()->count(21)->create([
            'name' => 'Alpha',
            'created_by_user_id' => $admin->id,
        ]);
        Plan::factory()->draft()->create(['name' => 'Alpha']);
        $url = '/admin/plans?keyword=Alpha&status=published';

        $first = $this->actingAs($admin)->get($url.'&extra=unwanted')->assertOk();
        $this->assertSame(21, $first->viewData('plans')->total());
        $this->assertCount(20, $first->viewData('plans'));
        $first->assertSee('21 件')->assertSee($url.'&page=2')->assertDontSee('extra=unwanted');

        $this->get($url.'&page='.str_repeat('9', 35))->assertRedirect($url.'&page=2');
        $second = $this->get($url.'&page=2')->assertOk();
        $this->assertCount(1, $second->viewData('plans'));

        $this->get('/admin/plans?keyword=absent&page=3')->assertRedirect('/admin/plans?keyword=absent&page=1');
        $empty = $this->get('/admin/plans?keyword=absent&page=1')->assertOk();
        $empty->assertSee('該当するプランがありません')->assertDontSee('page=2');
        $this->assertSame(1, $empty->viewData('plans')->currentPage());
    }

    public function test_invalid_search_resets_all_filters_and_returns_localized_json_errors(): void
    {
        $admin = User::factory()->admin()->create();
        $url = '/admin/plans?keyword=valid&status%5B0%5D=draft&page=0';
        $this->actingAs($admin)->get($url)->assertRedirect('/admin/plans')->assertSessionHas('error');
        $this->get('/admin/plans')->assertOk()->assertSee('ステータス')->assertSee('ページ番号');
        $this->getJson($url)->assertUnprocessable()->assertJsonValidationErrors(['status', 'page']);
        $this->getJson('/admin/plans?keyword='.str_repeat('a', 100))->assertOk();
        $this->getJson('/admin/plans?keyword='.str_repeat('a', 101))->assertUnprocessable()
            ->assertJsonValidationErrors('keyword')
            ->assertJsonPath('errors.keyword.0', '検索キーワード は 100 文字以内で入力してください。');

        $pageErrors = [
            ['', 'ページ番号 を入力してください。'],
            ['0', 'ページ番号は1以上で指定してください。'],
            ['-1', 'ページ番号は整数で指定してください。'],
            ['1.5', 'ページ番号は整数で指定してください。'],
            ['1e2', 'ページ番号は整数で指定してください。'],
            ['%2B1', 'ページ番号は整数で指定してください。'],
        ];
        foreach ($pageErrors as [$badPage, $message]) {
            $this->getJson('/admin/plans?page='.$badPage)->assertUnprocessable()
                ->assertJsonValidationErrors('page')
                ->assertJsonPath('errors.page.0', $message);
        }
    }

    public function test_invalid_status_displays_japanese_in_html_and_json(): void
    {
        $url = '/admin/plans?status=invalid';
        $message = '有効なステータスを選択してください。';
        $this->actingAs(User::factory()->admin()->create());

        $this->get($url)->assertRedirect('/admin/plans')->assertSessionHas('error', $message);
        $this->get('/admin/plans')->assertOk()->assertSee($message);
        $this->getJson($url)->assertUnprocessable()
            ->assertJsonPath('errors.status.0', $message)
            ->assertJsonPath('message', $message);
    }

    public function test_multiple_search_errors_and_the_json_summary_are_in_japanese(): void
    {
        $url = '/admin/plans?keyword%5B%5D=x&status%5B%5D=draft&page=0';
        $messages = [
            'keyword' => '検索キーワード は文字列で指定してください。',
            'status' => 'ステータス は文字列で指定してください。',
            'page' => 'ページ番号は1以上で指定してください。',
        ];
        $this->actingAs(User::factory()->admin()->create());

        $this->get($url)->assertRedirect('/admin/plans');
        $html = $this->get('/admin/plans')->assertOk();
        $json = $this->getJson($url)->assertUnprocessable();

        foreach ($messages as $field => $message) {
            $html->assertSee($message);
            $json->assertJsonPath('errors.'.$field, [$message]);
        }

        $json->assertJsonPath('message', $messages['keyword'].'（ほか2件のエラー）');
    }

    public function test_detail_and_index_share_student_conditions_and_detail_queries_quota_once(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->archived()->create();
        $start = now()->subDays(2);
        $first = User::factory()->student()->withPlan($plan)->inProgress()->create([
            'name' => 'Active First',
            'plan_started_at' => $start,
            'plan_expires_at' => now()->subDay(),
            'max_meetings' => 4,
        ]);
        $sameStart = User::factory()->student()->withPlan($plan)->inProgress()->create([
            'name' => 'Active Tie',
            'plan_started_at' => $start,
        ]);
        $nullStart = User::factory()->student()->withPlan($plan)->inProgress()->create([
            'name' => 'Active Null',
            'plan_started_at' => null,
        ]);
        $latest = User::factory()->student()->withPlan($plan)->inProgress()->create([
            'name' => 'Active Latest',
            'plan_started_at' => now(),
        ]);
        foreach (['invited', 'graduated', 'withdrawn'] as $status) {
            User::factory()->student()->withPlan($plan)->create(['name' => 'Excluded '.$status, 'status' => $status]);
        }
        User::factory()->coach()->withPlan($plan)->inProgress()->create(['name' => 'Excluded coach']);
        User::factory()->student()->withPlan($plan)->inProgress()->create(['name' => 'Excluded deleted'])->delete();
        MeetingQuotaTransaction::factory()->grantedInitial(4)->create(['user_id' => $first->id]);
        MeetingQuotaTransaction::factory()->consumed()->create(['user_id' => $first->id]);
        MeetingQuotaTransaction::factory()->purchased(5)->create(['user_id' => $first->id]);

        $index = $this->actingAs($admin)->get('/admin/plans')->assertOk();
        $this->assertSame(4, $index->viewData('plans')->first()->users_count);

        DB::enableQueryLog();
        $detail = $this->get('/admin/plans/'.$plan->id)->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $tie = collect([$first, $sameStart])->sortByDesc('id')->pluck('name')->all();
        $detail->assertSeeInOrder([$latest->name, ...$tie, $nullStart->name])->assertDontSee('Excluded');
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$detail->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);
        $this->assertSame('8', $xpath->evaluate('normalize-space(//tr[td//a[normalize-space(.)="Active First"]]/td[last()])'));
        $this->assertSame(8, $detail->viewData('remainingQuotas')[$first->id]);
        $this->assertSame(4, $first->fresh()->max_meetings);
        $this->assertCount(1, array_filter($queries, fn (array $entry): bool => str_contains($entry['query'], 'meeting_quota_transactions')));
    }
}
