<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SectionImage;

use App\Models\Certification;
use App\Models\SectionImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ContentTestHelpers;
use Tests\TestCase;

class StoreTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_admin_can_upload_image(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        [$part, $chapter, $section] = $this->makePartChain(Certification::factory()->published()->create(), 'draft');

        $file = UploadedFile::fake()->image('cover.png', 800, 600);

        $response = $this->actingAs($admin)
            ->postJson(route('admin.sections.images.store', $section), [
                'file' => $file,
            ])
            ->assertCreated()
            ->assertJsonStructure(['id', 'url', 'alt_placeholder']);

        $this->assertDatabaseHas('section_images', [
            'section_id' => $section->id,
            'mime_type' => 'image/png',
        ]);

        $payload = $response->json();
        $path = ltrim(str_replace('/storage/', '', $payload['url']), '/');
        Storage::disk('public')->assertExists($path);
    }

    public function test_rejects_oversized_file(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        [$part, $chapter, $section] = $this->makePartChain(Certification::factory()->published()->create(), 'draft');

        $file = UploadedFile::fake()->create('big.png', 3000, 'image/png');

        $this->actingAs($admin)
            ->postJson(route('admin.sections.images.store', $section), ['file' => $file])
            ->assertStatus(422);
    }

    public function test_rejects_invalid_mime(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        [$part, $chapter, $section] = $this->makePartChain(Certification::factory()->published()->create(), 'draft');

        $file = UploadedFile::fake()->create('script.svg', 10, 'image/svg+xml');

        $this->actingAs($admin)
            ->postJson(route('admin.sections.images.store', $section), ['file' => $file])
            ->assertStatus(422);
    }

    public function test_assigned_coach_can_upload_image_only_to_assigned_certification(): void
    {
        Storage::fake('public');

        $coach = User::factory()->coach()->create();
        $certX = Certification::factory()->published()->create();
        $certY = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certX);

        [, , $sectionX] = $this->makePartChain($certX, 'draft');
        [, , $sectionY] = $this->makePartChain($certY, 'draft');

        // 担当資格Xへのアップロードは許可
        $this->actingAs($coach)
            ->postJson(route('admin.sections.images.store', $sectionX), [
                'file' => UploadedFile::fake()->image('allowed.png'),
            ])
            ->assertCreated();

        $this->assertDatabaseHas('section_images', [
            'section_id' => $sectionX->id,
        ]);

        // 担当外資格Yへのアップロードは拒否
        $this->actingAs($coach)
            ->postJson(route('admin.sections.images.store', $sectionY), [
                'file' => UploadedFile::fake()->image('denied.png'),
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('section_images', [
            'section_id' => $sectionY->id,
        ]);
    }

    public function test_assigned_coach_can_delete_image_only_from_assigned_certification(): void
    {
        Storage::fake('public');

        $coach = User::factory()->coach()->create();
        $certX = Certification::factory()->published()->create();
        $certY = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certX);

        [, , $sectionX] = $this->makePartChain($certX, 'draft');
        [, , $sectionY] = $this->makePartChain($certY, 'draft');

        $imageX = SectionImage::factory()->forSection($sectionX)->create();
        $imageY = SectionImage::factory()->forSection($sectionY)->create();

        Storage::disk('public')->put($imageX->path, 'image X');
        Storage::disk('public')->put($imageY->path, 'image Y');

        // 担当資格Xの画像は削除できる
        $this->actingAs($coach)
            ->deleteJson(route('admin.section-images.destroy', $imageX))
            ->assertNoContent();

        $this->assertDatabaseMissing('section_images', [
            'id' => $imageX->id,
        ]);

        // 担当外資格Yの画像は削除できない
        $this->actingAs($coach)
            ->deleteJson(route('admin.section-images.destroy', $imageY))
            ->assertForbidden();

        // 担当外資格Yの画像が保持されている
        $this->assertDatabaseHas('section_images', [
            'id' => $imageY->id,
        ]);

        Storage::disk('public')->assertExists($imageY->path);
    }
}
