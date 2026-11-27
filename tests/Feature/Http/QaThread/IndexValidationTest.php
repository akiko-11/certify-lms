<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaThread;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 質問掲示板の一覧検索における
 * バリデーションエラーメッセージを検証する。
 */
class IndexValidationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('invalidFilterCases')]
    public function test_invalid_search_conditions_return_japanese_messages(
        array $params,
        string $field,
        string $expectedMessage
    ): void {
        $student = User::factory()->student()->create();

        $indexUrl = route('qa-board.index');

        $this->actingAs($student)
            ->from($indexUrl)
            ->get(route('qa-board.index', $params))
            ->assertRedirect($indexUrl)
            ->assertSessionHasErrors([
                $field => $expectedMessage,
            ]);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function invalidFilterCases(): array
    {
        return [
            '資格IDの形式が不正' => [
                ['certification_id' => 'invalid-id'],
                'certification_id',
                '資格の指定が正しくありません。',
            ],

            '解決状態が不正' => [
                ['status' => 'closed'],
                'status',
                '解決状態の指定が正しくありません。',
            ],

            'キーワードが文字列ではない' => [
                ['keyword' => ['invalid']],
                'keyword',
                'キーワードは文字列で入力してください。',
            ],

            'キーワードが100文字を超える' => [
                ['keyword' => str_repeat('a', 101)],
                'keyword',
                'キーワードは100文字以内で入力してください。',
            ],

            'ページ番号が整数ではない' => [
                ['page' => 'abc'],
                'page',
                'ページ番号は整数で指定してください。',
            ],

            'ページ番号が0以下' => [
                ['page' => '0'],
                'page',
                'ページ番号は1以上で指定してください。',
            ],
        ];
    }
}
