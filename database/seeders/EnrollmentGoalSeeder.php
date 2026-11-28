<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\EnrollmentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use Illuminate\Database\Seeder;
use Symfony\Component\Uid\Ulid;

/** EnrollmentSeeder で作成済みの受講登録に動作確認用の個人目標を追加する。 */
final class EnrollmentGoalSeeder extends Seeder
{
    private const FIXED_STUDENT = 'student@certify-lms.test';

    /**
     * @param list<string> $demoEnrollmentIds 確認用と特定済みの受講登録ID。単体実行時の既定値は固定受講生のみ。
     */
    public function run(array $demoEnrollmentIds = []): void
    {
        $fixed = Enrollment::query()
            ->whereHas('user', fn ($query) => $query->where('email', self::FIXED_STUDENT))
            ->whereHas('certification', fn ($query) => $query->where('name', '基本情報技術者試験'))
            ->orderBy('id')
            ->first();

        if ($fixed !== null) {
            $this->seedGoal($fixed, 'practice', [
                'title' => '過去問を5年分解く',
                'description' => "解き終わったら苦手分野を整理する。\n間違えた問題は翌週に解き直す。",
                'target_date' => today()->addDays(14)->toDateString(),
            ]);
            $this->seedGoal($fixed, 'review', [
                'title' => '基礎分野の教材を一周する',
                'achieved_at' => now()->subDays(2),
            ]);
        }

        $demoEnrollments = Enrollment::query()
            ->whereKey($demoEnrollmentIds)
            ->whereHas('user', fn ($query) => $query
                ->where('role', UserRole::Student)
                ->where('status', UserStatus::InProgress)
                ->whereNotIn('email', [self::FIXED_STUDENT, 'student-noquota@certify-lms.test']))
            ->get();

        foreach ($demoEnrollments as $enrollment) {
            $this->seedGoal($enrollment, 'demo', [
                'title' => '学習内容を振り返って要点をまとめる',
                'achieved_at' => $enrollment->status === EnrollmentStatus::Passed ? $enrollment->passed_at ?? now() : null,
            ]);
        }
    }

    private function seedGoal(Enrollment $enrollment, string $sample, array $attributes): void
    {
        // サンプル自身のIDを固定し、受講生が編集した内容にも再実行で上書きしない。
        $timestamp = substr((new Ulid($enrollment->id))->toBinary(), 0, 6);
        $suffix = substr(hash('sha256', 'enrollment-goal:'.$enrollment->id.':'.$sample, true), 0, 10);
        $id = Ulid::fromBinary($timestamp.$suffix)->toBase32();

        EnrollmentGoal::unguarded(fn () => $enrollment->goals()->firstOrCreate(['id' => $id], $attributes));
    }
}
