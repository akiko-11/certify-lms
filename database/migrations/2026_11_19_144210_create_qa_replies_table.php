<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 質問掲示板のスレッドに紐づく回答を管理するテーブル。
 * 受講生・コーチが投稿した回答を保持する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qa_replies', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('qa_thread_id')
                ->constrained('qa_threads')
                ->cascadeOnDelete();

            $table->foreignUlid('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->text('body');
            $table->timestamps();

            $table->index(['qa_thread_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_replies');
    }
};
