<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Part;

use App\Models\Certification;
use App\Models\Part;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ContentTestHelpers;
use Tests\TestCase;

class PublishTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_admin_can_publish_draft_part(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();

        $this->actingAs($admin)
            ->post(route('admin.parts.publish', $part))
            ->assertRedirect(route('admin.parts.show', $part));

        $part->refresh();
        $this->assertSame('published', $part->status->value);
        $this->assertNotNull($part->published_at);
    }

    public function test_cannot_publish_already_published(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->published()->create();

        $this->actingAs($admin)
            ->postJson(route('admin.parts.publish', $part))
            ->assertStatus(409);
    }

    public function test_admin_can_unpublish_published_part(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->published()->create();

        $this->actingAs($admin)
            ->post(route('admin.parts.unpublish', $part))
            ->assertRedirect(route('admin.parts.show', $part));

        $this->assertSame('draft', $part->fresh()->status->value);
    }

    public function test_assigned_coach_can_publish_part_only_in_assigned_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $certX = Certification::factory()->published()->create();
        $certY = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certX);

        $partX = Part::factory()->forCertification($certX)->draft()->create();
        $partY = Part::factory()->forCertification($certY)->draft()->create();

        // 担当資格XのPartは公開できる
        $this->actingAs($coach)
            ->post(route('admin.parts.publish', $partX))
            ->assertRedirect();

        $this->assertSame('published', $partX->fresh()->status->value);

        // 担当外資格YのPartは公開できない
        $this->actingAs($coach)
            ->post(route('admin.parts.publish', $partY))
            ->assertForbidden();

        $this->assertSame('draft', $partY->fresh()->status->value);
    }

    public function test_assigned_coach_can_unpublish_part_only_in_assigned_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $certX = Certification::factory()->published()->create();
        $certY = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certX);

        $partX = Part::factory()->forCertification($certX)->published()->create();
        $partY = Part::factory()->forCertification($certY)->published()->create();

        // 担当資格XのPartは非公開できる
        $this->actingAs($coach)
            ->post(route('admin.parts.unpublish', $partX))
            ->assertRedirect();

        $this->assertSame('draft', $partX->fresh()->status->value);

        // 担当外資格YのPartは非公開できない
        $this->actingAs($coach)
            ->post(route('admin.parts.unpublish', $partY))
            ->assertForbidden();

        $this->assertSame('published', $partY->fresh()->status->value);
    }
}
