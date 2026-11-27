<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 質問掲示板の回答に対する投稿・編集・削除の認可を検証する。
 */
class QaReplyPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_reply_to_published_thread_even_if_resolved(): void
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $unresolvedThread = QaThread::factory()
            ->for($certification, 'certification')
            ->unresolved()
            ->create();

        $resolvedThread = QaThread::factory()
            ->for($certification, 'certification')
            ->resolved()
            ->create();

        // 未解決・解決済みのどちらにも回答できる
        $this->assertTrue(
            $student->can('create', [QaReply::class, $unresolvedThread])
        );

        $this->assertTrue(
            $student->can('create', [QaReply::class, $resolvedThread])
        );
    }

    public function test_coach_can_reply_only_to_assigned_certification(): void
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

        // 担当資格への回答は許可
        $this->assertTrue(
            $coach->can('create', [QaReply::class, $assignedThread])
        );

        // 担当外の資格への回答は拒否
        $this->assertFalse(
            $coach->can('create', [QaReply::class, $otherThread])
        );

        // 担当解除後は回答できない
        CertificationCoachAssignment::query()
            ->where('certification_id', $assignedCertification->id)
            ->where('user_id', $coach->id)
            ->update(['unassigned_at' => now()]);

        $this->assertFalse(
            $coach->can('create', [QaReply::class, $assignedThread->fresh()])
        );
    }

    public function test_student_and_coach_cannot_reply_to_unpublished_thread(): void
    {
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();

        $certification = Certification::factory()->archived()->create();

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

        // 公開停止中は受講生・担当コーチともに回答不可
        $this->assertFalse(
            $student->can('create', [QaReply::class, $thread])
        );

        $this->assertFalse(
            $coach->can('create', [QaReply::class, $thread])
        );

        // 管理者は公開状態にかかわらず回答できない
        $this->assertFalse(
            $admin->can('create', [QaReply::class, $thread])
        );
    }

    public function test_only_reply_author_can_update_and_delete_reply(): void
    {
        $author = User::factory()->student()->create();
        $otherStudent = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();

        $thread = QaThread::factory()->create();

        $reply = QaReply::factory()
            ->for($thread, 'thread')
            ->for($author, 'user')
            ->create();

        // 投稿者本人は編集・削除可能
        $this->assertTrue($author->can('update', $reply));
        $this->assertTrue($author->can('delete', $reply));

        // 他の受講生は編集・削除不可
        $this->assertFalse($otherStudent->can('update', $reply));
        $this->assertFalse($otherStudent->can('delete', $reply));

        // コーチは他人の回答を編集・削除できない
        $this->assertFalse($coach->can('update', $reply));
        $this->assertFalse($coach->can('delete', $reply));

        // 管理者は編集不可、削除のみ可能
        $this->assertFalse($admin->can('update', $reply));
        $this->assertTrue($admin->can('delete', $reply));
    }

    public function test_coach_can_edit_own_reply_only_while_assigned(): void
    {
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();

        $certification = Certification::factory()->published()->create();

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

        $reply = QaReply::factory()
            ->for($thread, 'thread')
            ->for($coach, 'user')
            ->create();

        // 担当中は自分の回答を編集・削除できる
        $this->assertTrue($coach->can('update', $reply));
        $this->assertTrue($coach->can('delete', $reply));

        // 担当解除
        CertificationCoachAssignment::query()
            ->where('certification_id', $certification->id)
            ->where('user_id', $coach->id)
            ->update(['unassigned_at' => now()]);

        // 担当解除後は自分の回答でも操作できない
        $this->assertFalse($coach->can('update', $reply->fresh()));
        $this->assertFalse($coach->can('delete', $reply->fresh()));
    }

    public function test_admin_can_delete_archived_reply_but_cannot_edit_it(): void
    {
        $student = User::factory()->student()->create();
        $admin = User::factory()->admin()->create();

        $certification = Certification::factory()->archived()->create();

        $thread = QaThread::factory()
            ->for($certification, 'certification')
            ->create();

        $reply = QaReply::factory()
            ->for($thread, 'thread')
            ->for($student, 'user')
            ->create();

        // 公開停止中は投稿者本人も回答を操作できない
        $this->assertFalse($student->can('update', $reply));
        $this->assertFalse($student->can('delete', $reply));

        // 管理者は公開停止中でも削除できる
        $this->assertTrue($admin->can('delete', $reply));

        // 管理者による内容の編集は禁止
        $this->assertFalse($admin->can('update', $reply));
    }
}
