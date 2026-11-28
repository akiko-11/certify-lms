<?php

declare(strict_types=1);

namespace Tests\Feature\Http\EnrollmentGoal;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class SaveTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_normalizes_text_and_supports_boundaries_and_duplicate_titles(): void
    {
        $enrollment = Enrollment::factory()->create();
        $this->actingAs($enrollment->user);
        $url = route('enrollments.goals.store', $enrollment);
        $detail = route('enrollments.show', $enrollment);
        $title = str_repeat('目', 100);
        $description = str_repeat('学', 500)."\n".str_repeat('習', 499);

        $this->post($url, ['title' => "　 {$title}\t", 'description' => "　{$description}  "])
            ->assertRedirect($detail)->assertSessionHas('success', '目標を追加しました。');
        $this->assertDatabaseHas('enrollment_goals', [
            'enrollment_id' => $enrollment->id,
            'title' => $title,
            'description' => $description,
            'target_date' => null,
            'achieved_at' => null,
        ]);

        $dates = ['1000-01-01', '9999-12-31', '2024-02-29', today()->toDateString()];
        foreach ($dates as $date) {
            $this->post($url, ['title' => '目', 'description' => ' 　', 'target_date' => $date])->assertRedirect($detail);
            $this->assertDatabaseHas('enrollment_goals', ['title' => '目', 'description' => null, 'target_date' => $date]);
        }

        $this->assertDatabaseCount('enrollment_goals', 5);
        $this->get($detail)->assertOk()->assertDontSee('value="'.$title.'"', false);
    }

    public function test_invalid_input_returns_localized_json_and_does_not_write(): void
    {
        $enrollment = Enrollment::factory()->create();
        $this->actingAs($enrollment->user);
        $url = route('enrollments.goals.store', $enrollment);

        $this->postJson($url, [
            'title' => '  　',
            'description' => str_repeat('詳', 1001),
            'target_date' => '2025-02-29',
            'unknown' => null,
        ])->assertUnprocessable()->assertJsonPath('message', '目標を入力してください。（ほか3件のエラー）')
            ->assertJsonPath('errors.title.0', '目標を入力してください。')
            ->assertJsonPath('errors.description.0', '詳細は1000文字以内で入力してください。')
            ->assertJsonPath('errors.target_date.0', '目標期日はYYYY-MM-DD形式の有効な日付で入力してください。')
            ->assertJsonPath('errors._input.0', '変更できない項目が指定されています。');

        foreach (['2026-2-01', '2026/02/01', '2026-02-01T00:00:00', '0999-12-31', '10000-01-01', ['2026-01-01'], 20260101, false] as $invalidDate) {
            $this->postJson($url, ['title' => '目標', 'target_date' => $invalidDate])
                ->assertUnprocessable()
                ->assertJsonPath('errors.target_date.0', '目標期日はYYYY-MM-DD形式の有効な日付で入力してください。');
        }

        foreach ([
            [[], 'title', '目標を入力してください。'],
            [['title' => null], 'title', '目標を入力してください。'],
            [['title' => str_repeat('長', 101)], 'title', '目標は100文字以内で入力してください。'],
            [['title' => ['不正']], 'title', '目標は文字列で入力してください。'],
            [['title' => 99], 'title', '目標は文字列で入力してください。'],
            [['title' => false], 'title', '目標は文字列で入力してください。'],
            [['title' => '目標', 'description' => ['不正']], 'description', '詳細は文字列で入力してください。'],
            [['title' => '目標', 'description' => 1], 'description', '詳細は文字列で入力してください。'],
        ] as [$payload, $field, $message]) {
            $this->postJson($url, $payload)->assertUnprocessable()->assertJsonPath('errors.'.$field.'.0', $message);
        }

        foreach (['enrollment_id', 'id', 'achieved_at', 'created_at', 'updated_at', 'unknown', 'password'] as $forbidden) {
            foreach ([null, '', '入力禁止'] as $value) {
                $this->postJson($url, ['title' => '目標', $forbidden => $value])->assertUnprocessable()
                    ->assertJsonPath('errors._input.0', '変更できない項目が指定されています。');
            }
        }

        $this->assertDatabaseCount('enrollment_goals', 0);
    }

    public function test_patch_keeps_omitted_values_and_clears_explicit_empty_values_without_moving_or_unmarking(): void
    {
        $goal = EnrollmentGoal::factory()->achieved()->create(['description' => '元の詳細', 'target_date' => '2028-04-10']);
        $before = $goal->fresh();
        $enrollment = $goal->enrollment;
        $this->actingAs($enrollment->user);
        $url = route('enrollment-goals.update', $goal);
        $detail = route('enrollments.show', $enrollment);
        $another = Enrollment::factory()->for($enrollment->user)->create();

        $this->patchJson($url, ['title' => '移動を試行', 'enrollment_id' => $another->id])
            ->assertUnprocessable()->assertJsonPath('errors._input.0', '変更できない項目が指定されています。');
        $this->assertSame($before->getAttributes(), $goal->fresh()->getAttributes());

        $this->patch($url, ['title' => '更新タイトル'])->assertRedirect($detail)->assertSessionHas('success', '目標を更新しました。');
        $this->assertSame('元の詳細', $goal->fresh()->description);
        $this->assertSame('2028-04-10', $goal->fresh()->target_date->toDateString());

        $this->patch($url, ['title' => '更新タイトル', 'description' => '', 'target_date' => null])->assertRedirect($detail);
        $updated = $goal->fresh();
        $this->assertNull($updated->description);
        $this->assertNull($updated->target_date);
        $this->assertEquals($before->achieved_at, $updated->achieved_at);
        $this->assertSame($before->enrollment_id, $updated->enrollment_id);
    }

    public function test_html_forms_restore_only_safe_input_for_the_matching_target(): void
    {
        $goal = EnrollmentGoal::factory()->create(['title' => '登録済みのタイトル', 'description' => '元の詳細', 'target_date' => '2029-01-01']);
        $enrollment = $goal->enrollment;
        $otherGoal = EnrollmentGoal::factory()->for($enrollment)->create(['title' => '別目標のタイトル']);
        $otherEnrollment = Enrollment::factory()->for($enrollment->user)->create();
        $this->actingAs($enrollment->user);
        $detail = route('enrollments.show', $enrollment);
        $edit = route('enrollment-goals.edit', $goal);
        $patch = route('enrollment-goals.update', $goal);

        $this->post(route('enrollments.goals.store', $enrollment), [
            'title' => '復元するタイトル',
            'description' => ['復元しない配列'],
            'target_date' => '2025-02-29',
            'password' => '復元しない秘密',
        ])->assertRedirect($detail)
            ->assertSessionMissing('error')
            ->assertSessionMissing('_old_input');

        $this->get($detail)->assertOk()
            ->assertSee('value="復元するタイトル"', false)
            ->assertSeeText('詳細は文字列で入力してください。')
            ->assertSeeText('目標期日はYYYY-MM-DD形式の有効な日付で入力してください。')
            ->assertSeeText('変更できない項目が指定されています。')
            ->assertDontSeeText('復元しない配列')
            ->assertDontSeeText('復元しない秘密');

        $this->post(route('enrollments.goals.store', $enrollment), ['title' => null, 'description' => '別の登録には出さない'])
            ->assertRedirect($detail);
        $this->get(route('enrollments.show', $otherEnrollment))->assertOk()
            ->assertDontSeeText('目標を入力してください。')->assertDontSeeText('別の登録には出さない');

        $invalidEdit = ['title' => ['復元しない配列'], 'description' => '', 'target_date' => ''];
        $this->patch($patch, $invalidEdit)->assertRedirect($edit);
        $this->get($edit)->assertOk()
            ->assertSee('value="登録済みのタイトル"', false)
            ->assertSeeText('目標は文字列で入力してください。')
            ->assertDontSeeText('元の詳細')
            ->assertDontSee('2029-01-01')
            ->assertDontSee('復元しない配列');

        $this->patch($patch, $invalidEdit)->assertRedirect($edit);
        $this->get(route('enrollment-goals.edit', $otherGoal))->assertOk()
            ->assertSee('value="別目標のタイトル"', false)
            ->assertDontSeeText('目標は文字列で入力してください。');

        $errors = new ViewErrorBag;
        $errors->put('default', new MessageBag(['title' => ['別フォームのタイトルエラー']]));
        $this->withSession(['errors' => $errors, '_old_input' => ['title' => '別フォームの入力']])
            ->get($edit)->assertOk()->assertDontSeeText('別フォームのタイトルエラー')->assertDontSee('別フォームの入力');
        $this->assertDatabaseHas('enrollment_goals', [
            'id' => $goal->id,
            'title' => '登録済みのタイトル',
            'description' => '元の詳細',
            'target_date' => '2029-01-01',
        ]);
    }

    public function test_forbidden_input_notices_are_only_shown_on_the_corresponding_form(): void
    {
        $goal = EnrollmentGoal::factory()->create();
        $enrollment = $goal->enrollment;
        $otherEnrollment = Enrollment::factory()->for($enrollment->user)->create();
        $otherGoal = EnrollmentGoal::factory()->for($enrollment)->create();
        $notice = '変更できない項目が指定されています。';
        $payload = ['title' => '保存されない目標', 'enrollment_id' => null];
        $detail = route('enrollments.show', $enrollment);
        $edit = route('enrollment-goals.edit', $goal);
        $store = route('enrollments.goals.store', $enrollment);
        $update = route('enrollment-goals.update', $goal);
        $before = $goal->fresh()->getAttributes();
        $this->actingAs($enrollment->user);

        foreach ([
            ['POST', $store, $detail, [route('enrollments.show', $otherEnrollment), $edit, route('dashboard.index')]],
            ['PATCH', $update, $edit, [route('enrollment-goals.edit', $otherGoal), $detail, route('dashboard.index')]],
        ] as [$method, $url, $target, $unrelatedPages]) {
            $this->call($method, $url, $payload)->assertRedirect($target);
            $response = $this->get($target)->assertOk()->assertSeeText($notice);
            $this->assertSame(1, substr_count($response->getContent(), $notice));
            $this->get($target)->assertOk()->assertDontSeeText($notice);

            foreach ($unrelatedPages as $unrelated) {
                $this->call($method, $url, $payload)->assertRedirect($target);
                $this->get($unrelated)->assertOk()->assertDontSeeText($notice);
                $this->get($target)->assertOk()->assertDontSeeText($notice);
            }

            $this->json($method, $url, $payload)->assertUnprocessable()
                ->assertJsonPath('message', $notice)
                ->assertJsonPath('errors._input.0', $notice);
        }

        $this->assertDatabaseCount('enrollment_goals', 2);
        $this->assertSame($before, $goal->fresh()->getAttributes());
    }
}
