<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_can_resolve_thread(): void
    {
        $author = User::factory()->student()->create();
        $responder = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()
            ->unresolved()
            ->for($certification, 'certification')
            ->for($author, 'user')
            ->create();

        $reply = QaReply::factory()
            ->for($thread, 'thread')
            ->for($responder, 'user')
            ->create();

        $this->actingAs($author)
            ->post(route('qa-board.resolve', $thread))
            ->assertRedirect(route('qa-board.show', $thread))
            ->assertSessionHas('success', '質問を解決済みにしました。');

        // 解決済みに変更されている
        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
            'status' => QaThreadStatus::Resolved->value,
        ]);

        // 解決日時が設定されている
        $this->assertNotNull($thread->fresh()->resolved_at);

        // 既存の回答は削除されない
        $this->assertDatabaseHas('qa_replies', [
            'id' => $reply->id,
            'qa_thread_id' => $thread->id,
        ]);
    }

    public function test_author_can_unresolve_thread(): void
    {
        $author = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()
            ->resolved()
            ->for($certification, 'certification')
            ->for($author, 'user')
            ->create();

        $this->actingAs($author)
            ->post(route('qa-board.unresolve', $thread))
            ->assertRedirect(route('qa-board.show', $thread))
            ->assertSessionHas('success', '質問を未解決に戻しました。');

        // 未解決に変更され、解決日時がクリアされている
        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
            'status' => QaThreadStatus::Unresolved->value,
            'resolved_at' => null,
        ]);
    }

    public function test_resolving_already_resolved_thread_keeps_resolution_date(): void
    {
        $author = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()
            ->resolved()
            ->for($certification, 'certification')
            ->for($author, 'user')
            ->create();

        // 変更前の解決日時を取得
        $originalResolvedAt = $thread->fresh()
            ->getRawOriginal('resolved_at');

        $this->assertNotNull($originalResolvedAt);

        $this->actingAs($author)
            ->post(route('qa-board.resolve', $thread))
            ->assertRedirect(route('qa-board.show', $thread));

        $thread->refresh();

        // 解決済みのまま
        $this->assertSame(
            QaThreadStatus::Resolved,
            $thread->status
        );

        // 解決日時は変更されない
        $this->assertSame(
            $originalResolvedAt,
            $thread->getRawOriginal('resolved_at')
        );
    }

    public function test_unresolving_already_unresolved_thread_keeps_status(): void
    {
        $author = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()
            ->unresolved()
            ->for($certification, 'certification')
            ->for($author, 'user')
            ->create();

        $this->actingAs($author)
            ->post(route('qa-board.unresolve', $thread))
            ->assertRedirect(route('qa-board.show', $thread));

        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
            'status' => QaThreadStatus::Unresolved->value,
            'resolved_at' => null,
        ]);
    }

    public function test_other_users_cannot_change_resolution_status(): void
    {
        $author = User::factory()->student()->create();
        $otherStudent = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();

        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()
            ->unresolved()
            ->for($certification, 'certification')
            ->for($author, 'user')
            ->create();

        foreach ([$otherStudent, $coach, $admin] as $user) {
            // 解決済みへの変更を拒否
            $this->actingAs($user)
                ->post(route('qa-board.resolve', $thread))
                ->assertForbidden();

            // 未解決への変更も拒否
            $this->actingAs($user)
                ->post(route('qa-board.unresolve', $thread))
                ->assertForbidden();
        }

        // 解決状態が変更されていない
        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
            'status' => QaThreadStatus::Unresolved->value,
            'resolved_at' => null,
        ]);
    }

    public function test_author_cannot_change_resolution_of_archived_thread(): void
    {
        $author = User::factory()->student()->create();
        $certification = Certification::factory()->archived()->create();

        $thread = QaThread::factory()
            ->unresolved()
            ->for($certification, 'certification')
            ->for($author, 'user')
            ->create();

        $this->actingAs($author)
            ->post(route('qa-board.resolve', $thread))
            ->assertForbidden();

        $this->actingAs($author)
            ->post(route('qa-board.unresolve', $thread))
            ->assertForbidden();

        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
            'status' => QaThreadStatus::Unresolved->value,
            'resolved_at' => null,
        ]);
    }
}
