<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Http\Requests\MeetingPack\SaveRequest;
use App\Models\MeetingPack;
use App\Models\User;
use App\UseCases\MeetingPack\UpdateAction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UpdateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @dataProvider statusProvider
     */
    public function test_admin_can_update_pack_in_any_status(
        string $status,
    ): void {
        // Arrange
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->admin()->create();

        $pack = MeetingPack::factory()->create([
            'name' => '更新前の面談パック',
            'status' => $status,
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => $creator->id,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDay(),
        ])->refresh();

        $createdAt = $pack->created_at->copy();
        $updatedAt = $pack->updated_at->copy();

        $payload = $this->validPayload();

        // Act
        $response = $this->actingAs($admin)
            ->patch(
                route('admin.meeting-packs.update', $pack),
                $payload,
            );

        // Assert
        $response->assertRedirect(
            route('admin.meeting-packs.show', $pack),
        );

        $response->assertSessionHasNoErrors();

        $response->assertSessionHas(
            'success',
            '面談パックを更新しました。',
        );

        $this->assertDatabaseCount('meeting_packs', 1);

        $this->assertDatabaseHas('meeting_packs', [
            'id' => $pack->id,
            ...$payload,
            'status' => $status,
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => $admin->id,
        ]);

        $pack->refresh();

        $this->assertTrue(
            $pack->created_at->equalTo($createdAt),
        );

        $this->assertTrue(
            $pack->updated_at->greaterThan($updatedAt),
        );
    }

    public static function statusProvider(): array
    {
        return [
            'draft' => ['draft'],
            'published' => ['published'],
            'archived' => ['archived'],
        ];
    }

    public function test_full_update_uses_the_latest_record_after_route_binding(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = $this->validPayload();
        $pack = MeetingPack::factory()->draft()->create(['name' => $payload['name']]);

        MeetingPack::query()->findOrFail($pack->id)->update([
            'name' => '直前の操作で更新された名前',
            'status' => MeetingPackStatus::Published,
        ]);

        app(UpdateAction::class)($admin, $pack, $payload);

        $this->assertDatabaseHas('meeting_packs', [
            'id' => $pack->id,
            ...$payload,
            'status' => MeetingPackStatus::Published->value,
            'updated_by_user_id' => $admin->id,
        ]);
    }

    public function test_update_fails_if_pack_was_deleted_after_route_binding(): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->draft()->create();
        MeetingPack::query()->findOrFail($pack->id)->delete();

        $this->expectException(ModelNotFoundException::class);

        app(UpdateAction::class)($admin, $pack, $this->validPayload());
    }

    /**
     * @dataProvider optionalValuesProvider
     */
    public function test_optional_values_are_reset_to_defaults(
        array $optionalValues,
    ): void {
        $admin = User::factory()->admin()->create();

        $pack = MeetingPack::factory()->create([
            'description' => '更新前の説明',
            'stripe_price_id' => 'price_before_update',
            'sort_order' => 50,
        ]);

        $payload = array_merge([
            'name' => '更新後の面談パック',
            'meeting_count' => 10,
            'price' => 20000,
        ], $optionalValues);

        $response = $this->actingAs($admin)
            ->patch(
                route('admin.meeting-packs.update', $pack),
                $payload,
            );

        $response->assertRedirect(
            route('admin.meeting-packs.show', $pack),
        );

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('meeting_packs', [
            'id' => $pack->id,
            'name' => '更新後の面談パック',
            'meeting_count' => 10,
            'price' => 20000,
            'description' => null,
            'stripe_price_id' => null,
            'sort_order' => 0,
        ]);
    }

    public static function optionalValuesProvider(): array
    {
        return [
            'omitted optional values' => [
                [],
            ],
            'explicit null values' => [
                [
                    'description' => null,
                    'stripe_price_id' => null,
                    'sort_order' => null,
                ],
            ],
        ];
    }

    public function test_duplicate_name_and_stripe_price_id_are_allowed(): void
    {
        $admin = User::factory()->admin()->create();

        $payload = $this->validPayload();

        MeetingPack::factory()->create([
            'name' => $payload['name'],
            'stripe_price_id' => $payload['stripe_price_id'],
        ]);

        $pack = MeetingPack::factory()->create([
            'name' => '更新前のパック',
            'stripe_price_id' => 'price_before',
        ]);

        $response = $this->actingAs($admin)
            ->patch(
                route('admin.meeting-packs.update', $pack),
                $payload,
            );

        $response->assertRedirect(
            route('admin.meeting-packs.show', $pack),
        );

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseCount('meeting_packs', 2);

        $this->assertSame(
            2,
            MeetingPack::query()
                ->where('name', $payload['name'])
                ->where(
                    'stripe_price_id',
                    $payload['stripe_price_id'],
                )
                ->count(),
        );
    }

    public function test_client_supplied_audit_user_ids_are_ignored(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();

        $pack = MeetingPack::factory()->create([
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => $creator->id,
        ]);

        $payload = array_replace($this->validPayload(), [
            'created_by_user_id' => $otherAdmin->id,
            'updated_by_user_id' => $otherAdmin->id,
        ]);

        $response = $this->actingAs($admin)
            ->patch(
                route('admin.meeting-packs.update', $pack),
                $payload,
            );

        $response->assertRedirect(
            route('admin.meeting-packs.show', $pack),
        );

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('meeting_packs', [
            'id' => $pack->id,
            'name' => $payload['name'],
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => $admin->id,
        ]);
    }

    /**
     * @dataProvider unauthorizedRoleProvider
     */
    public function test_non_admin_cannot_update_pack(
        string $role,
    ): void {
        $user = User::factory()->create([
            'role' => $role,
        ]);

        $pack = MeetingPack::factory()->create();
        $before = $pack->refresh()->getRawOriginal();

        $response = $this->actingAs($user)
            ->patch(
                route('admin.meeting-packs.update', $pack),
                $this->validPayload(),
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
            'student' => ['student'],
            'coach' => ['coach'],
        ];
    }

    public function test_guest_cannot_update_pack(): void
    {
        $pack = MeetingPack::factory()->create();
        $before = $pack->refresh()->getRawOriginal();

        $response = $this->patch(
            route('admin.meeting-packs.update', $pack),
            $this->validPayload(),
        );

        $response->assertRedirect('/login');

        $this->assertSame(
            $before,
            $pack->refresh()->getRawOriginal(),
        );
    }

    public function test_nonexistent_pack_cannot_be_updated(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->patch(
                route('admin.meeting-packs.update', [
                    'plan' => (string) Str::ulid(),
                ]),
                $this->validPayload(),
            );

        $response->assertNotFound();

        $this->assertDatabaseCount('meeting_packs', 0);
    }

    /**
     * @dataProvider invalidPayloadProvider
     */
    public function test_invalid_payload_is_rejected_without_updating(
        string $field,
        mixed $value,
    ): void {
        $admin = User::factory()->admin()->create();

        $pack = MeetingPack::factory()->create();
        $before = $pack->refresh()->getRawOriginal();

        $payload = array_replace(
            $this->validPayload(),
            [$field => $value],
        );

        $response = $this->actingAs($admin)
            ->from(route('admin.meeting-packs.edit', $pack))
            ->patch(
                route('admin.meeting-packs.update', $pack),
                $payload,
            );

        $response->assertRedirect(
            route('admin.meeting-packs.edit', $pack),
        );

        $response->assertSessionHas(SaveRequest::formStateKey($pack).'.errors.'.$field);

        $this->assertSame(
            $before,
            $pack->refresh()->getRawOriginal(),
        );

        $this->assertDatabaseCount('meeting_packs', 1);
    }

    public static function invalidPayloadProvider(): array
    {
        return [
            'name is null' => ['name', null],
            'name is empty' => ['name', ''],
            'name is whitespace' => ['name', '   '],
            'name exceeds 100 characters' => [
                'name', str_repeat('あ', 101),
            ],
            'name is array' => ['name', ['invalid']],

            'description exceeds 2000 characters' => [
                'description', str_repeat('あ', 2001),
            ],
            'description is array' => [
                'description', ['invalid'],
            ],

            'meeting count is null' => ['meeting_count', null],
            'meeting count is zero' => ['meeting_count', 0],
            'meeting count is negative' => ['meeting_count', -1],
            'meeting count exceeds 100' => ['meeting_count', 101],
            'meeting count is decimal' => ['meeting_count', 1.5],
            'meeting count is string' => ['meeting_count', 'invalid'],
            'meeting count is array' => ['meeting_count', [1]],

            'price is null' => ['price', null],
            'price is negative' => ['price', -1],
            'price exceeds maximum' => ['price', 1000001],
            'price is decimal' => ['price', 1.5],
            'price is string' => ['price', 'invalid'],
            'price is array' => ['price', [1000]],

            'stripe price id exceeds 255 characters' => [
                'stripe_price_id', str_repeat('a', 256),
            ],
            'stripe price id is array' => [
                'stripe_price_id', ['invalid'],
            ],

            'sort order is negative' => ['sort_order', -1],
            'sort order exceeds 9999' => ['sort_order', 10000],
            'sort order is decimal' => ['sort_order', 1.5],
            'sort order is string' => ['sort_order', 'invalid'],
            'sort order is array' => ['sort_order', [1]],

            'status is draft' => ['status', 'draft'],
            'status is published' => ['status', 'published'],
            'status is archived' => ['status', 'archived'],
            'status is invalid' => ['status', 'invalid'],
            'status is null' => ['status', null],
            'status is empty' => ['status', ''],
            'status is array' => ['status', ['draft']],
        ];
    }

    /**
     * @dataProvider requiredFieldProvider
     */
    public function test_missing_required_field_is_rejected(
        string $field,
    ): void {
        $admin = User::factory()->admin()->create();

        $pack = MeetingPack::factory()->create();
        $before = $pack->refresh()->getRawOriginal();

        $payload = $this->validPayload();

        unset($payload[$field]);

        $response = $this->actingAs($admin)
            ->from(route('admin.meeting-packs.edit', $pack))
            ->patch(
                route('admin.meeting-packs.update', $pack),
                $payload,
            );

        $response->assertRedirect(
            route('admin.meeting-packs.edit', $pack),
        );

        $response->assertSessionHas(SaveRequest::formStateKey($pack).'.errors.'.$field);

        $this->assertSame(
            $before,
            $pack->refresh()->getRawOriginal(),
        );
    }

    public static function requiredFieldProvider(): array
    {
        return [
            'name' => ['name'],
            'meeting count' => ['meeting_count'],
            'price' => ['price'],
        ];
    }

    /**
     * @dataProvider validBoundaryProvider
     */
    public function test_valid_boundary_values_are_accepted(
        string $field,
        mixed $value,
    ): void {
        $admin = User::factory()->admin()->create();

        $pack = MeetingPack::factory()->create();

        $payload = array_replace(
            $this->validPayload(),
            [$field => $value],
        );

        $response = $this->actingAs($admin)
            ->patch(
                route('admin.meeting-packs.update', $pack),
                $payload,
            );

        $response->assertRedirect(
            route('admin.meeting-packs.show', $pack),
        );

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('meeting_packs', [
            'id' => $pack->id,
            ...$payload,
        ]);
    }

    public static function validBoundaryProvider(): array
    {
        return [
            'name minimum' => ['name', 'あ'],
            'name maximum' => ['name', str_repeat('あ', 100)],
            'description maximum' => [
                'description', str_repeat('あ', 2000),
            ],
            'meeting count minimum' => ['meeting_count', 1],
            'meeting count maximum' => ['meeting_count', 100],
            'price minimum' => ['price', 0],
            'price maximum' => ['price', 1000000],
            'stripe price id maximum' => [
                'stripe_price_id', str_repeat('a', 255),
            ],
            'sort order minimum' => ['sort_order', 0],
            'sort order maximum' => ['sort_order', 9999],
        ];
    }

    private function validPayload(): array
    {
        return [
            'name' => '更新後の面談10回パック',
            'description' => '更新後の説明文です。',
            'meeting_count' => 10,
            'price' => 20000,
            'stripe_price_id' => 'price_updated_test',
            'sort_order' => 20,
        ];
    }
}
