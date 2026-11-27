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
 * 質問スレッドの閲覧・投稿・編集・削除・解決状態変更の認可を検証する。
 */
class QaThreadPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_students_can_create_threads(): void
    {
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();

        // 一覧へのアクセスは3ロールとも可能
        $this->assertTrue($student->can('viewAny', QaThread::class));
        $this->assertTrue($coach->can('viewAny', QaThread::class));
        $this->assertTrue($admin->can('viewAny', QaThread::class));

        // 質問の新規投稿は受講生のみ可能
        $this->assertTrue($student->can('create', QaThread::class));
        $this->assertFalse($coach->can('create', QaThread::class));
        $this->assertFalse($admin->can('create', QaThread::class));
    }

    public function test_student_can_view_published_threads_without_enrollment(): void
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()
            ->for($certification, 'certification')
            ->create();

        // 受講登録のない資格でも、公開済みなら閲覧可能
        $this->assertTrue($student->can('view', $thread));
    }

    public function test_student_and_coach_cannot_view_unpublished_threads(): void
    {
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();

        $certifications = [
            Certification::factory()->draft()->create(),
            Certification::factory()->archived()->create(),
        ];

        foreach ($certifications as $certification) {
            $thread = QaThread::factory()
                ->for($certification, 'certification')
                ->create();

            $this->assertFalse($student->can('view', $thread));
            $this->assertFalse($coach->can('view', $thread));
            $this->assertTrue($admin->can('view', $thread));
        }
    }

    public function test_coach_can_view_only_assigned_published_threads(): void
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

        // 担当資格のみ閲覧可能
        $this->assertTrue($coach->can('view', $assignedThread));
        $this->assertFalse($coach->can('view', $otherThread));

        // 担当解除後は閲覧できない
        CertificationCoachAssignment::query()
            ->where('certification_id', $assignedCertification->id)
            ->where('user_id', $coach->id)
            ->update(['unassigned_at' => now()]);

        $this->assertFalse($coach->can('view', $assignedThread->fresh()));
    }

    public function test_only_author_can_update_and_change_resolution(): void
    {
        $author = User::factory()->student()->create();
        $otherStudent = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();

        $thread = QaThread::factory()
            ->for($author, 'user')
            ->create();

        foreach (['update', 'resolve', 'unresolve'] as $ability) {
            $this->assertTrue($author->can($ability, $thread));

            $this->assertFalse($otherStudent->can($ability, $thread));
            $this->assertFalse($coach->can($ability, $thread));
            $this->assertFalse($admin->can($ability, $thread));
        }
    }

    public function test_author_cannot_modify_unpublished_thread(): void
    {
        $author = User::factory()->student()->create();
        $certification = Certification::factory()->archived()->create();

        $thread = QaThread::factory()
            ->for($certification, 'certification')
            ->for($author, 'user')
            ->create();

        $this->assertFalse($author->can('update', $thread));
        $this->assertFalse($author->can('resolve', $thread));
        $this->assertFalse($author->can('unresolve', $thread));
        $this->assertFalse($author->can('delete', $thread));
    }

    public function test_author_can_delete_only_threads_without_replies(): void
    {
        $author = User::factory()->student()->create();
        $otherStudent = User::factory()->student()->create();
        $admin = User::factory()->admin()->create();

        $thread = QaThread::factory()
            ->for($author, 'user')
            ->create();

        // 回答0件：投稿者本人は削除可能
        $this->assertTrue($author->can('delete', $thread));
        $this->assertFalse($otherStudent->can('delete', $thread));

        // 回答を追加
        QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $otherStudent->id,
        ]);

        // 回答がある場合、投稿者本人は削除できない
        $this->assertFalse($author->can('delete', $thread));

        // 管理者は回答が存在しても削除できる
        $this->assertTrue($admin->can('delete', $thread));
    }
}
