<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Section;

use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ContentTestHelpers;
use Tests\TestCase;

class CrudTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_store_section_includes_body(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->draft()->create();

        $this->actingAs($admin)
            ->post(route('admin.chapters.sections.store', $chapter), [
                'title' => 'はじめに',
                'body' => '## 概要

本セクションでは...',
            ])
            ->assertRedirect();

        $section = Section::where('title', 'はじめに')->firstOrFail();
        $this->assertStringContainsString('概要', $section->body);
        $this->assertSame('draft', $section->status->value);
    }

    public function test_update_updates_body(): void
    {
        $admin = User::factory()->admin()->create();
        [$part, $chapter, $section] = $this->makePartChain(Certification::factory()->published()->create(), 'draft');

        $this->actingAs($admin)
            ->patch(route('admin.sections.update', $section), [
                'title' => '新タイトル',
                'body' => 'updated body',
            ])
            ->assertRedirect();

        $section->refresh();
        $this->assertSame('新タイトル', $section->title);
        $this->assertSame('updated body', $section->body);
    }

    public function test_preview_returns_html(): void
    {
        $admin = User::factory()->admin()->create();
        [$part, $chapter, $section] = $this->makePartChain(Certification::factory()->published()->create(), 'draft');

        $this->actingAs($admin)
            ->postJson(route('admin.sections.preview', $section), [
                'body' => "# タイトル\n\n本文",
            ])
            ->assertOk()
            ->assertJsonStructure(['html']);
    }

    public function test_assigned_coach_can_create_section_only_in_assigned_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $certX = Certification::factory()->published()->create();
        $certY = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certX);

        $partX = Part::factory()->forCertification($certX)->draft()->create();
        $partY = Part::factory()->forCertification($certY)->draft()->create();

        $chapterX = Chapter::factory()->forPart($partX)->draft()->create();
        $chapterY = Chapter::factory()->forPart($partY)->draft()->create();

        // 担当資格X：作成できる
        $this->actingAs($coach)
            ->post(route('admin.chapters.sections.store', $chapterX), [
                'title' => '担当内Section',
                'body' => '担当内の本文',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('sections', [
            'chapter_id' => $chapterX->id,
            'title' => '担当内Section',
            'body' => '担当内の本文',
        ]);

        // 担当外資格Y：作成できない
        $this->actingAs($coach)
            ->post(route('admin.chapters.sections.store', $chapterY), [
                'title' => '担当外Section',
                'body' => '担当外の本文',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('sections', [
            'chapter_id' => $chapterY->id,
            'title' => '担当外Section',
        ]);
    }

    public function test_assigned_coach_can_update_section_only_in_assigned_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $certX = Certification::factory()->published()->create();
        $certY = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certX);

        // 各資格にPart・Chapter・Sectionを作成
        [, , $sectionX] = $this->makePartChain($certX, 'draft');
        [, , $sectionY] = $this->makePartChain($certY, 'draft');

        $originalTitleY = $sectionY->title;
        $originalBodyY = $sectionY->body;

        // 担当資格X：更新できる
        $this->actingAs($coach)
            ->patch(route('admin.sections.update', $sectionX), [
                'title' => '更新後のSection',
                'body' => '更新後の本文',
            ])
            ->assertRedirect();

        $this->assertSame('更新後のSection', $sectionX->fresh()->title);
        $this->assertSame('更新後の本文', $sectionX->fresh()->body);

        // 担当外資格Y：更新できない
        $this->actingAs($coach)
            ->patch(route('admin.sections.update', $sectionY), [
                'title' => '不正な更新',
                'body' => '不正な本文',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('sections', [
            'id' => $sectionY->id,
            'title' => $originalTitleY,
            'body' => $originalBodyY,
        ]);
    }

    public function test_assigned_coach_can_delete_section_only_in_assigned_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $certX = Certification::factory()->published()->create();
        $certY = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certX);

        // 担当資格X・担当外資格YのSectionを作成
        [, , $sectionX] = $this->makePartChain($certX, 'draft');
        [, , $sectionY] = $this->makePartChain($certY, 'draft');

        // 担当資格X：削除できる
        $this->actingAs($coach)
            ->delete(route('admin.sections.destroy', $sectionX))
            ->assertRedirect();

        $this->assertDatabaseMissing('sections', [
            'id' => $sectionX->id,
        ]);

        // 担当外資格Y：削除できない
        $this->actingAs($coach)
            ->delete(route('admin.sections.destroy', $sectionY))
            ->assertForbidden();

        $this->assertDatabaseHas('sections', [
            'id' => $sectionY->id,
        ]);
    }

    public function test_assigned_coach_can_publish_section_only_in_assigned_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $certX = Certification::factory()->published()->create();
        $certY = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certX);

        // 公開前のSectionを作成
        [, , $sectionX] = $this->makePartChain($certX, 'draft');
        [, , $sectionY] = $this->makePartChain($certY, 'draft');

        // 担当資格X：公開できる
        $this->actingAs($coach)
            ->post(route('admin.sections.publish', $sectionX))
            ->assertRedirect();

        $this->assertSame('published', $sectionX->fresh()->status->value);

        // 担当外資格Y：公開できない
        $this->actingAs($coach)
            ->post(route('admin.sections.publish', $sectionY))
            ->assertForbidden();

        $this->assertSame('draft', $sectionY->fresh()->status->value);
    }

    public function test_assigned_coach_can_unpublish_section_only_in_assigned_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $certX = Certification::factory()->published()->create();
        $certY = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certX);

        // 公開済みのSectionを作成
        [, , $sectionX] = $this->makePartChain($certX, 'published');
        [, , $sectionY] = $this->makePartChain($certY, 'published');

        // 担当資格X：非公開にできる
        $this->actingAs($coach)
            ->post(route('admin.sections.unpublish', $sectionX))
            ->assertRedirect();

        $this->assertSame('draft', $sectionX->fresh()->status->value);

        // 担当外資格Y：非公開にできない
        $this->actingAs($coach)
            ->post(route('admin.sections.unpublish', $sectionY))
            ->assertForbidden();

        $this->assertSame('published', $sectionY->fresh()->status->value);
    }

    public function test_assigned_coach_can_preview_section_only_in_assigned_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $certX = Certification::factory()->published()->create();
        $certY = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certX);

        // 担当資格X・担当外資格YのSectionを作成
        [, , $sectionX] = $this->makePartChain($certX, 'draft');
        [, , $sectionY] = $this->makePartChain($certY, 'draft');

        $originalBodyX = $sectionX->body;
        $originalBodyY = $sectionY->body;

        // 担当資格X：プレビューできる
        $response = $this->actingAs($coach)
            ->postJson(route('admin.sections.preview', $sectionX), [
                'body' => '# プレビュー確認',
            ]);

        $response->assertOk()
            ->assertJsonStructure(['html']);

        $this->assertStringContainsString(
            'プレビュー確認',
            $response->json('html'),
        );

        // プレビューではDBが更新されない
        $this->assertSame($originalBodyX, $sectionX->fresh()->body);

        // 担当外資格Y：プレビューできない
        $this->actingAs($coach)
            ->postJson(route('admin.sections.preview', $sectionY), [
                'body' => '# 担当外のプレビュー',
            ])
            ->assertForbidden();

        // 担当外資格YのDBも変更されない
        $this->assertSame($originalBodyY, $sectionY->fresh()->body);
    }
}
