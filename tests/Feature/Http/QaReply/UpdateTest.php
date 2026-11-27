<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaReply;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_can_view_reply_edit_form(): void
    {
        $author = User::factory()->student()->create();

        [$thread, $reply] = $this->createThreadAndReply($author);

        $this->actingAs($author)
            ->get(route('qa-board.replies.edit', [$thread, $reply]))
            ->assertOk()
            ->assertViewIs('qa-thread.reply-edit')
            ->assertViewHas('thread', function ($viewThread) use ($thread) {
                return $viewThread->id === $thread->id;
            })
            ->assertViewHas('reply', function ($viewReply) use ($reply) {
                return $viewReply->id === $reply->id;
            });
    }

    public function test_author_can_update_reply_body(): void
    {
        $author = User::factory()->student()->create();

        [$thread, $reply] = $this->createThreadAndReply($author);

        $this->actingAs($author)
            ->patch(route('qa-board.replies.update', [$thread, $reply]), [
                'body' => '更新後の回答です。',
            ])
            ->assertRedirect(route('qa-board.show', $thread))
            ->assertSessionHas('success', '回答を更新しました。');

        $this->assertDatabaseHas('qa_replies', [
            'id' => $reply->id,
            'qa_thread_id' => $thread->id,
            'user_id' => $author->id,
            'body' => '更新後の回答です。',
        ]);
    }

    public function test_other_users_cannot_edit_reply(): void
    {
        $author = User::factory()->student()->create();
        $otherStudent = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();

        $certification = Certification::factory()->published()->create();

        // 担当コーチであっても他人の回答は編集できない
        $this->assignCoach($certification, $coach);

        [$thread, $reply] = $this->createThreadAndReply(
            $author,
            $certification
        );

        foreach ([$otherStudent, $coach, $admin] as $user) {
            $this->actingAs($user)
                ->get(route('qa-board.replies.edit', [$thread, $reply]))
                ->assertForbidden();

            $this->actingAs($user)
                ->patch(route('qa-board.replies.update', [$thread, $reply]), [
                    'body' => '不正な更新です。',
                ])
                ->assertForbidden();
        }

        $this->assertDatabaseHas('qa_replies', [
            'id' => $reply->id,
            'body' => '更新前の回答です。',
        ]);
    }

    public function test_author_cannot_edit_reply_of_archived_thread(): void
    {
        $author = User::factory()->student()->create();
        $certification = Certification::factory()->archived()->create();

        [$thread, $reply] = $this->createThreadAndReply(
            $author,
            $certification
        );

        $this->actingAs($author)
            ->get(route('qa-board.replies.edit', [$thread, $reply]))
            ->assertForbidden();

        $this->actingAs($author)
            ->patch(route('qa-board.replies.update', [$thread, $reply]), [
                'body' => '更新後の回答です。',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('qa_replies', [
            'id' => $reply->id,
            'body' => '更新前の回答です。',
        ]);
    }

    public function test_coach_cannot_edit_own_reply_after_unassignment(): void
    {
        $coach = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();

        $assignment = $this->assignCoach($certification, $coach);

        [$thread, $reply] = $this->createThreadAndReply(
            $coach,
            $certification
        );

        // 担当中は編集画面を表示できる
        $this->actingAs($coach)
            ->get(route('qa-board.replies.edit', [$thread, $reply]))
            ->assertOk();

        // 担当解除
        CertificationCoachAssignment::query()
            ->whereKey($assignment->id)
            ->update([
                'unassigned_at' => now(),
            ]);

        // 担当解除後は編集できない
        $this->actingAs($coach)
            ->get(route('qa-board.replies.edit', [$thread, $reply]))
            ->assertForbidden();

        $this->actingAs($coach)
            ->patch(route('qa-board.replies.update', [$thread, $reply]), [
                'body' => '担当解除後の更新です。',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('qa_replies', [
            'id' => $reply->id,
            'body' => '更新前の回答です。',
        ]);
    }

    public function test_invalid_update_is_rejected_without_saving(): void
    {
        $author = User::factory()->student()->create();

        [$thread, $reply] = $this->createThreadAndReply($author);

        $invalidBodies = [
            [
                'body' => '',
                'message' => '回答を入力してください。',
            ],
            [
                'body' => ['不正な値'],
                'message' => '回答は文字列で入力してください。',
            ],
            [
                'body' => str_repeat('あ', 5001),
                'message' => '回答は5000文字以内で入力してください。',
            ],
        ];

        foreach ($invalidBodies as $case) {
            $this->actingAs($author)
                ->from(route('qa-board.replies.edit', [$thread, $reply]))
                ->patch(route('qa-board.replies.update', [$thread, $reply]), [
                    'body' => $case['body'],
                ])
                ->assertRedirect(
                    route('qa-board.replies.edit', [$thread, $reply])
                )
                ->assertSessionHasErrors([
                    'body' => $case['message'],
                ]);

            // 不正な入力で更新されていない
            $this->assertDatabaseHas('qa_replies', [
                'id' => $reply->id,
                'body' => '更新前の回答です。',
            ]);
        }
    }

    public function test_author_can_update_reply_at_maximum_length(): void
    {
        $author = User::factory()->student()->create();

        [$thread, $reply] = $this->createThreadAndReply($author);

        $body = str_repeat('あ', 5000);

        $this->actingAs($author)
            ->patch(route('qa-board.replies.update', [$thread, $reply]), [
                'body' => $body,
            ])
            ->assertRedirect(route('qa-board.show', $thread));

        $this->assertDatabaseHas('qa_replies', [
            'id' => $reply->id,
            'body' => $body,
        ]);
    }

    public function test_author_can_edit_reply_of_resolved_thread(): void
    {
        $author = User::factory()->student()->create();

        [$thread, $reply] = $this->createThreadAndReply(
            $author,
            resolved: true
        );

        $this->actingAs($author)
            ->patch(route('qa-board.replies.update', [$thread, $reply]), [
                'body' => '解決済みスレッドの回答を更新しました。',
            ])
            ->assertRedirect(route('qa-board.show', $thread));

        $this->assertDatabaseHas('qa_replies', [
            'id' => $reply->id,
            'body' => '解決済みスレッドの回答を更新しました。',
        ]);

        // 回答編集によって解決状態は変更されない
        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
            'status' => QaThreadStatus::Resolved->value,
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
            ->get(route('qa-board.replies.edit', [$otherThread, $reply]))
            ->assertNotFound();

        $this->actingAs($author)
            ->patch(route('qa-board.replies.update', [$otherThread, $reply]), [
                'body' => '別のスレッドからの不正な更新です。',
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('qa_replies', [
            'id' => $reply->id,
            'qa_thread_id' => $thread->id,
            'body' => '更新前の回答です。',
        ]);
    }

    /**
     * テスト用のスレッドと回答を作成する。
     */
    private function createThreadAndReply(
        User $replyAuthor,
        ?Certification $certification = null,
        bool $resolved = false
    ): array {
        $certification ??= Certification::factory()->published()->create();

        $threadAuthor = User::factory()->student()->create();

        $factory = QaThread::factory()
            ->for($certification, 'certification')
            ->for($threadAuthor, 'user');

        $thread = $resolved
            ? $factory->resolved()->create()
            : $factory->unresolved()->create();

        $reply = QaReply::factory()
            ->for($thread, 'thread')
            ->for($replyAuthor, 'user')
            ->create([
                'body' => '更新前の回答です。',
            ]);

        return [$thread, $reply];
    }

    /**
     * テスト用に資格へコーチを割り当てる。
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
