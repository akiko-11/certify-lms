<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @dataProvider statusProvider
     */
    public function test_admin_can_view_pack_in_any_status(
        string $status,
        string $label,
        array $actions,
    ): void {
        // Arrange
        $admin = User::factory()->admin()->create();

        $pack = MeetingPack::factory()->create([
            'status' => $status,
        ]);

        // Act
        $response = $this->actingAs($admin)
            ->get(route('admin.meeting-packs.show', $pack));

        // Assert
        $response->assertOk();
        $response->assertViewIs('meeting-pack.management.show');

        $response->assertViewHas(
            'plan',
            fn (MeetingPack $plan): bool => $plan->is($pack),
        );

        $response->assertSee($label);
        $response->assertSee(route('admin.meeting-packs.edit', $pack));

        foreach (['publish', 'archive', 'unarchive', 'destroy'] as $action) {
            $formAction = 'action="'.route("admin.meeting-packs.{$action}", $pack).'"';

            if (in_array($action, $actions, true)) {
                $response->assertSee($formAction, false);
            } else {
                $response->assertDontSee($formAction, false);
            }
        }
    }

    public static function statusProvider(): array
    {
        return [
            'draft' => ['draft', '下書き', ['publish', 'destroy']],
            'published' => ['published', '公開中', ['archive']],
            'archived' => ['archived', 'アーカイブ', ['unarchive', 'destroy']],
        ];
    }

    public function test_detail_displays_basic_and_metadata(): void
    {
        $admin = User::factory()->admin()->create();

        $creator = User::factory()->admin()->create([
            'name' => '面談パック作成者',
        ]);

        $updater = User::factory()->admin()->create([
            'name' => '面談パック更新者',
        ]);

        $pack = MeetingPack::factory()->create([
            'name' => '追加面談5回パック',
            'description' => '詳細確認用の説明文です。',
            'meeting_count' => 5,
            'price' => 12345,
            'stripe_price_id' => 'price_detail_test',
            'sort_order' => 9876,
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => $updater->id,
            'created_at' => '2026-01-01 10:00:00',
            'updated_at' => '2026-01-02 15:30:00',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.meeting-packs.show', $pack));

        $response->assertOk();

        $response->assertSee([
            $pack->name,
            $pack->description,
            '面談回数',
            '価格',
            '¥12,345',
            '税込',
            '面談パック作成者',
            '面談パック更新者',
            'price_detail_test',
            '9876',
            '2026-01-01 10:00',
            '2026-01-02 15:30',
        ]);

        $this->assertSame(
            5,
            $response->viewData('plan')->meeting_count,
        );
    }

    public function test_detail_displays_empty_purchase_history(): void
    {
        $admin = User::factory()->admin()->create();

        $pack = MeetingPack::factory()->create([
            'description' => null,
            'stripe_price_id' => null,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.meeting-packs.show', $pack));

        $response->assertOk();

        $response->assertSee(
            'この SKU の購入はまだありません。',
        );

        $response->assertSee('動的生成');
    }

    /**
     * @dataProvider unauthorizedRoleProvider
     */
    public function test_non_admin_cannot_view_detail(
        string $role,
    ): void {
        $user = User::factory()->create([
            'role' => $role,
        ]);

        $pack = MeetingPack::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('admin.meeting-packs.show', $pack));

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
            route('admin.meeting-packs.show', $pack),
        );

        $response->assertRedirect('/login');

        $this->get(route('admin.meeting-packs.show', ['plan' => (string) Str::ulid()]))
            ->assertRedirect('/login');
    }

    /**
     * @dataProvider authenticatedRoleProvider
     */
    public function test_nonexistent_pack_returns_not_found(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $response = $this->actingAs($user)
            ->get(route(
                'admin.meeting-packs.show',
                ['plan' => (string) Str::ulid()],
            ));

        $response->assertNotFound();
    }

    public static function authenticatedRoleProvider(): array
    {
        return ['admin' => ['admin']] + self::unauthorizedRoleProvider();
    }
}
