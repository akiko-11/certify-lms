<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Section;

use App\Models\Certification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ContentTestHelpers;
use Tests\TestCase;

class ShowTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_assigned_coach_can_view_section_only_in_assigned_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $certX = Certification::factory()->published()->create();
        $certY = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certX);

        // 担当資格X・担当外資格YのSectionを作成
        [, , $sectionX] = $this->makePartChain($certX, 'draft');
        [, , $sectionY] = $this->makePartChain($certY, 'draft');

        // 担当資格X：詳細を閲覧できる
        $this->actingAs($coach)
            ->get(route('admin.sections.show', $sectionX))
            ->assertOk();

        // 担当外資格Y：詳細を閲覧できない
        $this->actingAs($coach)
            ->get(route('admin.sections.show', $sectionY))
            ->assertForbidden();
    }
}
