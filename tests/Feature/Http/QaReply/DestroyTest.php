<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaReply;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_delete_own_reply(): void
    {
        $student = User::factory()->student()->create();

        [$thread, $reply] = $this->createThreadAndReply($student);

        $this->actingAs($student)
            ->delete(route('qa-board.replies.destroy', [$thread, $reply]))
            ->assertRedirect(route('qa-board.show', $thread))
            ->assertSessionHas('success', '回答を削除しました。');

        // 回答は削除される
        $this->assertDatabaseMissing('qa_replies', [
            'id' => $reply->id,
        ]);

        // スレッドは残る
        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
        ]);
    }

    public function test_assigned_coach_can_delete_own_reply(): void
    {
        $coach = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();

        $this->assignCoach($certification, $coach);

        [$thread, $reply] = $this->createThreadAndReply(
            $coach,
            $certification
        );

        $this->actingAs($coach)
            ->delete(route('qa-board.replies.destroy', [$thread, $reply]))
            ->assertRedirect(route('qa-board.show', $thread))
            ->assertSessionHas('success', '回答を削除しました。');

        $this->assertDatabaseMissing('qa_replies', [
            'id' => $reply->id,
        ]);

        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
        ]);
    }

    public function test_other_users_cannot_delete_reply(): void
    {
        $author = User::factory()->student()->create();
        $otherStudent = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();

        $certification = Certification::factory()->published()->create();

        // 担当コーチでも他人の回答は削除できない
        $this->assignCoach($certification, $coach);

        [$thread, $reply] = $this->createThreadAndReply(
            $author,
            $certification
        );

        foreach ([$otherStudent, $coach, $admin] as $user) {
            $this->actingAs($user)
                ->delete(route('qa-board.replies.destroy', [$thread, $reply]))
                ->assertForbidden();
        }

        // 不正な削除操作では回答が残る
        $this->assertDatabaseHas('qa_replies', [
            'id' => $reply->id,
            'user_id' => $author->id,
        ]);
    }

    public function test_coach_cannot_delete_own_reply_after_unassignment(): void
    {
        $coach = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();

        $assignment = $this->assignCoach($certification, $coach);

        [$thread, $reply] = $this->createThreadAndReply(
            $coach,
            $certification
        );

        // 担当解除する
        CertificationCoachAssignment::query()
            ->whereKey($assignment->id)
            ->update([
                'unassigned_at' => now(),
            ]);

        $this->actingAs($coach)
            ->delete(route('qa-board.replies.destroy', [$thread, $reply]))
            ->assertForbidden();

        // 担当解除後も過去の回答は残る
        $this->assertDatabaseHas('qa_replies', [
            'id' => $reply->id,
            'user_id' => $coach->id,
        ]);
    }

    public function test_author_cannot_delete_reply_of_archived_thread(): void
    {
        $author = User::factory()->student()->create();
        $certification = Certification::factory()->archived()->create();

        [$thread, $reply] = $this->createThreadAndReply(
            $author,
            $certification
        );

        $this->actingAs($author)
            ->delete(route('qa-board.replies.destroy', [$thread, $reply]))
            ->assertForbidden();

        $this->assertDatabaseHas('qa_replies', [
            'id' => $reply->id,
        ]);
    }

    public function test_admin_can_delete_reply_of_archived_thread(): void
    {
        $admin = User::factory()->admin()->create();
        $author = User::factory()->student()->create();
        $certification = Certification::factory()->archived()->create();

        [$thread, $reply] = $this->createThreadAndReply(
            $author,
            $certification
        );

        $this->actingAs($admin)
            ->delete(route('admin.qa-board.replies.destroy', [$thread, $reply]))
            ->assertRedirect(route('admin.qa-board.show', $thread))
            ->assertSessionHas('success', '回答を削除しました。');

        // 管理者によるモデレーション削除
        $this->assertDatabaseMissing('qa_replies', [
            'id' => $reply->id,
        ]);

        // スレッドそのものは削除しない
        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
        ]);
    }

    public function test_student_and_coach_cannot_use_admin_delete_route(): void
    {
        $author = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();

        [$thread, $reply] = $this->createThreadAndReply($author);

        foreach ([$author, $coach] as $user) {
            $this->actingAs($user)
                ->delete(route('admin.qa-board.replies.destroy', [$thread, $reply]))
                ->assertForbidden();
        }

        $this->assertDatabaseHas('qa_replies', [
            'id' => $reply->id,
        ]);
    }

    public function test_mismatched_thread_and_reply_returns_not_found(): void
    {
        $author = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        [$thread, $reply] = $this->createThreadAndReply(
            $author,
            $certification
        );

        // 回答が所属していない別のスレッド
        $otherThread = QaThread::factory()
            ->for($certification, 'certification')
            ->for($author, 'user')
            ->create();

        $this->actingAs($author)
            ->delete(route('qa-board.replies.destroy', [$otherThread, $reply]))
            ->assertNotFound();

        // 元のスレッドと回答は残る
        $this->assertDatabaseHas('qa_replies', [
            'id' => $reply->id,
            'qa_thread_id' => $thread->id,
        ]);

        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
        ]);
    }

    /**
     * テスト用のスレッドと回答を作成する。
     */
    private function createThreadAndReply(
        User $replyAuthor,
        ?Certification $certification = null
    ): array {
        $certification ??= Certification::factory()->published()->create();

        $threadAuthor = User::factory()->student()->create();

        $thread = QaThread::factory()
            ->for($certification, 'certification')
            ->for($threadAuthor, 'user')
            ->create();

        $reply = QaReply::factory()
            ->for($thread, 'thread')
            ->for($replyAuthor, 'user')
            ->create([
                'body' => '削除対象の回答です。',
            ]);

        return [$thread, $reply];
    }

    /**
     * テスト用にコーチを資格へ割り当てる。
     */
    private function assignCoach(
        Certification $certification,
        User $coach
    ): CertificationCoachAssignment {
        $admin = User::factory()->admin()->create();

        return CertificationCoachAssignment::query()->create([
            'id' => (string) Str::ulid(),
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);
    }
}
