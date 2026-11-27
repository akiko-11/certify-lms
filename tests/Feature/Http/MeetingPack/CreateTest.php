<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_create_form(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();

        // Act
        $response = $this->actingAs($admin)
            ->get(route('admin.meeting-packs.create'));

        // Assert
        $response->assertOk();
        $response->assertViewIs(
            'meeting-pack.management.create',
        );
    }

    public function test_student_cannot_view_create_form(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
        ]);

        $response = $this->actingAs($student)
            ->get(route('admin.meeting-packs.create'));

        $response->assertForbidden();
    }

    public function test_coach_cannot_view_create_form(): void
    {
        $coach = User::factory()->create([
            'role' => 'coach',
        ]);

        $response = $this->actingAs($coach)
            ->get(route('admin.meeting-packs.create'));

        $response->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get(
            route('admin.meeting-packs.create'),
        );

        $response->assertRedirect('/login');
    }
}
