<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaThread;

use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_can_delete_thread_without_replies(): void
    {
        $author = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()
            ->for($certification, 'certification')
            ->for($author, 'user')
            ->create();

        $this->actingAs($author)
            ->delete(route('qa-board.destroy', $thread))
            ->assertRedirect(route('qa-board.index'))
            ->assertSessionHas('success', '質問を削除しました。');

        $this->assertDatabaseMissing('qa_threads', [
            'id' => $thread->id,
        ]);
    }

    public function test_author_cannot_delete_thread_with_replies(): void
    {
        $author = User::factory()->student()->create();
        $responder = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()
            ->for($certification, 'certification')
            ->for($author, 'user')
            ->create();

        $reply = QaReply::factory()
            ->for($thread, 'thread')
            ->for($responder, 'user')
            ->create();

        $this->actingAs($author)
            ->delete(route('qa-board.destroy', $thread))
            ->assertStatus(409)
            ->assertSessionHas(
                'error',
                '回答が付いているスレッドは削除できません。'
            );

        // スレッド・回答ともに残っている
        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
        ]);

        $this->assertDatabaseHas('qa_replies', [
            'id' => $reply->id,
        ]);
    }

    public function test_other_student_and_coach_cannot_delete_thread(): void
    {
        $author = User::factory()->student()->create();
        $otherStudent = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();

        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()
            ->for($certification, 'certification')
            ->for($author, 'user')
            ->create();

        foreach ([$otherStudent, $coach] as $user) {
            $this->actingAs($user)
                ->delete(route('qa-board.destroy', $thread))
                ->assertForbidden();
        }

        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
        ]);
    }

    public function test_author_cannot_delete_thread_of_archived_certification(): void
    {
        $author = User::factory()->student()->create();
        $certification = Certification::factory()->archived()->create();

        $thread = QaThread::factory()
            ->for($certification, 'certification')
            ->for($author, 'user')
            ->create();

        $this->actingAs($author)
            ->delete(route('qa-board.destroy', $thread))
            ->assertForbidden();

        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
        ]);
    }

    public function test_admin_can_delete_archived_thread_with_replies(): void
    {
        $admin = User::factory()->admin()->create();
        $author = User::factory()->student()->create();
        $responder = User::factory()->student()->create();

        $certification = Certification::factory()->archived()->create();

        $thread = QaThread::factory()
            ->for($certification, 'certification')
            ->for($author, 'user')
            ->create();

        $reply = QaReply::factory()
            ->for($thread, 'thread')
            ->for($responder, 'user')
            ->create();

        $this->actingAs($admin)
            ->delete(route('admin.qa-board.destroy', $thread))
            ->assertRedirect(route('admin.qa-board.index'))
            ->assertSessionHas('success', '質問を削除しました。');

        // スレッドが削除されている
        $this->assertDatabaseMissing('qa_threads', [
            'id' => $thread->id,
        ]);

        // 紐づく回答も削除されている
        $this->assertDatabaseMissing('qa_replies', [
            'id' => $reply->id,
        ]);
    }

    public function test_student_and_coach_cannot_use_admin_delete_route(): void
    {
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();

        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()
            ->for($certification, 'certification')
            ->for($student, 'user')
            ->create();

        foreach ([$student, $coach] as $user) {
            $this->actingAs($user)
                ->delete(route('admin.qa-board.destroy', $thread))
                ->assertForbidden();
        }

        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
        ]);
    }

    public function test_other_student_cannot_delete_thread_with_replies(): void
    {
        $author = User::factory()->student()->create();
        $otherStudent = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()
            ->for($certification, 'certification')
            ->for($author, 'user')
            ->create();

        QaReply::factory()
            ->for($thread, 'thread')
            ->for($author, 'user')
            ->create();

        $this->actingAs($otherStudent)
            ->delete(route('qa-board.destroy', $thread))
            ->assertForbidden();

        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
        ]);
    }
}
