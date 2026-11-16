<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Chapter;

use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Part;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ContentTestHelpers;
use Tests\TestCase;

class CrudTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_store_creates_draft_chapter(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();

        $this->actingAs($admin)
            ->post(route('admin.parts.chapters.store', $part), ['title' => '第1章'])
            ->assertRedirect();

        $chapter = Chapter::where('title', '第1章')->firstOrFail();
        $this->assertSame('draft', $chapter->status->value);
        $this->assertSame(1, $chapter->order);
    }

    public function test_publish_then_unpublish(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->draft()->create();

        $this->actingAs($admin)
            ->post(route('admin.chapters.publish', $chapter))
            ->assertRedirect();
        $this->assertSame('published', $chapter->fresh()->status->value);

        $this->actingAs($admin)
            ->post(route('admin.chapters.unpublish', $chapter))
            ->assertRedirect();
        $this->assertSame('draft', $chapter->fresh()->status->value);
    }

    public function test_destroy_draft_only(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->published()->create();

        $this->actingAs($admin)
            ->deleteJson(route('admin.chapters.destroy', $chapter))
            ->assertStatus(409);
    }

    public function test_assigned_coach_can_create_chapter_only_in_assigned_certification(): void
    {
        // テストデータの準備
        $coach = User::factory()->coach()->create();
        $certX = Certification::factory()->published()->create();
        $certY = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certX);

        $partX = Part::factory()->forCertification($certX)->draft()->create();
        $partY = Part::factory()->forCertification($certY)->draft()->create();

        // 担当資格X：作成できる
        $this->actingAs($coach)
            ->post(route('admin.parts.chapters.store', $partX), [
                'title' => '第1章',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('chapters', [
            'part_id' => $partX->id,
            'title' => '第1章',
        ]);

        // 担当外資格Y：作成できない
        $this->actingAs($coach)
            ->post(route('admin.parts.chapters.store', $partY), [
                'title' => '第2章',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('chapters', [
            'part_id' => $partY->id,
            'title' => '第2章',
        ]);
    }

    public function test_assigned_coach_can_update_chapter_only_in_assigned_certification(): void
    {
        // テストデータの準備
        $coach = User::factory()->coach()->create();
        $certX = Certification::factory()->published()->create();
        $certY = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certX);

        $partX = Part::factory()->forCertification($certX)->draft()->create();
        $partY = Part::factory()->forCertification($certY)->draft()->create();

        $chapterX = Chapter::factory()->forPart($partX)->draft()->create();
        $chapterY = Chapter::factory()->forPart($partY)->draft()->create();

        $originalTitleY = $chapterY->title;

        // 担当資格X：更新できる
        $this->actingAs($coach)
            ->patch(route('admin.chapters.update', $chapterX), [
                'title' => '更新後のタイトル',
            ])
            ->assertRedirect();

        $this->assertSame('更新後のタイトル', $chapterX->fresh()->title);

        // 担当外資格Y：更新できない
        $this->actingAs($coach)
            ->patch(route('admin.chapters.update', $chapterY), [
                'title' => '更新後のタイトル',
            ])
            ->assertForbidden();

        $this->assertSame($originalTitleY, $chapterY->fresh()->title);
    }

    public function test_assigned_coach_can_delete_chapter_only_in_assigned_certification(): void
    {
        // テストデータの準備
        $coach = User::factory()->coach()->create();
        $certX = Certification::factory()->published()->create();
        $certY = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certX);

        $partX = Part::factory()->forCertification($certX)->draft()->create();
        $partY = Part::factory()->forCertification($certY)->draft()->create();

        $chapterX = Chapter::factory()->forPart($partX)->draft()->create();
        $chapterY = Chapter::factory()->forPart($partY)->draft()->create();

        // 担当資格X：削除できる
        $this->actingAs($coach)
            ->delete(route('admin.chapters.destroy', $chapterX))
            ->assertRedirect();

        $this->assertDatabaseMissing('chapters', [
            'id' => $chapterX->id,
        ]);

        // 担当外資格Y：削除できない
        $this->actingAs($coach)
            ->delete(route('admin.chapters.destroy', $chapterY))
            ->assertForbidden();

        $this->assertDatabaseHas('chapters', [
            'id' => $chapterY->id,
        ]);
    }

    public function test_assigned_coach_can_publish_chapter_only_in_assigned_certification(): void
    {
        // テストデータの準備
        $coach = User::factory()->coach()->create();
        $certX = Certification::factory()->published()->create();
        $certY = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certX);

        $partX = Part::factory()->forCertification($certX)->draft()->create();
        $partY = Part::factory()->forCertification($certY)->draft()->create();

        $chapterX = Chapter::factory()->forPart($partX)->draft()->create();
        $chapterY = Chapter::factory()->forPart($partY)->draft()->create();

        // 担当資格XのChapterは公開できる
        $this->actingAs($coach)
            ->post(route('admin.chapters.publish', $chapterX))
            ->assertRedirect();

        $this->assertSame('published', $chapterX->fresh()->status->value);

        // 担当外資格YのChapterは公開できない
        $this->actingAs($coach)
            ->post(route('admin.chapters.publish', $chapterY))
            ->assertForbidden();

        $this->assertSame('draft', $chapterY->fresh()->status->value);
    }

    public function test_assigned_coach_can_unpublish_chapter_only_in_assigned_certification(): void
    {
        // テストデータの準備
        $coach = User::factory()->coach()->create();
        $certX = Certification::factory()->published()->create();
        $certY = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certX);

        $partX = Part::factory()->forCertification($certX)->draft()->create();
        $partY = Part::factory()->forCertification($certY)->draft()->create();

        $chapterX = Chapter::factory()->forPart($partX)->published()->create();
        $chapterY = Chapter::factory()->forPart($partY)->published()->create();

        // 担当資格XのChapterは非公開できる
        $this->actingAs($coach)
            ->post(route('admin.chapters.unpublish', $chapterX))
            ->assertRedirect();

        $this->assertSame('draft', $chapterX->fresh()->status->value);

        // 担当外資格YのChapterは非公開できない
        $this->actingAs($coach)
            ->post(route('admin.chapters.unpublish', $chapterY))
            ->assertForbidden();

        $this->assertSame('published', $chapterY->fresh()->status->value);
    }
}
