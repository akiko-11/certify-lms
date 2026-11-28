<?php

declare(strict_types=1);

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $seededUserIds = $this->seedUsers();

        $this->call([
            PlanSeeder::class,
            UserLifecycleSeeder::class,
            MeetingPackSeeder::class,
            CertificationCategorySeeder::class,
            CertificationSeeder::class,
            InvitationSeeder::class,
        ]);

        $this->callWith(EnrollmentSeeder::class, ['seededUserIds' => $seededUserIds]);

        $this->call([
            MentoringSeeder::class,
            ContentSeeder::class,
            LearningSeeder::class,
            QuizAnsweringSeeder::class,
            MockExamSeeder::class,
            ChatSeeder::class,
            ChatSeeder::class,
            QaThreadSeeder::class,
            CertificateSeeder::class,
        ]);
    }

    /** @return list<string> */
    private function seedUsers(): array
    {
        $seededUserIds = [];
        $dispatcher = User::getEventDispatcher();
        User::setEventDispatcher(clone $dispatcher);

        try {
            // デモの由来をDBの属性から推測せず、この UserSeeder の生成結果だけを渡す。
            User::created(static function (User $user) use (&$seededUserIds): void {
                $seededUserIds[] = $user->id;
            });
            $this->call(UserSeeder::class);
        } finally {
            User::setEventDispatcher($dispatcher);
        }

        return $seededUserIds;
    }
}
