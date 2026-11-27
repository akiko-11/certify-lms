<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Http\Requests\MeetingPack\SaveRequest;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_store_meeting_pack(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();

        $payload = $this->validPayload();

        // Act
        $response = $this->actingAs($admin)
            ->post(route('admin.meeting-packs.store'), $payload);

        // Assert
        $this->assertDatabaseCount('meeting_packs', 1);

        $pack = MeetingPack::query()->sole();

        $response->assertRedirect(
            route('admin.meeting-packs.show', $pack),
        );

        $response->assertSessionHas(
            'success',
            '面談パックを作成しました。',
        );

        $this->assertDatabaseHas('meeting_packs', [
            'id' => $pack->id,
            'name' => $payload['name'],
            'description' => $payload['description'],
            'meeting_count' => $payload['meeting_count'],
            'price' => $payload['price'],
            'stripe_price_id' => $payload['stripe_price_id'],
            'sort_order' => $payload['sort_order'],
            'status' => MeetingPackStatus::Draft->value,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);
    }

    public function test_admin_can_store_with_required_fields_only(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->post(route('admin.meeting-packs.store'), [
                'name' => '面談1回パック',
                'meeting_count' => 1,
                'price' => 3000,
            ]);

        $this->assertDatabaseCount('meeting_packs', 1);

        $pack = MeetingPack::query()->sole();

        $response->assertRedirect(
            route('admin.meeting-packs.show', $pack),
        );

        $this->assertDatabaseHas('meeting_packs', [
            'id' => $pack->id,
            'description' => null,
            'stripe_price_id' => null,
            'sort_order' => 0,
            'status' => MeetingPackStatus::Draft->value,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);
    }

    public function test_optional_null_values_are_saved_with_defaults(): void
    {
        $admin = User::factory()->admin()->create();

        $payload = array_replace($this->validPayload(), [
            'description' => null,
            'stripe_price_id' => null,
            'sort_order' => null,
        ]);

        $response = $this->actingAs($admin)
            ->post(route('admin.meeting-packs.store'), $payload);

        $this->assertDatabaseCount('meeting_packs', 1);

        $pack = MeetingPack::query()->sole();

        $response->assertRedirect(
            route('admin.meeting-packs.show', $pack),
        );

        $this->assertDatabaseHas('meeting_packs', [
            'id' => $pack->id,
            'description' => null,
            'stripe_price_id' => null,
            'sort_order' => 0,
        ]);
    }

    public function test_duplicate_name_and_stripe_price_id_are_allowed(): void
    {
        $admin = User::factory()->admin()->create();

        $payload = $this->validPayload();

        MeetingPack::factory()->create([
            'name' => $payload['name'],
            'stripe_price_id' => $payload['stripe_price_id'],
        ]);

        $response = $this->actingAs($admin)
            ->post(route('admin.meeting-packs.store'), $payload);

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseCount('meeting_packs', 2);

        $this->assertSame(
            2,
            MeetingPack::query()
                ->where('name', $payload['name'])
                ->where('stripe_price_id', $payload['stripe_price_id'])
                ->count(),
        );
    }

    public function test_client_supplied_audit_user_ids_are_ignored(): void
    {
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();

        $payload = array_replace($this->validPayload(), [
            'created_by_user_id' => $otherAdmin->id,
            'updated_by_user_id' => $otherAdmin->id,
        ]);

        $response = $this->actingAs($admin)
            ->post(route('admin.meeting-packs.store'), $payload);

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseCount('meeting_packs', 1);

        $this->assertDatabaseHas('meeting_packs', [
            'name' => $payload['name'],
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);
    }

    private function validPayload(): array
    {
        return [
            'name' => '面談5回パック',
            'description' => '追加面談用のパックです。',
            'meeting_count' => 5,
            'price' => 12000,
            'stripe_price_id' => 'price_test_001',
            'sort_order' => 10,
        ];
    }

    /**
     * @dataProvider unauthorizedRoleProvider
     */
    public function test_non_admin_cannot_store_meeting_pack(
        string $role,
    ): void {
        $user = User::factory()->create([
            'role' => $role,
        ]);

        $response = $this->actingAs($user)
            ->post(
                route('admin.meeting-packs.store'),
                $this->validPayload(),
            );

        $response->assertForbidden();

        $this->assertDatabaseCount('meeting_packs', 0);
    }

    public static function unauthorizedRoleProvider(): array
    {
        return [
            'student' => ['student'],
            'coach' => ['coach'],
        ];
    }

    public function test_guest_cannot_store_meeting_pack(): void
    {
        $response = $this->post(
            route('admin.meeting-packs.store'),
            $this->validPayload(),
        );

        $response->assertRedirect('/login');

        $this->assertDatabaseCount('meeting_packs', 0);
    }

    /**
     * @dataProvider validBoundaryProvider
     */
    public function test_valid_boundary_values_are_accepted(
        array $values,
    ): void {
        $admin = User::factory()->admin()->create();

        $payload = array_replace(
            $this->validPayload(),
            $values,
        );

        $response = $this->actingAs($admin)
            ->post(route('admin.meeting-packs.store'), $payload);

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseCount('meeting_packs', 1);

        $pack = MeetingPack::query()->sole();

        $response->assertRedirect(
            route('admin.meeting-packs.show', $pack),
        );

        $this->assertDatabaseHas('meeting_packs', $payload);
    }

    public static function validBoundaryProvider(): array
    {
        return [
            'minimum name' => [
                ['name' => 'あ'],
            ],
            'maximum name' => [
                ['name' => str_repeat('あ', 100)],
            ],
            'maximum description' => [
                ['description' => str_repeat('あ', 2000)],
            ],
            'minimum meeting count' => [
                ['meeting_count' => 1],
            ],
            'maximum meeting count' => [
                ['meeting_count' => 100],
            ],
            'minimum price' => [
                ['price' => 0],
            ],
            'maximum price' => [
                ['price' => 1000000],
            ],
            'maximum stripe price id' => [
                ['stripe_price_id' => str_repeat('a', 255)],
            ],
            'minimum sort order' => [
                ['sort_order' => 0],
            ],
            'maximum sort order' => [
                ['sort_order' => 9999],
            ],
        ];
    }

    /**
     * @dataProvider invalidPayloadProvider
     */
    public function test_invalid_payload_is_rejected(
        string $field,
        mixed $value,
    ): void {
        $admin = User::factory()->admin()->create();

        $payload = array_replace($this->validPayload(), [
            $field => $value,
        ]);

        $response = $this->actingAs($admin)
            ->from(route('admin.meeting-packs.create'))
            ->post(route('admin.meeting-packs.store'), $payload);

        $response->assertRedirect(
            route('admin.meeting-packs.create'),
        );

        $response->assertSessionHas(SaveRequest::formStateKey().'.errors.'.$field);

        $this->assertDatabaseCount('meeting_packs', 0);
    }

    public static function invalidPayloadProvider(): array
    {
        return [
            // name
            'name is null' => ['name', null],
            'name is empty' => ['name', ''],
            'name exceeds 100 characters' => [
                'name', str_repeat('あ', 101),
            ],
            'name is array' => ['name', ['test']],

            // description
            'description exceeds 2000 characters' => [
                'description', str_repeat('あ', 2001),
            ],
            'description is array' => [
                'description', ['test'],
            ],

            // meeting_count
            'meeting count is null' => ['meeting_count', null],
            'meeting count is zero' => ['meeting_count', 0],
            'meeting count is negative' => ['meeting_count', -1],
            'meeting count exceeds 100' => ['meeting_count', 101],
            'meeting count is decimal' => ['meeting_count', '1.5'],
            'meeting count is string' => ['meeting_count', 'abc'],
            'meeting count is array' => ['meeting_count', [5]],

            // price
            'price is null' => ['price', null],
            'price is negative' => ['price', -1],
            'price exceeds maximum' => ['price', 1000001],
            'price is decimal' => ['price', '3000.5'],
            'price is string' => ['price', 'abc'],
            'price is array' => ['price', [3000]],

            // stripe_price_id
            'stripe price id exceeds 255 characters' => [
                'stripe_price_id', str_repeat('a', 256),
            ],
            'stripe price id is array' => [
                'stripe_price_id', ['price_test'],
            ],

            // sort_order
            'sort order is negative' => ['sort_order', -1],
            'sort order exceeds maximum' => ['sort_order', 10000],
            'sort order is decimal' => ['sort_order', '1.5'],
            'sort order is string' => ['sort_order', 'abc'],
            'sort order is array' => ['sort_order', [10]],

            // status
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

        $payload = $this->validPayload();

        unset($payload[$field]);

        $response = $this->actingAs($admin)
            ->from(route('admin.meeting-packs.create'))
            ->post(route('admin.meeting-packs.store'), $payload);

        $response->assertRedirect(
            route('admin.meeting-packs.create'),
        );

        $response->assertSessionHas(SaveRequest::formStateKey().'.errors.'.$field);

        $this->assertDatabaseCount('meeting_packs', 0);
    }

    public static function requiredFieldProvider(): array
    {
        return [
            'name' => ['name'],
            'meeting count' => ['meeting_count'],
            'price' => ['price'],
        ];
    }
}
