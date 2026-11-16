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

class ReorderTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_assigned_coach_can_reorder_chapters_only_in_assigned_certification(): void
    {
        // テストデータの準備
        $coach = User::factory()->coach()->create();
        $certX = Certification::factory()->published()->create();
        $certY = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certX);

        $partX = Part::factory()->forCertification($certX)->draft()->create();
        $partY = Part::factory()->forCertification($certY)->draft()->create();

        // 担当資格XのChapter
        $chapterX1 = Chapter::factory()->forPart($partX)
            ->state(['order' => 1])->create();

        $chapterX2 = Chapter::factory()->forPart($partX)
            ->state(['order' => 2])->create();

        // 担当外資格YのChapter
        $chapterY1 = Chapter::factory()->forPart($partY)
            ->state(['order' => 1])->create();

        $chapterY2 = Chapter::factory()->forPart($partY)
            ->state(['order' => 2])->create();

        // 担当資格X：並び替えできる
        $this->actingAs($coach)
            ->patch(route('admin.parts.chapters.reorder', $partX), [
                'ids' => [$chapterX2->id, $chapterX1->id],
            ])
            ->assertRedirect();

        // 並び順が更新されていることを確認
        $this->assertSame(1, $chapterX2->fresh()->order);
        $this->assertSame(2, $chapterX1->fresh()->order);

        // 担当外資格Y：並び替えできない
        $this->actingAs($coach)
            ->patch(route('admin.parts.chapters.reorder', $partY), [
                'ids' => [$chapterY2->id, $chapterY1->id],
            ])
            ->assertForbidden();

        // 並び順が変更されていないことを確認
        $this->assertSame(1, $chapterY1->fresh()->order);
        $this->assertSame(2, $chapterY2->fresh()->order);
    }
}
