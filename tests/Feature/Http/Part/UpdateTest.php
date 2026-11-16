<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Part;

use App\Models\Certification;
use App\Models\Part;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ContentTestHelpers;
use Tests\TestCase;

class UpdateTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_assigned_coach_can_update_part_only_in_assigned_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $certX = Certification::factory()->published()->create();
        $certY = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certX);

        $partX = Part::factory()
            ->forCertification($certX)
            ->draft()
            ->create(['title' => '更新前X']);

        $partY = Part::factory()
            ->forCertification($certY)
            ->draft()
            ->create(['title' => '更新前Y']);

        // 担当資格XのPartは更新できる
        $this->actingAs($coach)
            ->patch(route('admin.parts.update', $partX), [
                'title' => '更新後X',
                'description' => '更新した説明',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('parts', [
            'id' => $partX->id,
            'title' => '更新後X',
            'description' => '更新した説明',
        ]);

        // 担当外資格YのPartは更新できない
        $this->actingAs($coach)
            ->patch(route('admin.parts.update', $partY), [
                'title' => '不正更新',
                'description' => '変更してはいけない説明',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('parts', [
            'id' => $partY->id,
            'title' => '更新前Y',
        ]);
    }
}
