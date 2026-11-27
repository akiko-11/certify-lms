<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DestroyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @dataProvider deletableStatusProvider
     */
    public function test_admin_can_delete_pack(
        string $status,
    ): void {
        // Arrange
        $admin = User::factory()->admin()->create();

        $pack = MeetingPack::factory()->create([
            'status' => $status,
        ]);

        $otherPack = MeetingPack::factory()->create();

        // Act
        $response = $this->actingAs($admin)
            ->delete(
                route('admin.meeting-packs.destroy', $pack),
            );

        // Assert
        $response->assertRedirect(
            route('admin.meeting-packs.index'),
        );

        $response->assertSessionHas(
            'success',
            '面談パックを削除しました。',
        );

        $this->assertDatabaseMissing('meeting_packs', [
            'id' => $pack->id,
        ]);

        $this->assertDatabaseHas('meeting_packs', [
            'id' => $otherPack->id,
        ]);

        $this->assertDatabaseCount('meeting_packs', 1);
    }

    public static function deletableStatusProvider(): array
    {
        return [
            'draft' => ['draft'],
            'archived' => ['archived'],
        ];
    }

    /**
     * @dataProvider conflictFormatProvider
     */
    public function test_published_pack_cannot_be_deleted(bool $json): void
    {
        $admin = User::factory()->admin()->create();

        $pack = MeetingPack::factory()->published()->create([
            'updated_at' => now()->subDay(),
        ])->refresh();

        $before = $pack->getRawOriginal();

        $this->actingAs($admin);
        $response = $json
            ? $this->deleteJson(route('admin.meeting-packs.destroy', $pack))
            : $this->from(route('admin.meeting-packs.show', $pack))
                ->delete(route('admin.meeting-packs.destroy', $pack));

        $response->assertStatus(409);
        if ($json) {
            $response->assertJsonPath('message', '公開中の面談パックは削除できません。')
                ->assertSessionMissing('error');
        } else {
            $response->assertSessionHas('error', '公開中の面談パックは削除できません。');
        }
        $response->assertSessionMissing('success');

        $this->assertSame(
            $before,
            $pack->refresh()->getRawOriginal(),
        );

        $this->assertDatabaseCount('meeting_packs', 1);
    }

    public static function conflictFormatProvider(): array
    {
        return ['HTML' => [false], 'JSON' => [true]];
    }

    /**
     * @dataProvider unauthorizedRoleProvider
     */
    public function test_non_admin_cannot_delete_pack(
        string $role,
    ): void {
        $user = User::factory()->create([
            'role' => $role,
        ]);

        $pack = MeetingPack::factory()
            ->draft()
            ->create()
            ->refresh();

        $before = $pack->getRawOriginal();

        $response = $this->actingAs($user)
            ->delete(
                route('admin.meeting-packs.destroy', $pack),
            );

        $response->assertForbidden();

        $this->assertSame(
            $before,
            $pack->refresh()->getRawOriginal(),
        );

        $this->assertDatabaseCount('meeting_packs', 1);
    }

    public static function unauthorizedRoleProvider(): array
    {
        return [
            'student' => ['student'],
            'coach' => ['coach'],
        ];
    }

    public function test_guest_cannot_delete_pack(): void
    {
        $pack = MeetingPack::factory()
            ->draft()
            ->create()
            ->refresh();

        $before = $pack->getRawOriginal();

        $response = $this->delete(
            route('admin.meeting-packs.destroy', $pack),
        );

        $response->assertRedirect('/login');

        $this->assertSame(
            $before,
            $pack->refresh()->getRawOriginal(),
        );

        $this->assertDatabaseCount('meeting_packs', 1);
    }

    public function test_nonexistent_pack_returns_not_found(): void
    {
        $admin = User::factory()->admin()->create();

        $pack = MeetingPack::factory()
            ->draft()
            ->create()
            ->refresh();

        $before = $pack->getRawOriginal();

        $response = $this->actingAs($admin)
            ->delete(
                route('admin.meeting-packs.destroy', [
                    'plan' => (string) Str::ulid(),
                ]),
            );

        $response->assertNotFound();

        $this->assertSame(
            $before,
            $pack->refresh()->getRawOriginal(),
        );

        $this->assertDatabaseCount('meeting_packs', 1);
    }
}
