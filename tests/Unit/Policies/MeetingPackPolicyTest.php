<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Enums\MeetingPackStatus;
use App\Enums\UserRole;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class MeetingPackPolicyTest extends TestCase
{
    public function test_all_abilities_are_restricted_to_administrators(): void
    {
        foreach (UserRole::cases() as $role) {
            $gate = Gate::forUser(new User(['role' => $role]));
            $allowed = $role === UserRole::Admin;

            foreach (['viewAny', 'create'] as $ability) {
                $this->assertSame($allowed, $gate->allows($ability, MeetingPack::class), "{$role->value}: {$ability}");
            }

            foreach (MeetingPackStatus::cases() as $status) {
                $pack = new MeetingPack(['status' => $status]);

                foreach (['view', 'update', 'publish', 'archive', 'unarchive', 'delete'] as $ability) {
                    $this->assertSame($allowed, $gate->allows($ability, $pack), "{$role->value}: {$ability} ({$status->value})");
                }
            }
        }
    }
}
