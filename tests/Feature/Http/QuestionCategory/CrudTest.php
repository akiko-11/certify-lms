<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QuestionCategory;

use App\Models\Certification;
use App\Models\QuestionCategory;
use App\Models\SectionQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ContentTestHelpers;
use Tests\TestCase;

class CrudTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_admin_can_create_question_category(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();

        $this->actingAs($admin)
            ->post(route('admin.certifications.question-categories.store', $cert), [
                'name' => 'テクノロジー系',
                'slug' => 'technology',
                'sort_order' => 10,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('question_categories', [
            'certification_id' => $cert->id,
            'slug' => 'technology',
        ]);
    }

    public function test_duplicate_slug_within_certification_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        QuestionCategory::factory()->forCertification($cert)->state(['slug' => 'tech'])->create();

        $this->actingAs($admin)
            ->post(route('admin.certifications.question-categories.store', $cert), [
                'name' => '別名',
                'slug' => 'tech',
            ])
            ->assertSessionHasErrors('slug');
    }

    public function test_same_slug_in_different_certification_allowed(): void
    {
        $admin = User::factory()->admin()->create();
        $certA = Certification::factory()->published()->create();
        $certB = Certification::factory()->published()->create();
        QuestionCategory::factory()->forCertification($certA)->state(['slug' => 'tech'])->create();

        $this->actingAs($admin)
            ->post(route('admin.certifications.question-categories.store', $certB), [
                'name' => 'Tech',
                'slug' => 'tech',
            ])
            ->assertRedirect();

        $this->assertSame(2, QuestionCategory::where('slug', 'tech')->count());
    }

    public function test_destroy_blocked_if_section_questions_exist(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        [, , $section] = $this->makePartChain($cert);
        $category = QuestionCategory::factory()->forCertification($cert)->create();
        SectionQuestion::factory()->forSection($section)->forCategory($category)->create();

        $this->actingAs($admin)
            ->deleteJson(route('admin.question-categories.destroy', $category))
            ->assertStatus(409);
    }

    public function test_destroy_allowed_when_no_questions(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $category = QuestionCategory::factory()->forCertification($cert)->create();

        $this->actingAs($admin)
            ->delete(route('admin.question-categories.destroy', $category))
            ->assertRedirect();

        $this->assertDatabaseMissing('question_categories', ['id' => $category->id]);
    }

    public function test_non_assigned_coach_cannot_view(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();

        $this->actingAs($coach)
            ->get(route('admin.certifications.question-categories.index', $cert))
            ->assertForbidden();
    }

    public function test_assigned_coach_can_view_only_assigned_question_categories(): void
    {
        // テストデータを準備
        $coach = User::factory()->coach()->create();

        $certX = Certification::factory()->published()->create();
        $certY = Certification::factory()->published()->create();

        // コーチを資格Xにだけ割り当てる
        $this->assignCoach($coach, $certX);

        // 資格Xは閲覧可能
        $this->actingAs($coach)
            ->get(route('admin.certifications.question-categories.index', $certX))
            ->assertOk();

        // 資格Yは閲覧不可
        $this->actingAs($coach)
            ->get(route('admin.certifications.question-categories.index', $certY))
            ->assertForbidden();
    }

    public function test_assigned_coach_can_create_only_own_question_categories(): void
    {
        $coach = User::factory()->coach()->create();
        $certX = Certification::factory()->published()->create();
        $certY = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certX);

        // 担当資格Xには作成できる
        $this->actingAs($coach)
            ->post(route('admin.certifications.question-categories.store', $certX), [
                'name' => 'テクノロジー系',
                'slug' => 'technology',
                'sort_order' => 10,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('question_categories', [
            'certification_id' => $certX->id,
            'slug' => 'technology',
        ]);

        // 担当外資格Yには作成できない
        $this->actingAs($coach)
            ->post(route('admin.certifications.question-categories.store', $certY), [
                'name' => '担当外の分野',
                'slug' => 'outside',
                'sort_order' => 10,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('question_categories', [
            'certification_id' => $certY->id,
            'slug' => 'outside',
        ]);
    }

    public function test_assigned_coach_can_update_only_own_question_categories(): void
    {
        $coach = User::factory()->coach()->create();
        $certX = Certification::factory()->published()->create();
        $certY = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certX);

        $categoryX = QuestionCategory::factory()
            ->forCertification($certX)
            ->create(['name' => '更新前X', 'slug' => 'before-x']);

        $categoryY = QuestionCategory::factory()
            ->forCertification($certY)
            ->create(['name' => '更新前Y', 'slug' => 'before-y']);

        // 担当資格Xの出題分野を更新できる
        $this->actingAs($coach)
            ->patch(route('admin.question-categories.update', $categoryX), [
                'name' => '更新後X',
                'slug' => 'after-x',
                'sort_order' => 10,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('question_categories', [
            'id' => $categoryX->id,
            'name' => '更新後X',
            'slug' => 'after-x',
        ]);

        // 担当外資格Yの出題分野は更新できない
        $this->actingAs($coach)
            ->patch(route('admin.question-categories.update', $categoryY), [
                'name' => '不正更新',
                'slug' => 'invalid-update',
                'sort_order' => 10,
            ])
            ->assertForbidden();

        // 資格Yのデータが変更されていないこと
        $this->assertDatabaseHas('question_categories', [
            'id' => $categoryY->id,
            'name' => '更新前Y',
            'slug' => 'before-y',
        ]);
    }

    public function test_assigned_coach_can_delete_only_own_question_categories(): void
    {
        $coach = User::factory()->coach()->create();
        $certX = Certification::factory()->published()->create();
        $certY = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certX);

        $categoryX = QuestionCategory::factory()
            ->forCertification($certX)
            ->create();

        $categoryY = QuestionCategory::factory()
            ->forCertification($certY)
            ->create();

        // 担当資格Xの出題分野は削除できる
        $this->actingAs($coach)
            ->delete(route('admin.question-categories.destroy', $categoryX))
            ->assertRedirect();

        $this->assertDatabaseMissing('question_categories', [
            'id' => $categoryX->id,
        ]);

        // 担当外資格Yの出題分野は削除できない
        $this->actingAs($coach)
            ->delete(route('admin.question-categories.destroy', $categoryY))
            ->assertForbidden();

        // 担当外資格Yのデータが残っていること
        $this->assertDatabaseHas('question_categories', [
            'id' => $categoryY->id,
        ]);
    }
}
