<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\QaThreadStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 質問掲示板の開発用初期データを生成するSeeder。
 *
 * 公開中の資格ごとに解決状態・回答数・投稿日時の異なる質問を作成し、
 * 固定受講生による投稿も用意する。
 */
final class QaThreadSeeder extends Seeder
{
    public function run(): void
    {
        $fixedStudent = User::query()
            ->where('email', 'student@certify-lms.test')
            ->first();

        $students = User::query()
            ->where('role', UserRole::Student->value)
            ->where('status', UserStatus::InProgress->value)
            ->get();

        $certifications = Certification::query()
            ->published()
            ->with('coaches')
            ->get();

        if ($fixedStudent === null || $students->isEmpty() || $certifications->isEmpty()) {
            $this->command?->warn('QaThreadSeeder: 必要な受講生または資格が存在しません。');

            return;
        }

        $scenarios = [
            ['status' => QaThreadStatus::Unresolved, 'replies' => 0],
            ['status' => QaThreadStatus::Resolved, 'replies' => 2],
            ['status' => QaThreadStatus::Unresolved, 'replies' => 3],
            ['status' => QaThreadStatus::Resolved, 'replies' => 1],
        ];

        $titles = [
            '学習の進め方について質問があります',
            'この分野の理解を深めたいです',
            '問題の解き方について教えてください',
            '学習方法について確認したいです',
        ];

        foreach ($certifications as $certIndex => $certification) {
            foreach ($scenarios as $index => $scenario) {
                $createdAt = now()
                    ->subDays(20 - ($certIndex * 4 + $index))
                    ->subHours($index);

                $isResolved = $scenario['status'] === QaThreadStatus::Resolved;

                $resolvedAt = $isResolved
                    ? $createdAt->copy()->addHours(6)
                    : null;

                $author = in_array($index, [0, 3], true)
                    ? $fixedStudent
                    : $students[($certIndex + $index) % $students->count()];

                $thread = QaThread::factory()->create([
                    'certification_id' => $certification->id,
                    'user_id' => $author->id,
                    'title' => $certification->name.'：'.$titles[$index],
                    'body' => 'この資格の学習について質問があります。理解を深めるために、考え方や学習方法を教えていただきたいです。',
                    'status' => $scenario['status']->value,
                    'resolved_at' => $resolvedAt,
                ]);

                $thread->forceFill([
                    'created_at' => $createdAt,
                    'updated_at' => $resolvedAt ?? $createdAt,
                ])->save();

                for ($i = 0; $i < $scenario['replies']; $i++) {
                    $coach = $certification->coaches->first();

                    $replyAuthor = $i % 2 === 0 && $coach !== null
                        ? $coach
                        : $students[($certIndex + $index + $i + 1) % $students->count()];

                    $reply = QaReply::factory()->create([
                        'qa_thread_id' => $thread->id,
                        'user_id' => $replyAuthor->id,
                        'body' => 'まず関連する教材を確認し、基本的な考え方を整理すると理解しやすくなると思います。',
                    ]);

                    $replyAt = $createdAt->copy()->addHours($i + 1);

                    $reply->forceFill([
                        'created_at' => $replyAt,
                        'updated_at' => $replyAt,
                    ])->save();
                }
            }
        }
    }
}
