<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EditTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @dataProvider statusProvider
     */
    public function test_admin_can_view_edit_form_in_any_status(
        string $status,
    ): void {
        // Arrange
        $admin = User::factory()->admin()->create();

        $pack = MeetingPack::factory()->create([
            'status' => $status,
        ]);

        // Act
        $response = $this->actingAs($admin)
            ->get(route('admin.meeting-packs.edit', $pack));

        // Assert
        $response->assertOk();
        $response->assertViewIs(
            'meeting-pack.management.edit',
        );

        $response->assertViewHas(
            'plan',
            fn (MeetingPack $plan): bool => $plan->is($pack),
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

    public function test_edit_form_displays_existing_values(): void
    {
        $admin = User::factory()->admin()->create();

        $pack = MeetingPack::factory()->create([
            'name' => '編集確認用面談パック',
            'description' => '編集前の説明文です。',
            'meeting_count' => 7,
            'price' => 23456,
            'stripe_price_id' => 'price_edit_test',
            'sort_order' => 9876,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.meeting-packs.edit', $pack));

        $response->assertOk();

        $response->assertSee([
            $pack->name,
            $pack->description,
            $pack->stripe_price_id,
        ]);

        $response->assertSee('value="7"', false);
        $response->assertSee('value="23456"', false);
        $response->assertSee('value="9876"', false);
    }

    /**
     * @dataProvider unauthorizedRoleProvider
     */
    public function test_non_admin_cannot_view_edit_form(
        string $role,
    ): void {
        $user = User::factory()->create([
            'role' => $role,
        ]);

        $pack = MeetingPack::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('admin.meeting-packs.edit', $pack));

        $response->assertForbidden();
    }

    public static function unauthorizedRoleProvider(): array
    {
        return [
            'student' => ['student'],
            'coach' => ['coach'],
        ];
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $pack = MeetingPack::factory()->create();

        $response = $this->get(
            route('admin.meeting-packs.edit', $pack),
        );

        $response->assertRedirect('/login');
    }

    public function test_nonexistent_pack_returns_not_found(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->get(route(
                'admin.meeting-packs.edit',
                ['plan' => (string) Str::ulid()],
            ));

        $response->assertNotFound();
    }
}
