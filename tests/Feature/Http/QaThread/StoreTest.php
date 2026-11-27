<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 質問スレッドの新規投稿画面・投稿処理・
 * バリデーション・認可を検証する。
 */
class StoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_view_create_form_with_only_published_certifications(): void
    {
        $student = User::factory()->student()->create();

        $published = Certification::factory()->published()->create();
        Certification::factory()->draft()->create();
        Certification::factory()->archived()->create();

        $this->actingAs($student)
            ->get(route('qa-board.create'))
            ->assertOk()
            ->assertViewIs('qa-thread.create')
            ->assertViewHas('certifications', function ($certifications) use ($published) {
                return $certifications->count() === 1
                    && $certifications->contains('id', $published->id);
            });
    }

    public function test_coach_and_admin_cannot_create_threads(): void
    {
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();

        $certification = Certification::factory()->published()->create();

        $data = $this->validPayload($certification);

        foreach ([$coach, $admin] as $user) {
            $this->actingAs($user)
                ->get(route('qa-board.create'))
                ->assertForbidden();

            $this->actingAs($user)
                ->post(route('qa-board.store'), $data)
                ->assertForbidden();
        }

        $this->assertDatabaseCount('qa_threads', 0);
    }

    public function test_student_can_store_thread_without_enrollment(): void
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $data = $this->validPayload($certification);

        // 対象資格への受講登録は作成しない
        $this->assertDatabaseMissing('enrollments', [
            'user_id' => $student->id,
            'certification_id' => $certification->id,
        ]);

        $response = $this->actingAs($student)
            ->post(route('qa-board.store'), $data);

        // 投稿リクエストがリダイレクトを返すことを確認
        $response->assertRedirect();

        // スレッドが1件作成されたことを確認
        $this->assertDatabaseCount('qa_threads', 1);

        // 作成後のスレッドを取得
        $thread = QaThread::query()->firstOrFail();

        // 詳細画面への遷移と成功メッセージを確認
        $response
            ->assertRedirect(route('qa-board.show', $thread))
            ->assertSessionHas('success', '質問を投稿しました。');

        // 投稿データの保存を確認
        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
            'certification_id' => $certification->id,
            'user_id' => $student->id,
            'title' => $data['title'],
            'body' => $data['body'],
        ]);

        // 初期状態は未解決
        $this->assertSame(QaThreadStatus::Unresolved, $thread->status);
        $this->assertNull($thread->resolved_at);

        $this->assertDatabaseCount('qa_threads', 1);
    }

    #[DataProvider('invalidFieldCases')]
    public function test_invalid_input_is_rejected_without_saving(
        string $field,
        ?string $invalidValue,
        string $expectedMessage
    ): void {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $data = $this->validPayload($certification);

        // 指定した項目だけ不正な値に変更
        $data[$field] = $invalidValue;

        $this->actingAs($student)
            ->from(route('qa-board.create'))
            ->post(route('qa-board.store'), $data)
            ->assertRedirect(route('qa-board.create'))
            ->assertSessionHasErrors([
                $field => $expectedMessage,
            ]);

        // バリデーションエラー時は保存しない
        $this->assertDatabaseCount('qa_threads', 0);
    }

    #[DataProvider('unpublishedCertificationStates')]
    public function test_student_cannot_store_thread_for_unpublished_certification(
        string $state
    ): void {
        $student = User::factory()->student()->create();

        $certification = Certification::factory()
            ->{$state}()
            ->create();

        $this->actingAs($student)
            ->from(route('qa-board.create'))
            ->post(
                route('qa-board.store'),
                $this->validPayload($certification)
            )
            ->assertRedirect(route('qa-board.create'))
            ->assertSessionHasErrors([
                'certification_id' => '公開中の資格を選択してください。',
            ]);

        $this->assertDatabaseCount('qa_threads', 0);
    }

    public function test_student_cannot_store_thread_for_nonexistent_certification(): void
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $data = $this->validPayload($certification);

        // ULID形式は正しいが、存在しない資格ID
        $data['certification_id'] = (string) Str::ulid();

        $this->actingAs($student)
            ->from(route('qa-board.create'))
            ->post(route('qa-board.store'), $data)
            ->assertRedirect(route('qa-board.create'))
            ->assertSessionHasErrors([
                'certification_id' => '公開中の資格を選択してください。',
            ]);

        $this->assertDatabaseCount('qa_threads', 0);
    }

    public function test_student_can_store_thread_at_maximum_length(): void
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $data = [
            'certification_id' => $certification->id,
            'title' => str_repeat('a', 200),
            'body' => str_repeat('b', 5000),
        ];

        $this->actingAs($student)
            ->post(route('qa-board.store'), $data)
            ->assertSessionHas('success', '質問を投稿しました。');

        $this->assertDatabaseHas('qa_threads', [
            'certification_id' => $certification->id,
            'user_id' => $student->id,
            'title' => $data['title'],
            'body' => $data['body'],
        ]);
    }

    /**
     * @return array<string, array{0: string, 1: ?string, 2: string}>
     */
    public static function invalidFieldCases(): array
    {
        return [
            '資格が未選択' => [
                'certification_id',
                null,
                '資格を選択してください。',
            ],

            '資格IDの形式が不正' => [
                'certification_id',
                'invalid-id',
                '資格の指定が正しくありません。',
            ],

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

    /**
     * @return array<string, array{0: string}>
     */
    public static function unpublishedCertificationStates(): array
    {
        return [
            '下書き資格' => ['draft'],
            '公開停止中の資格' => ['archived'],
        ];
    }

    /**
     * 正常な投稿データを生成する。
     *
     * @return array<string, string>
     */
    private function validPayload(Certification $certification): array
    {
        return [
            'certification_id' => $certification->id,
            'title' => '資格学習についての質問',
            'body' => '教材を確認しましたが、解説の一部が理解できません。',
        ];
    }
}
