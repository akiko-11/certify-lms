<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class IndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_meeting_pack_index(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();

        // Act
        $response = $this->actingAs($admin)
            ->get('/admin/meeting-packs');

        // Assert
        $response->assertOk();
        $response->assertViewIs('meeting-pack.management.index');
    }

    public function test_student_cannot_view_meeting_pack_index(): void
    {
        // Arrange
        $student = User::factory()->create([
            'role' => 'student',
        ]);

        // Act
        $response = $this->actingAs($student)
            ->get('/admin/meeting-packs');

        // Assert
        $response->assertForbidden();
    }

    public function test_coach_cannot_view_meeting_pack_index(): void
    {
        // Arrange
        $coach = User::factory()->create([
            'role' => 'coach',
        ]);

        // Act
        $response = $this->actingAs($coach)
            ->get('/admin/meeting-packs');

        // Assert
        $response->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        // Act
        $response = $this->get('/admin/meeting-packs');

        // Assert
        $response->assertRedirect('/login');
    }

    public function test_index_displays_all_statuses_without_filters(): void
    {
        $draft = MeetingPack::factory()->draft()->create();
        $published = MeetingPack::factory()->published()->create();
        $archived = MeetingPack::factory()->archived()->create();

        $response = $this->getIndex();

        $response->assertOk();

        $this->assertEqualsCanonicalizing(
            [$draft->id, $published->id, $archived->id],
            $response->viewData('plans')->pluck('id')->all(),
        );
    }

    public function test_index_filters_by_partial_name(): void
    {
        $matched = MeetingPack::factory()->create([
            'name' => '追加面談5回パック',
        ]);

        MeetingPack::factory()->create([
            'name' => '通常パック',
            'description' => '追加面談用',
        ]);

        $response = $this->getIndex([
            'keyword' => '追加面談',
        ]);

        $response->assertOk();

        $this->assertSame(
            [$matched->id],
            $response->viewData('plans')->pluck('id')->all(),
        );
    }

    /**
     * @dataProvider statusProvider
     */
    public function test_index_filters_by_status(string $status): void
    {
        $packs = [
            'draft' => MeetingPack::factory()->draft()->create(),
            'published' => MeetingPack::factory()->published()->create(),
            'archived' => MeetingPack::factory()->archived()->create(),
        ];

        $response = $this->getIndex([
            'status' => $status,
        ]);

        $response->assertOk();

        $this->assertSame(
            [$packs[$status]->id],
            $response->viewData('plans')->pluck('id')->all(),
        );
    }

    public static function statusProvider(): array
    {
        return [
            'draft' => ['draft'],
            'published' => ['published'],
            'archived' => ['archived'],
        ];
    }

    public function test_index_combines_keyword_and_status_filters(): void
    {
        $matched = MeetingPack::factory()->published()->create([
            'name' => '追加面談5回パック',
        ]);

        MeetingPack::factory()->draft()->create([
            'name' => '追加面談10回パック',
        ]);

        MeetingPack::factory()->published()->create([
            'name' => '通常パック',
        ]);

        $response = $this->getIndex([
            'keyword' => '追加面談',
            'status' => 'published',
        ]);

        $response->assertOk();

        $this->assertSame(
            [$matched->id],
            $response->viewData('plans')->pluck('id')->all(),
        );
    }

    public function test_index_displays_empty_state_when_no_pack_matches(): void
    {
        MeetingPack::factory()->create([
            'name' => '通常パック',
        ]);

        $response = $this->getIndex([
            'keyword' => '存在しないパック',
        ]);

        $response->assertOk();
        $response->assertSee('該当する面談パックがありません');

        $this->assertSame(
            0,
            $response->viewData('plans')->total(),
        );
    }

    public function test_index_displays_published_packs_first(): void
    {
        $draft = MeetingPack::factory()->draft()->create([
            'sort_order' => 1,
        ]);

        $archived = MeetingPack::factory()->archived()->create([
            'sort_order' => 2,
        ]);

        $publishedSecond = MeetingPack::factory()->published()->create([
            'sort_order' => 20,
        ]);

        $publishedFirst = MeetingPack::factory()->published()->create([
            'sort_order' => 10,
        ]);

        $response = $this->getIndex();

        $response->assertOk();

        $this->assertSame(
            [
                $publishedFirst->id,
                $publishedSecond->id,
                $draft->id,
                $archived->id,
            ],
            $response->viewData('plans')->pluck('id')->all(),
        );
    }

    public function test_index_sorts_by_sort_order_ascending(): void
    {
        $second = MeetingPack::factory()->create([
            'sort_order' => 20,
        ]);

        $first = MeetingPack::factory()->create([
            'sort_order' => 10,
        ]);

        $response = $this->getIndex();

        $response->assertOk();

        $this->assertSame(
            [$first->id, $second->id],
            $response->viewData('plans')->pluck('id')->all(),
        );
    }

    public function test_index_sorts_same_sort_order_by_created_at_descending(): void
    {
        $older = MeetingPack::factory()->create([
            'sort_order' => 10,
            'created_at' => now()->subDay(),
        ]);

        $newer = MeetingPack::factory()->create([
            'sort_order' => 10,
            'created_at' => now(),
        ]);

        $response = $this->getIndex();

        $response->assertOk();

        $this->assertSame(
            [$newer->id, $older->id],
            $response->viewData('plans')->pluck('id')->all(),
        );
    }

    public function test_index_uses_id_descending_as_tie_breaker(): void
    {
        $createdAt = now();

        $packs = MeetingPack::factory()->count(3)->create([
            'sort_order' => 10,
            'created_at' => $createdAt,
        ]);

        $expectedIds = $packs->pluck('id')
            ->sortDesc()
            ->values()
            ->all();

        $response = $this->getIndex();

        $response->assertOk();

        $this->assertSame(
            $expectedIds,
            $response->viewData('plans')->pluck('id')->all(),
        );
    }

    public function test_index_paginates_twenty_packs_per_page(): void
    {
        MeetingPack::factory()->count(21)->create();

        $response = $this->getIndex();

        $response->assertOk();

        $plans = $response->viewData('plans');

        $this->assertSame(20, $plans->perPage());
        $this->assertSame(20, $plans->count());
        $this->assertSame(21, $plans->total());
        $this->assertSame(1, $plans->currentPage());
        $this->assertSame(2, $plans->lastPage());
    }

    public function test_index_displays_remaining_packs_on_second_page(): void
    {
        foreach (range(1, 21) as $order) {
            $pack = MeetingPack::factory()->draft()->create([
                'sort_order' => $order,
            ]);
        }

        $response = $this->getIndex([
            'page' => 2,
        ]);

        $response->assertOk();

        $plans = $response->viewData('plans');

        $this->assertSame(2, $plans->currentPage());
        $this->assertSame(1, $plans->count());
        $this->assertSame(
            [$pack->id],
            $plans->pluck('id')->all(),
        );
    }

    public function test_index_preserves_filters_in_pagination_links(): void
    {
        MeetingPack::factory()->published()->count(21)->create([
            'name' => '追加面談パック',
        ]);

        $response = $this->getIndex([
            'keyword' => '追加面談',
            'status' => 'published',
            'unexpected_param' => 'unrelated-value',
        ]);

        $nextPageUrl = route('admin.meeting-packs.index', [
            'keyword' => '追加面談',
            'status' => 'published',
            'page' => 2,
        ]);

        $response->assertOk()
            ->assertSee('href="'.e($nextPageUrl).'"', false)
            ->assertDontSee('unexpected_param=', false);
    }

    private function getIndex(array $query = []): TestResponse
    {
        $admin = User::factory()->admin()->create();

        return $this->actingAs($admin)
            ->get(route('admin.meeting-packs.index', $query));
    }

    /**
     * @dataProvider pageBeyondLastProvider
     */
    public function test_index_displays_empty_state_when_page_exceeds_last_page(int $page): void
    {
        MeetingPack::factory()->count(21)->create();

        $response = $this->getIndex([
            'page' => $page,
        ]);

        $response->assertOk();
        $response->assertSee('該当する面談パックがありません');

        $plans = $response->viewData('plans');

        $this->assertSame($page, $plans->currentPage());
        $this->assertSame(2, $plans->lastPage());
        $this->assertSame(0, $plans->count());
        $this->assertSame(21, $plans->total());
    }

    public static function pageBeyondLastProvider(): array
    {
        return [
            'next page after last' => [3],
            'offset exceeds integer range' => [intdiv(PHP_INT_MAX, 10) + 1],
        ];
    }

    public function test_index_treats_percent_as_literal_character(): void
    {
        $matched = MeetingPack::factory()->create([
            'name' => '100%面談パック',
        ]);

        MeetingPack::factory()->create([
            'name' => '100回面談パック',
        ]);

        $response = $this->getIndex([
            'keyword' => '100%',
        ]);

        $response->assertOk();

        $this->assertSame(
            [$matched->id],
            $response->viewData('plans')->pluck('id')->all(),
        );
    }

    public function test_index_treats_underscore_as_literal_character(): void
    {
        $matched = MeetingPack::factory()->create([
            'name' => 'A_Bパック',
        ]);

        MeetingPack::factory()->create([
            'name' => 'A1Bパック',
        ]);

        $response = $this->getIndex([
            'keyword' => 'A_B',
        ]);

        $response->assertOk();

        $this->assertSame(
            [$matched->id],
            $response->viewData('plans')->pluck('id')->all(),
        );
    }

    public function test_index_treats_escape_character_as_literal_character(): void
    {
        $matched = MeetingPack::factory()->create([
            'name' => 'A!Bパック',
        ]);

        MeetingPack::factory()->create([
            'name' => 'ABパック',
        ]);

        $response = $this->getIndex([
            'keyword' => 'A!B',
        ]);

        $response->assertOk();

        $this->assertSame(
            [$matched->id],
            $response->viewData('plans')->pluck('id')->all(),
        );
    }

    public function test_index_preserves_filter_values_in_view(): void
    {
        $response = $this->getIndex([
            'keyword' => '追加面談',
            'status' => 'published',
        ]);

        $response->assertOk();
        $response->assertViewHas('keyword', '追加面談');
        $response->assertViewHas('status', 'published');
    }

    public function test_index_searches_name_case_insensitively(): void
    {
        $upper = MeetingPack::factory()->create([
            'name' => 'ABC面談パック',
        ]);

        $lower = MeetingPack::factory()->create([
            'name' => 'abc面談パック',
        ]);

        MeetingPack::factory()->create([
            'name' => 'XYZ面談パック',
        ]);

        $response = $this->getIndex([
            'keyword' => 'AbC',
        ]);

        $response->assertOk();

        $this->assertEqualsCanonicalizing(
            [$upper->id, $lower->id],
            $response->viewData('plans')->pluck('id')->all(),
        );
    }
}
