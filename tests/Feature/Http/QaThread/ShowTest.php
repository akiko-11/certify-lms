<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaThread;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 質問スレッド詳細画面の表示・認可・回答一覧を検証する。
 */
class ShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_view_published_thread_without_enrollment(): void
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()
            ->for($certification, 'certification')
            ->create([
                'title' => '資格学習についての質問',
                'body' => '教材の解説について確認したいことがあります。',
            ]);

        // 受講登録は存在しない
        $this->assertDatabaseMissing('enrollments', [
            'user_id' => $student->id,
            'certification_id' => $certification->id,
        ]);

        $this->actingAs($student)
            ->get(route('qa-board.show', $thread))
            ->assertOk()
            ->assertViewIs('qa-thread.show')
            ->assertViewHas('thread', function ($viewThread) use ($thread) {
                return $viewThread->id === $thread->id;
            })
            ->assertSeeText('資格学習についての質問')
            ->assertSeeText('教材の解説について確認したいことがあります。');
    }

    public function test_coach_can_view_only_assigned_published_thread(): void
    {
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();

        $assignedCertification = Certification::factory()->published()->create();
        $otherCertification = Certification::factory()->published()->create();

        CertificationCoachAssignment::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $assignedCertification->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        $assignedThread = QaThread::factory()
            ->for($assignedCertification, 'certification')
            ->create();

        $otherThread = QaThread::factory()
            ->for($otherCertification, 'certification')
            ->create();

        // 担当資格は閲覧可能
        $this->actingAs($coach)
            ->get(route('qa-board.show', $assignedThread))
            ->assertOk();

        // 担当外の資格は閲覧不可
        $this->actingAs($coach)
            ->get(route('qa-board.show', $otherThread))
            ->assertForbidden();

        // 担当解除
        CertificationCoachAssignment::query()
            ->where('certification_id', $assignedCertification->id)
            ->where('user_id', $coach->id)
            ->update(['unassigned_at' => now()]);

        // 担当解除後は閲覧不可
        $this->actingAs($coach)
            ->get(route('qa-board.show', $assignedThread))
            ->assertForbidden();
    }

    public function test_unpublished_thread_is_hidden_from_student_and_coach(): void
    {
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();

        foreach (['draft', 'archived'] as $state) {
            $certification = Certification::factory()
                ->{$state}()
                ->create();

            // コーチを担当者に設定
            CertificationCoachAssignment::create([
                'id' => (string) Str::ulid(),
                'certification_id' => $certification->id,
                'user_id' => $coach->id,
                'assigned_by_user_id' => $admin->id,
                'assigned_at' => now(),
            ]);

            $thread = QaThread::factory()
                ->for($certification, 'certification')
                ->create();

            // 受講生は閲覧不可
            $this->actingAs($student)
                ->get(route('qa-board.show', $thread))
                ->assertNotFound();

            // 担当コーチでも公開停止中は閲覧不可
            $this->actingAs($coach)
                ->get(route('qa-board.show', $thread))
                ->assertNotFound();

            // 管理者は閲覧可能
            $this->actingAs($admin)
                ->get(route('admin.qa-board.show', $thread))
                ->assertOk();
        }
    }

    public function test_thread_detail_displays_replies_and_reply_count(): void
    {
        $student = User::factory()->student()->create();
        $replyAuthor1 = User::factory()->student()->create();
        $replyAuthor2 = User::factory()->coach()->create();

        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()
            ->for($certification, 'certification')
            ->create();

        QaReply::factory()
            ->for($thread, 'thread')
            ->for($replyAuthor1, 'user')
            ->create([
                'body' => '1件目の回答内容です。',
            ]);

        QaReply::factory()
            ->for($thread, 'thread')
            ->for($replyAuthor2, 'user')
            ->create([
                'body' => '2件目の回答内容です。',
            ]);

        $this->actingAs($student)
            ->get(route('qa-board.show', $thread))
            ->assertOk()
            ->assertViewHas('thread', function ($viewThread) use ($thread) {
                return $viewThread->id === $thread->id
                    && (int) $viewThread->replies_count === 2
                    && $viewThread->replies->count() === 2;
            })
            ->assertSeeText('1件目の回答内容です。')
            ->assertSeeText('2件目の回答内容です。');
    }

    public function test_nonexistent_thread_returns_not_found(): void
    {
        $student = User::factory()->student()->create();

        $nonexistentId = (string) Str::ulid();

        $this->actingAs($student)
            ->get(route('qa-board.show', [
                'thread' => $nonexistentId,
            ]))
            ->assertNotFound();
    }
}
