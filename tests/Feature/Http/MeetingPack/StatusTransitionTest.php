<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @dataProvider validTransitionProvider
     */
    public function test_admin_can_transition_status(
        string $operation,
        string $from,
        string $to,
        string $message,
    ): void {
        // Arrange
        $admin = User::factory()->admin()->create();

        $pack = MeetingPack::factory()->create([
            'status' => $from,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDay(),
        ])->refresh();

        $before = $pack->getRawOriginal();
        $updatedAt = $pack->updated_at->copy();

        // Act
        $response = $this->actingAs($admin)
            ->post(
                route("admin.meeting-packs.{$operation}", $pack),
            );

        // Assert
        $response->assertRedirect(
            route('admin.meeting-packs.show', $pack),
        );

        $response->assertSessionHas('success', $message);

        $this->assertDatabaseCount('meeting_packs', 1);

        $pack->refresh();

        $this->assertTrue(
            $pack->updated_at->greaterThan($updatedAt),
        );

        $expected = array_replace($before, [
            'status' => $to,
            'updated_by_user_id' => $admin->id,
            'updated_at' => $pack->getRawOriginal('updated_at'),
        ]);

        $this->assertSame(
            $expected,
            $pack->getRawOriginal(),
        );
    }

    public static function validTransitionProvider(): array
    {
        return [
            'draft to published' => [
                'publish',
                'draft',
                'published',
                '面談パックを公開しました。',
            ],
            'published to archived' => [
                'archive',
                'published',
                'archived',
                '面談パックをアーカイブしました。',
            ],
            'archived to draft' => [
                'unarchive',
                'archived',
                'draft',
                '面談パックを下書きに戻しました。',
            ],
        ];
    }

    /**
     * @dataProvider invalidTransitionProvider
     */
    public function test_invalid_transition_returns_conflict(
        string $operation,
        string $status,
    ): void {
        $admin = User::factory()->admin()->create();

        $pack = MeetingPack::factory()->create([
            'status' => $status,
            'updated_at' => now()->subDay(),
        ])->refresh();

        $before = $pack->getRawOriginal();

        $response = $this->actingAs($admin)
            ->from(route('admin.meeting-packs.show', $pack))
            ->post(
                route("admin.meeting-packs.{$operation}", $pack),
            );

        $response->assertStatus(409);
        $response->assertSessionHas('error');
        $response->assertSessionMissing('success');

        $this->assertSame(
            $before,
            $pack->refresh()->getRawOriginal(),
        );

        $this->assertDatabaseCount('meeting_packs', 1);
    }

    public static function invalidTransitionProvider(): array
    {
        return [
            'publish published' => ['publish', 'published'],
            'publish archived' => ['publish', 'archived'],
            'archive draft' => ['archive', 'draft'],
            'archive archived' => ['archive', 'archived'],
            'unarchive draft' => ['unarchive', 'draft'],
            'unarchive published' => ['unarchive', 'published'],
        ];
    }

    public function test_invalid_json_transition_returns_conflict_without_changing_the_pack(): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->published()->create(['updated_at' => now()->subDay()])->refresh();
        $before = $pack->getRawOriginal();

        $this->actingAs($admin)->postJson(route('admin.meeting-packs.publish', $pack))
            ->assertConflict()
            ->assertJsonPath('message', '下書き状態の面談パックのみ公開できます。')
            ->assertSessionMissing('error');

        $this->assertSame($before, $pack->refresh()->getRawOriginal());
    }

    /**
     * @dataProvider unauthorizedRoleProvider
     */
    public function test_non_admin_cannot_transition_status(
        string $role,
        string $operation,
        string $status,
    ): void {
        $user = User::factory()->create([
            'role' => $role,
        ]);

        $pack = MeetingPack::factory()->create([
            'status' => $status,
        ])->refresh();

        $before = $pack->getRawOriginal();

        $response = $this->actingAs($user)
            ->post(
                route("admin.meeting-packs.{$operation}", $pack),
            );

        $response->assertForbidden();

        $this->assertSame(
            $before,
            $pack->refresh()->getRawOriginal(),
        );
    }

    public static function unauthorizedRoleProvider(): array
    {
        return [
            'student publish' => ['student', 'publish', 'draft'],
            'student archive' => ['student', 'archive', 'published'],
            'student unarchive' => ['student', 'unarchive', 'archived'],
            'coach publish' => ['coach', 'publish', 'draft'],
            'coach archive' => ['coach', 'archive', 'published'],
            'coach unarchive' => ['coach', 'unarchive', 'archived'],
        ];
    }

    /**
     * @dataProvider operationProvider
     */
    public function test_guest_cannot_transition_status(
        string $operation,
        string $status,
    ): void {
        $pack = MeetingPack::factory()->create([
            'status' => $status,
        ])->refresh();

        $before = $pack->getRawOriginal();

        $response = $this->post(
            route("admin.meeting-packs.{$operation}", $pack),
        );

        $response->assertRedirect('/login');

        $this->assertSame(
            $before,
            $pack->refresh()->getRawOriginal(),
        );
    }

    /**
     * @dataProvider operationProvider
     */
    public function test_nonexistent_pack_returns_not_found(
        string $operation,
        string $status,
    ): void {
        $admin = User::factory()->admin()->create();

        $pack = MeetingPack::factory()->create([
            'status' => $status,
        ])->refresh();

        $before = $pack->getRawOriginal();

        $response = $this->actingAs($admin)
            ->post(
                route("admin.meeting-packs.{$operation}", [
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

    public static function operationProvider(): array
    {
        return [
            'publish' => ['publish', 'draft'],
            'archive' => ['archive', 'published'],
            'unarchive' => ['unarchive', 'archived'],
        ];
    }

    /**
     * @dataProvider publishBoundaryProvider
     */
    public function test_admin_can_publish_with_optional_conditions(
        array $attributes,
    ): void {
        $admin = User::factory()->admin()->create();

        $pack = MeetingPack::factory()->draft()->create(
            $attributes,
        );

        $response = $this->actingAs($admin)
            ->post(
                route('admin.meeting-packs.publish', $pack),
            );

        $response->assertRedirect(
            route('admin.meeting-packs.show', $pack),
        );

        $response->assertSessionHas(
            'success',
            '面談パックを公開しました。',
        );

        $this->assertDatabaseHas('meeting_packs', [
            'id' => $pack->id,
            ...$attributes,
            'status' => 'published',
            'updated_by_user_id' => $admin->id,
        ]);
    }

    public static function publishBoundaryProvider(): array
    {
        return [
            'without stripe price id' => [
                ['stripe_price_id' => null],
            ],
            'zero price' => [
                ['price' => 0],
            ],
        ];
    }
}
