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

class ReorderTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_assigned_coach_can_reorder_sections_only_in_assigned_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $certX = Certification::factory()->published()->create();
        $certY = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certX);

        // 担当資格Xの教材階層
        $partX = Part::factory()->forCertification($certX)->draft()->create();
        $chapterX = Chapter::factory()->forPart($partX)->draft()->create();

        // 担当外資格Yの教材階層
        $partY = Part::factory()->forCertification($certY)->draft()->create();
        $chapterY = Chapter::factory()->forPart($partY)->draft()->create();

        // 担当資格XのSection
        $sectionX1 = Section::factory()->forChapter($chapterX)
            ->state(['order' => 1])->create();

        $sectionX2 = Section::factory()->forChapter($chapterX)
            ->state(['order' => 2])->create();

        // 担当外資格YのSection
        $sectionY1 = Section::factory()->forChapter($chapterY)
            ->state(['order' => 1])->create();

        $sectionY2 = Section::factory()->forChapter($chapterY)
            ->state(['order' => 2])->create();

        // 担当資格X：並び替えできる
        $this->actingAs($coach)
            ->patch(route('admin.chapters.sections.reorder', $chapterX), [
                'ids' => [$sectionX2->id, $sectionX1->id],
            ])
            ->assertRedirect();

        // 並び順が更新されていることを確認
        $this->assertSame(1, $sectionX2->fresh()->order);
        $this->assertSame(2, $sectionX1->fresh()->order);

        // 担当外資格Y：並び替えできない
        $this->actingAs($coach)
            ->patch(route('admin.chapters.sections.reorder', $chapterY), [
                'ids' => [$sectionY2->id, $sectionY1->id],
            ])
            ->assertForbidden();

        // 並び順が変更されていないことを確認
        $this->assertSame(1, $sectionY1->fresh()->order);
        $this->assertSame(2, $sectionY2->fresh()->order);
    }
}
