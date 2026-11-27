<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 質問掲示板の回答を生成するFactory。
 *
 * @extends Factory<QaReply>
 */
class QaReplyFactory extends Factory
{
    protected $model = QaReply::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'qa_thread_id' => QaThread::factory(),
            'user_id' => User::factory()->student(),
            'body' => fake()->realText(300),
        ];
    }

    public function fromStudent(): static
    {
        return $this->state(fn () => [
            'user_id' => User::factory()->student(),
        ]);
    }

    public function fromCoach(): static
    {
        return $this->state(fn () => [
            'user_id' => User::factory()->coach(),
        ]);
    }
}
