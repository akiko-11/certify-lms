<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Part;

use App\Models\Certification;
use App\Models\Part;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ContentTestHelpers;
use Tests\TestCase;

class ReorderTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_reorder_updates_order_columns(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $a = Part::factory()->forCertification($cert)->state(['order' => 1])->create();
        $b = Part::factory()->forCertification($cert)->state(['order' => 2])->create();
        $c = Part::factory()->forCertification($cert)->state(['order' => 3])->create();

        $this->actingAs($admin)
            ->patch(route('admin.certifications.parts.reorder', $cert), [
                'ids' => [$c->id, $a->id, $b->id],
            ])
            ->assertRedirect();

        $this->assertSame(1, $c->fresh()->order);
        $this->assertSame(2, $a->fresh()->order);
        $this->assertSame(3, $b->fresh()->order);
    }

    public function test_reorder_rejects_cross_certification_ids(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $otherCert = Certification::factory()->published()->create();
        $a = Part::factory()->forCertification($cert)->state(['order' => 1])->create();
        $foreign = Part::factory()->forCertification($otherCert)->state(['order' => 1])->create();

        $this->actingAs($admin)
            ->patchJson(route('admin.certifications.parts.reorder', $cert), [
                'ids' => [$a->id, $foreign->id],
            ])
            ->assertStatus(422);
    }

    public function test_assigned_coach_can_reorder_parts_only_in_assigned_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $certX = Certification::factory()->published()->create();
        $certY = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certX);

        // 担当資格XのPart
        $partX1 = Part::factory()->forCertification($certX)
            ->state(['order' => 1])->create();

        $partX2 = Part::factory()->forCertification($certX)
            ->state(['order' => 2])->create();

        // 担当外資格YのPart
        $partY1 = Part::factory()->forCertification($certY)
            ->state(['order' => 1])->create();

        $partY2 = Part::factory()->forCertification($certY)
            ->state(['order' => 2])->create();

        // 担当資格XのPartは並び替えできる
        $this->actingAs($coach)
            ->patch(route('admin.certifications.parts.reorder', $certX), [
                'ids' => [$partX2->id, $partX1->id],
            ])
            ->assertRedirect();

        $this->assertSame(1, $partX2->fresh()->order);
        $this->assertSame(2, $partX1->fresh()->order);

        // 担当外資格YのPartは並び替えできない
        $this->actingAs($coach)
            ->patch(route('admin.certifications.parts.reorder', $certY), [
                'ids' => [$partY2->id, $partY1->id],
            ])
            ->assertForbidden();

        // 担当外資格Yの並び順は変更されていない
        $this->assertSame(1, $partY1->fresh()->order);
        $this->assertSame(2, $partY2->fresh()->order);
    }
}
