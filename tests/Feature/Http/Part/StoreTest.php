<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Part;

use App\Models\Certification;
use App\Models\Part;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ContentTestHelpers;
use Tests\TestCase;

class StoreTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_admin_can_create_part_as_draft_with_auto_order(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        Part::factory()->forCertification($cert)->state(['order' => 1])->create();

        $response = $this->actingAs($admin)
            ->post(route('admin.certifications.parts.store', $cert), [
                'title' => '第2部 ネットワーク',
                'description' => '通信プロトコルの基本',
            ]);

        $part = Part::where('title', '第2部 ネットワーク')->firstOrFail();
        $response->assertRedirect(route('admin.parts.show', $part));
        $this->assertSame('draft', $part->status->value);
        $this->assertSame(2, $part->order);
    }

    public function test_validation_title_required(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();

        $this->actingAs($admin)
            ->post(route('admin.certifications.parts.store', $cert), [
                'title' => '',
            ])
            ->assertSessionHasErrors('title');
    }

    public function test_non_assigned_coach_cannot_create(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();

        $this->actingAs($coach)
            ->post(route('admin.certifications.parts.store', $cert), ['title' => 'X'])
            ->assertForbidden();
    }

    public function test_assigned_coach_can_create_part_only_in_assigned_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $certX = Certification::factory()->published()->create();
        $certY = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certX);

        // 担当資格Xには作成できる
        $this->actingAs($coach)
            ->post(route('admin.certifications.parts.store', $certX), [
                'title' => '担当資格のPart',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('parts', [
            'certification_id' => $certX->id,
            'title' => '担当資格のPart',
        ]);

        // 担当外資格Yには作成できない
        $this->actingAs($coach)
            ->post(route('admin.certifications.parts.store', $certY), [
                'title' => '担当外資格のPart',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('parts', [
            'certification_id' => $certY->id,
            'title' => '担当外資格のPart',
        ]);
    }
}
