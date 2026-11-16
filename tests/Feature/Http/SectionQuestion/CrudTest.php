<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SectionQuestion;

use App\Models\Certification;
use App\Models\SectionQuestion;
use App\Models\SectionQuestionOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ContentTestHelpers;
use Tests\TestCase;

class CrudTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_admin_can_create_section_question_with_options(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        [, , $section] = $this->makePartChain($cert);
        $category = $this->makeCategory($cert);

        $this->actingAs($admin)
            ->post(route('admin.sections.questions.store', $section), [
                'body' => 'Markdown を安全に描画するために最も適切な処理はどれか?',
                'explanation' => 'XSS 対策として html_input=strip が有効。',
                'category_id' => $category->id,
                'options' => [
                    ['body' => 'プレーンテキストとして表示する', 'is_correct' => false, 'order' => 0],
                    ['body' => 'CommonMark で html_input=strip を有効化する', 'is_correct' => true, 'order' => 1],
                    ['body' => '何もせずそのまま表示する', 'is_correct' => false, 'order' => 2],
                    ['body' => 'iframe で囲む', 'is_correct' => false, 'order' => 3],
                ],
            ])
            ->assertRedirect();

        $question = SectionQuestion::firstOrFail();
        $this->assertSame('draft', $question->status->value);
        $this->assertSame($section->id, $question->section_id);
        $this->assertSame(4, $question->options()->count());
        $this->assertSame(1, $question->options()->where('is_correct', true)->count());
    }

    public function test_store_rejects_zero_correct_options(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        [, , $section] = $this->makePartChain($cert);
        $category = $this->makeCategory($cert);

        $this->actingAs($admin)
            ->postJson(route('admin.sections.questions.store', $section), [
                'body' => 'Q?',
                'category_id' => $category->id,
                'options' => [
                    ['body' => 'A', 'is_correct' => false, 'order' => 0],
                    ['body' => 'B', 'is_correct' => false, 'order' => 1],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_store_rejects_category_from_different_certification(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        [, , $section] = $this->makePartChain($cert);
        $otherCert = Certification::factory()->published()->create();
        $foreignCategory = $this->makeCategory($otherCert);

        $this->actingAs($admin)
            ->postJson(route('admin.sections.questions.store', $section), [
                'body' => 'Q?',
                'category_id' => $foreignCategory->id,
                'options' => [
                    ['body' => 'A', 'is_correct' => true, 'order' => 0],
                    ['body' => 'B', 'is_correct' => false, 'order' => 1],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_store_rejects_single_option(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        [, , $section] = $this->makePartChain($cert);
        $category = $this->makeCategory($cert);

        $this->actingAs($admin)
            ->postJson(route('admin.sections.questions.store', $section), [
                'body' => 'Q?',
                'category_id' => $category->id,
                'options' => [
                    ['body' => 'A', 'is_correct' => true, 'order' => 0],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_update_replaces_options_via_delete_and_insert(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        [, , $section] = $this->makePartChain($cert);
        $category = $this->makeCategory($cert);
        $question = SectionQuestion::factory()
            ->forSection($section)
            ->forCategory($category)
            ->withOptions(4)
            ->draft()
            ->create();

        $originalOptionIds = $question->options()->pluck('id')->all();

        $this->actingAs($admin)
            ->patch(route('admin.section-questions.update', $question), [
                'body' => 'updated',
                'category_id' => $category->id,
                'options' => [
                    ['body' => 'new A', 'is_correct' => true, 'order' => 0],
                    ['body' => 'new B', 'is_correct' => false, 'order' => 1],
                    ['body' => 'new C', 'is_correct' => false, 'order' => 2],
                ],
            ])
            ->assertRedirect();

        $question->refresh();
        $this->assertSame(3, $question->options()->count());
        $this->assertSame(0, SectionQuestionOption::whereIn('id', $originalOptionIds)->count());
    }

    public function test_publish_requires_valid_options(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        [, , $section] = $this->makePartChain($cert);
        $category = $this->makeCategory($cert);

        $bad = SectionQuestion::factory()->forSection($section)->forCategory($category)->draft()->create();
        $bad->options()->create(['body' => 'lone', 'is_correct' => true, 'order' => 0]);

        $this->actingAs($admin)
            ->postJson(route('admin.section-questions.publish', $bad))
            ->assertStatus(409);

        $good = SectionQuestion::factory()->forSection($section)->forCategory($category)->withOptions(4)->draft()->create();

        $this->actingAs($admin)
            ->post(route('admin.section-questions.publish', $good))
            ->assertRedirect();
        $this->assertSame('published', $good->fresh()->status->value);
    }

    public function test_non_assigned_coach_cannot_view_section_questions(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        [, , $section] = $this->makePartChain($cert);

        $this->actingAs($coach)
            ->get(route('admin.sections.questions.index', $section))
            ->assertForbidden();
    }

    public function test_destroy_soft_deletes_section_question(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        [, , $section] = $this->makePartChain($cert);
        $category = $this->makeCategory($cert);
        $question = SectionQuestion::factory()
            ->forSection($section)
            ->forCategory($category)
            ->draft()
            ->create();

        $this->actingAs($admin)
            ->delete(route('admin.section-questions.destroy', $question))
            ->assertRedirect();

        $this->assertDatabaseMissing('section_questions', ['id' => $question->id]);
    }

    public function test_assigned_coach_can_view_section_questions(): void
    {
        $coach = User::factory()->coach()->create();

        $certX = Certification::factory()->published()->create();
        $certY = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certX);

        [, , $sectionX] = $this->makePartChain($certX);
        [, , $sectionY] = $this->makePartChain($certY);

        $this->actingAs($coach)
            ->get(route('admin.sections.questions.index', $sectionX))
            ->assertOk();

        $this->actingAs($coach)
            ->get(route('admin.sections.questions.index', $sectionY))
            ->assertForbidden();
    }

    public function test_assigned_coach_can_create_question_only_in_assigned_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $certX = Certification::factory()->published()->create();
        $certY = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certX);

        [, , $sectionX] = $this->makePartChain($certX);
        [, , $sectionY] = $this->makePartChain($certY);

        $categoryX = $this->makeCategory($certX);
        $categoryY = $this->makeCategory($certY);

        // 担当資格Xには作成できる
        $this->actingAs($coach)
            ->post(route('admin.sections.questions.store', $sectionX), [
                'body' => '担当資格の問題',
                'category_id' => $categoryX->id,
                'options' => [
                    ['body' => '正解', 'is_correct' => true, 'order' => 0],
                    ['body' => '不正解', 'is_correct' => false, 'order' => 1],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('section_questions', [
            'section_id' => $sectionX->id,
            'body' => '担当資格の問題',
        ]);

        // 担当外資格Yには作成できない
        $this->actingAs($coach)
            ->post(route('admin.sections.questions.store', $sectionY), [
                'body' => '担当外の問題',
                'category_id' => $categoryY->id,
                'options' => [
                    ['body' => '正解', 'is_correct' => true, 'order' => 0],
                    ['body' => '不正解', 'is_correct' => false, 'order' => 1],
                ],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('section_questions', [
            'section_id' => $sectionY->id,
            'body' => '担当外の問題',
        ]);
    }

    public function test_assigned_coach_can_update_question_only_in_assigned_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $certX = Certification::factory()->published()->create();
        $certY = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certX);

        [, , $sectionX] = $this->makePartChain($certX);
        [, , $sectionY] = $this->makePartChain($certY);

        $categoryX = $this->makeCategory($certX);
        $categoryY = $this->makeCategory($certY);

        $questionX = SectionQuestion::factory()
            ->forSection($sectionX)
            ->forCategory($categoryX)
            ->withOptions(4)
            ->draft()
            ->create(['body' => '更新前X']);

        $questionY = SectionQuestion::factory()
            ->forSection($sectionY)
            ->forCategory($categoryY)
            ->withOptions(4)
            ->draft()
            ->create(['body' => '更新前Y']);

        // 担当資格Xの問題は更新できる
        $this->actingAs($coach)
            ->patch(route('admin.section-questions.update', $questionX), [
                'body' => '更新後X',
                'category_id' => $categoryX->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('section_questions', [
            'id' => $questionX->id,
            'body' => '更新後X',
        ]);

        // 担当外資格Yの問題は更新できない
        $this->actingAs($coach)
            ->patch(route('admin.section-questions.update', $questionY), [
                'body' => '不正更新',
                'category_id' => $categoryY->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('section_questions', [
            'id' => $questionY->id,
            'body' => '更新前Y',
        ]);
    }

    public function test_assigned_coach_can_delete_question_only_in_assigned_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $certX = Certification::factory()->published()->create();
        $certY = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certX);

        [, , $sectionX] = $this->makePartChain($certX);
        [, , $sectionY] = $this->makePartChain($certY);

        $categoryX = $this->makeCategory($certX);
        $categoryY = $this->makeCategory($certY);

        $questionX = SectionQuestion::factory()
            ->forSection($sectionX)
            ->forCategory($categoryX)
            ->draft()
            ->create();

        $questionY = SectionQuestion::factory()
            ->forSection($sectionY)
            ->forCategory($categoryY)
            ->draft()
            ->create();

        // 担当資格Xの問題は削除できる
        $this->actingAs($coach)
            ->delete(route('admin.section-questions.destroy', $questionX))
            ->assertRedirect();

        $this->assertDatabaseMissing('section_questions', [
            'id' => $questionX->id,
        ]);

        // 担当外資格Yの問題は削除できない
        $this->actingAs($coach)
            ->delete(route('admin.section-questions.destroy', $questionY))
            ->assertForbidden();

        $this->assertDatabaseHas('section_questions', [
            'id' => $questionY->id,
        ]);
    }

    public function test_assigned_coach_can_publish_question_only_in_assigned_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $certX = Certification::factory()->published()->create();
        $certY = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certX);

        [, , $sectionX] = $this->makePartChain($certX);
        [, , $sectionY] = $this->makePartChain($certY);

        $categoryX = $this->makeCategory($certX);
        $categoryY = $this->makeCategory($certY);

        $questionX = SectionQuestion::factory()
            ->forSection($sectionX)
            ->forCategory($categoryX)
            ->withOptions(4)
            ->draft()
            ->create();

        $questionY = SectionQuestion::factory()
            ->forSection($sectionY)
            ->forCategory($categoryY)
            ->withOptions(4)
            ->draft()
            ->create();

        // 担当資格Xの問題は公開できる
        $this->actingAs($coach)
            ->post(route('admin.section-questions.publish', $questionX))
            ->assertRedirect();

        $this->assertDatabaseHas('section_questions', [
            'id' => $questionX->id,
            'status' => 'published',
        ]);

        // 担当外資格Yの問題は公開できない
        $this->actingAs($coach)
            ->post(route('admin.section-questions.publish', $questionY))
            ->assertForbidden();

        // 担当外資格Yの問題は下書きのまま
        $this->assertDatabaseHas('section_questions', [
            'id' => $questionY->id,
            'status' => 'draft',
        ]);
    }

    public function test_assigned_coach_can_unpublish_question_only_in_assigned_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $certX = Certification::factory()->published()->create();
        $certY = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certX);

        [, , $sectionX] = $this->makePartChain($certX);
        [, , $sectionY] = $this->makePartChain($certY);

        $categoryX = $this->makeCategory($certX);
        $categoryY = $this->makeCategory($certY);

        $questionX = SectionQuestion::factory()
            ->forSection($sectionX)
            ->forCategory($categoryX)
            ->published()
            ->create();

        $questionY = SectionQuestion::factory()
            ->forSection($sectionY)
            ->forCategory($categoryY)
            ->published()
            ->create();

        // 担当資格Xの問題は非公開にできる
        $this->actingAs($coach)
            ->post(route('admin.section-questions.unpublish', $questionX))
            ->assertRedirect();

        $this->assertDatabaseHas('section_questions', [
            'id' => $questionX->id,
            'status' => 'draft',
        ]);

        // 担当外資格Yの問題は非公開にできない
        $this->actingAs($coach)
            ->post(route('admin.section-questions.unpublish', $questionY))
            ->assertForbidden();

        $this->assertDatabaseHas('section_questions', [
            'id' => $questionY->id,
            'status' => 'published',
        ]);
    }
}
