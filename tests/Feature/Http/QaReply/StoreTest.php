<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaReply;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_reply_to_published_thread_without_enrollment(): void
    {
        $student = User::factory()->student()->create();
        $author = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()
            ->for($certification, 'certification')
            ->for($author, 'user')
            ->create();

        $this->actingAs($student)
            ->post(route('qa-board.replies.store', $thread), [
                'body' => '回答の本文です。',
            ])
            ->assertRedirect(route('qa-board.show', $thread))
            ->assertSessionHas('success', '回答を投稿しました。');

        $this->assertDatabaseHas('qa_replies', [
            'qa_thread_id' => $thread->id,
            'user_id' => $student->id,
            'body' => '回答の本文です。',
        ]);
    }

    public function test_assigned_coach_can_reply_to_published_thread(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $author = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        CertificationCoachAssignment::query()->create([
            'id' => (string) Str::ulid(),
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        $thread = QaThread::factory()
            ->for($certification, 'certification')
            ->for($author, 'user')
            ->create();

        $this->actingAs($coach)
            ->post(route('qa-board.replies.store', $thread), [
                'body' => '担当コーチからの回答です。',
            ])
            ->assertRedirect(route('qa-board.show', $thread));

        $this->assertDatabaseHas('qa_replies', [
            'qa_thread_id' => $thread->id,
            'user_id' => $coach->id,
            'body' => '担当コーチからの回答です。',
        ]);
    }

    public function test_unassigned_coach_cannot_reply(): void
    {
        $coach = User::factory()->coach()->create();
        $author = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()
            ->for($certification, 'certification')
            ->for($author, 'user')
            ->create();

        $this->actingAs($coach)
            ->post(route('qa-board.replies.store', $thread), [
                'body' => '担当外資格への回答です。',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('qa_replies', 0);
    }

    public function test_student_can_reply_to_resolved_thread(): void
    {
        $student = User::factory()->student()->create();
        $author = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()
            ->resolved()
            ->for($certification, 'certification')
            ->for($author, 'user')
            ->create();

        $this->actingAs($student)
            ->post(route('qa-board.replies.store', $thread), [
                'body' => '解決済みスレッドへの補足回答です。',
            ])
            ->assertRedirect(route('qa-board.show', $thread));

        $this->assertDatabaseHas('qa_replies', [
            'qa_thread_id' => $thread->id,
            'user_id' => $student->id,
            'body' => '解決済みスレッドへの補足回答です。',
        ]);

        // 回答を追加しても解決状態は変更しない
        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
            'status' => QaThreadStatus::Resolved->value,
        ]);
    }

    public function test_student_cannot_reply_to_archived_thread(): void
    {
        $student = User::factory()->student()->create();
        $author = User::factory()->student()->create();
        $certification = Certification::factory()->archived()->create();

        $thread = QaThread::factory()
            ->for($certification, 'certification')
            ->for($author, 'user')
            ->create();

        $this->actingAs($student)
            ->post(route('qa-board.replies.store', $thread), [
                'body' => '公開停止中のスレッドへの回答です。',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('qa_replies', 0);
    }

    public function test_admin_cannot_reply(): void
    {
        $admin = User::factory()->admin()->create();
        $author = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()
            ->for($certification, 'certification')
            ->for($author, 'user')
            ->create();

        $this->actingAs($admin)
            ->post(route('qa-board.replies.store', $thread), [
                'body' => '管理者からの回答です。',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('qa_replies', 0);
    }

    public function test_invalid_reply_body_is_rejected_without_saving(): void
    {
        $student = User::factory()->student()->create();
        $author = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()
            ->for($certification, 'certification')
            ->for($author, 'user')
            ->create();

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
            $this->actingAs($student)
                ->from(route('qa-board.show', $thread))
                ->post(route('qa-board.replies.store', $thread), [
                    'body' => $case['body'],
                ])
                ->assertRedirect(route('qa-board.show', $thread))
                ->assertSessionHasErrors([
                    'body' => $case['message'],
                ]);

            // バリデーションエラー時は保存されない
            $this->assertDatabaseCount('qa_replies', 0);
        }
    }

    public function test_student_can_reply_at_maximum_length(): void
    {
        $student = User::factory()->student()->create();
        $author = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()
            ->for($certification, 'certification')
            ->for($author, 'user')
            ->create();

        $body = str_repeat('あ', 5000);

        $this->actingAs($student)
            ->post(route('qa-board.replies.store', $thread), [
                'body' => $body,
            ])
            ->assertRedirect(route('qa-board.show', $thread));

        $this->assertDatabaseHas('qa_replies', [
            'qa_thread_id' => $thread->id,
            'user_id' => $student->id,
            'body' => $body,
        ]);
    }
}
