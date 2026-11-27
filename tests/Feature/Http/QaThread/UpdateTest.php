<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 質問スレッドの編集画面・更新処理・認可・
 * バリデーションを検証する。
 */
class UpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_can_view_edit_form(): void
    {
        $author = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()
            ->for($certification, 'certification')
            ->for($author, 'user')
            ->create([
                'title' => '変更前のタイトル',
                'body' => '変更前の本文',
            ]);

        $this->actingAs($author)
            ->get(route('qa-board.edit', $thread))
            ->assertOk()
            ->assertViewIs('qa-thread.edit')
            ->assertViewHas('thread', function ($viewThread) use ($thread) {
                return $viewThread->id === $thread->id;
            })
            ->assertSeeText('変更前のタイトル')
            ->assertSeeText('変更前の本文');
    }

    public function test_author_can_update_title_and_body(): void
    {
        $author = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()
            ->for($certification, 'certification')
            ->for($author, 'user')
            ->resolved()
            ->create([
                'title' => '変更前のタイトル',
                'body' => '変更前の本文',
            ]);

        $resolvedAt = $thread->resolved_at?->toDateTimeString();

        $data = [
            'title' => '変更後のタイトル',
            'body' => '変更後の本文',
        ];

        $this->actingAs($author)
            ->patch(route('qa-board.update', $thread), $data)
            ->assertRedirect(route('qa-board.show', $thread))
            ->assertSessionHas('success', '質問を更新しました。');

        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
            'certification_id' => $certification->id,
            'user_id' => $author->id,
            'title' => '変更後のタイトル',
            'body' => '変更後の本文',
        ]);

        $updatedThread = $thread->fresh();

        // 編集によって解決状態は変更されない
        $this->assertSame(
            QaThreadStatus::Resolved,
            $updatedThread->status
        );

        $this->assertSame(
            $resolvedAt,
            $updatedThread->resolved_at?->toDateTimeString()
        );
    }

    public function test_other_users_cannot_edit_thread(): void
    {
        $author = User::factory()->student()->create();
        $otherStudent = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();

        $thread = QaThread::factory()
            ->for($author, 'user')
            ->create([
                'title' => '変更前のタイトル',
                'body' => '変更前の本文',
            ]);

        foreach ([$otherStudent, $coach, $admin] as $user) {
            $this->actingAs($user)
                ->get(route('qa-board.edit', $thread))
                ->assertForbidden();

            $this->actingAs($user)
                ->patch(route('qa-board.update', $thread), [
                    'title' => '不正な変更',
                    'body' => '不正な本文',
                ])
                ->assertForbidden();
        }

        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
            'title' => '変更前のタイトル',
            'body' => '変更前の本文',
        ]);
    }

    public function test_author_cannot_edit_unpublished_thread(): void
    {
        $author = User::factory()->student()->create();
        $certification = Certification::factory()->archived()->create();

        $thread = QaThread::factory()
            ->for($certification, 'certification')
            ->for($author, 'user')
            ->create([
                'title' => '変更前のタイトル',
                'body' => '変更前の本文',
            ]);

        $this->actingAs($author)
            ->get(route('qa-board.edit', $thread))
            ->assertForbidden();

        $this->actingAs($author)
            ->patch(route('qa-board.update', $thread), [
                'title' => '変更後のタイトル',
                'body' => '変更後の本文',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
            'title' => '変更前のタイトル',
            'body' => '変更前の本文',
        ]);
    }

    #[DataProvider('invalidUpdateCases')]
    public function test_invalid_update_is_rejected_without_saving(
        string $field,
        ?string $invalidValue,
        string $expectedMessage
    ): void {
        $author = User::factory()->student()->create();

        $thread = QaThread::factory()
            ->for($author, 'user')
            ->create([
                'title' => '変更前のタイトル',
                'body' => '変更前の本文',
            ]);

        $data = [
            'title' => '変更後のタイトル',
            'body' => '変更後の本文',
        ];

        $data[$field] = $invalidValue;

        $this->actingAs($author)
            ->from(route('qa-board.edit', $thread))
            ->patch(route('qa-board.update', $thread), $data)
            ->assertRedirect(route('qa-board.edit', $thread))
            ->assertSessionHasErrors([
                $field => $expectedMessage,
            ]);

        // バリデーションエラー時は更新されない
        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
            'title' => '変更前のタイトル',
            'body' => '変更前の本文',
        ]);
    }

    public function test_certification_change_is_rejected_without_updating_any_field(): void
    {
        $author = User::factory()->student()->create();

        $originalCertification = Certification::factory()->published()->create();
        $otherCertification = Certification::factory()->published()->create();

        $thread = QaThread::factory()
            ->for($originalCertification, 'certification')
            ->for($author, 'user')
            ->create([
                'title' => '変更前のタイトル',
                'body' => '変更前の本文',
            ]);

        $this->actingAs($author)
            ->from(route('qa-board.edit', $thread))
            ->patch(route('qa-board.update', $thread), [
                'certification_id' => $otherCertification->id,
                'title' => '変更後のタイトル',
                'body' => '変更後の本文',
            ])
            ->assertRedirect(route('qa-board.edit', $thread))
            ->assertSessionHasErrors([
                'certification_id' => '資格は変更できません。',
            ]);

        // 資格・タイトル・本文のいずれも更新されない
        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
            'certification_id' => $originalCertification->id,
            'title' => '変更前のタイトル',
            'body' => '変更前の本文',
        ]);
    }

    public function test_author_can_update_at_maximum_length(): void
    {
        $author = User::factory()->student()->create();

        $thread = QaThread::factory()
            ->for($author, 'user')
            ->create();

        $data = [
            'title' => str_repeat('a', 200),
            'body' => str_repeat('b', 5000),
        ];

        $this->actingAs($author)
            ->patch(route('qa-board.update', $thread), $data)
            ->assertRedirect(route('qa-board.show', $thread));

        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
            'title' => $data['title'],
            'body' => $data['body'],
        ]);
    }

    /**
     * @return array<string, array{0: string, 1: ?string, 2: string}>
     */
    public static function invalidUpdateCases(): array
    {
        return [
            'タイトルが未入力' => [
                'title',
                '',
                'タイトルを入力してください。',
            ],

            'タイトルが200文字を超える' => [
                'title',
                str_repeat('a', 201),
                'タイトルは200文字以内で入力してください。',
            ],

            '本文が未入力' => [
                'body',
                '',
                '本文を入力してください。',
            ],

            '本文が5000文字を超える' => [
                'body',
                str_repeat('b', 5001),
                '本文は5000文字以内で入力してください。',
            ],
        ];
    }

    public function test_certification_field_is_rejected_even_when_empty(): void
    {
        $author = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()
            ->for($certification, 'certification')
            ->for($author, 'user')
            ->create([
                'title' => '変更前のタイトル',
                'body' => '変更前の本文',
            ]);

        $this->actingAs($author)
            ->from(route('qa-board.edit', $thread))
            ->patch(route('qa-board.update', $thread), [
                'certification_id' => null,
                'title' => '変更後のタイトル',
                'body' => '変更後の本文',
            ])
            ->assertRedirect(route('qa-board.edit', $thread))
            ->assertSessionHasErrors([
                'certification_id' => '資格は変更できません。',
            ]);

        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
            'certification_id' => $certification->id,
            'title' => '変更前のタイトル',
            'body' => '変更前の本文',
        ]);
    }
}
