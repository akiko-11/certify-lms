<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaThread;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 質問掲示板の一覧表示・アクセス範囲・絞り込みを検証する。
 */
class IndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_view_only_published_threads(): void
    {
        $student = User::factory()->student()->create();

        $publishedCertification = Certification::factory()->published()->create();
        $archivedCertification = Certification::factory()->archived()->create();

        $publishedThread = QaThread::factory()
            ->for($publishedCertification, 'certification')
            ->create();

        QaThread::factory()
            ->for($archivedCertification, 'certification')
            ->create();

        $this->actingAs($student)
            ->get(route('qa-board.index'))
            ->assertOk()
            ->assertViewIs('qa-thread.index')
            ->assertViewHas('threads', function ($threads) use ($publishedThread) {
                return $threads->total() === 1
                    && $threads->getCollection()->contains('id', $publishedThread->id);
            });
    }

    public function test_coach_can_view_only_assigned_published_threads(): void
    {
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();

        $assignedCertification = Certification::factory()->published()->create();
        $otherCertification = Certification::factory()->published()->create();
        $archivedCertification = Certification::factory()->archived()->create();

        foreach ([$assignedCertification, $archivedCertification] as $certification) {
            CertificationCoachAssignment::create([
                'id' => (string) Str::ulid(),
                'certification_id' => $certification->id,
                'user_id' => $coach->id,
                'assigned_by_user_id' => $admin->id,
                'assigned_at' => now(),
            ]);
        }

        $assignedThread = QaThread::factory()
            ->for($assignedCertification, 'certification')
            ->create();

        QaThread::factory()
            ->for($otherCertification, 'certification')
            ->create();

        QaThread::factory()
            ->for($archivedCertification, 'certification')
            ->create();

        $this->actingAs($coach)
            ->get(route('qa-board.index'))
            ->assertOk()
            ->assertViewHas('threads', function ($threads) use ($assignedThread) {
                return $threads->total() === 1
                    && $threads->getCollection()->contains('id', $assignedThread->id);
            });
    }

    public function test_admin_can_view_threads_from_all_certification_statuses(): void
    {
        $admin = User::factory()->admin()->create();

        $publishedCertification = Certification::factory()->published()->create();
        $draftCertification = Certification::factory()->draft()->create();
        $archivedCertification = Certification::factory()->archived()->create();

        foreach ([
            $publishedCertification,
            $draftCertification,
            $archivedCertification,
        ] as $certification) {
            QaThread::factory()
                ->for($certification, 'certification')
                ->create();
        }

        $this->actingAs($admin)
            ->get(route('admin.qa-board.index'))
            ->assertOk()
            ->assertViewIs('qa-thread.index')
            ->assertViewHas('threads', function ($threads) {
                return $threads->total() === 3;
            });
    }

    public function test_student_can_filter_threads_by_resolution_status(): void
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $resolvedThread = QaThread::factory()
            ->for($certification, 'certification')
            ->resolved()
            ->create();

        QaThread::factory()
            ->for($certification, 'certification')
            ->unresolved()
            ->create();

        $this->actingAs($student)
            ->get(route('qa-board.index', ['status' => 'resolved']))
            ->assertOk()
            ->assertViewHas('threads', function ($threads) use ($resolvedThread) {
                return $threads->total() === 1
                    && $threads->getCollection()->contains('id', $resolvedThread->id);
            });
    }

    public function test_student_can_filter_threads_by_certification(): void
    {
        $student = User::factory()->student()->create();

        $certificationA = Certification::factory()->published()->create();
        $certificationB = Certification::factory()->published()->create();

        $threadA = QaThread::factory()
            ->for($certificationA, 'certification')
            ->create();

        QaThread::factory()
            ->for($certificationB, 'certification')
            ->create();

        $this->actingAs($student)
            ->get(route('qa-board.index', [
                'certification_id' => $certificationA->id,
            ]))
            ->assertOk()
            ->assertViewHas('threads', function ($threads) use ($threadA) {
                return $threads->total() === 1
                    && $threads->getCollection()->contains('id', $threadA->id);
            });
    }

    public function test_student_can_search_threads_by_body_keyword(): void
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $matchingThread = QaThread::factory()
            ->for($certification, 'certification')
            ->create([
                'title' => 'データベースの質問',
                'body' => 'トランザクションの仕組みについて教えてください。',
            ]);

        QaThread::factory()
            ->for($certification, 'certification')
            ->create([
                'title' => '学習方法について',
                'body' => '資格試験に向けた復習方法を知りたいです。',
            ]);

        $this->actingAs($student)
            ->get(route('qa-board.index', [
                'keyword' => 'トランザクション',
            ]))
            ->assertOk()
            ->assertViewHas('threads', function ($threads) use ($matchingThread) {
                return $threads->total() === 1
                    && $threads->getCollection()->contains('id', $matchingThread->id);
            });
    }

    public function test_student_can_search_threads_by_title_keyword(): void
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        // タイトルのみ一致
        $matchingThread = QaThread::factory()
            ->for($certification, 'certification')
            ->create([
                'title' => 'Laravelの基本について',
                'body' => 'フレームワークの使い方を教えてください。',
            ]);

        QaThread::factory()
            ->for($certification, 'certification')
            ->create([
                'title' => 'SQLの基本について',
                'body' => 'データベースの操作方法を教えてください。',
            ]);

        $this->actingAs($student)
            ->get(route('qa-board.index', [
                'keyword' => 'Laravel',
            ]))
            ->assertOk()
            ->assertViewHas('threads', function ($threads) use ($matchingThread) {
                return $threads->total() === 1
                    && $threads->getCollection()->contains('id', $matchingThread->id);
            });
    }

    public function test_student_can_search_threads_by_reply_keyword_without_duplicates(): void
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $matchingThread = QaThread::factory()
            ->for($certification, 'certification')
            ->create([
                'title' => 'プログラムの質問',
                'body' => '処理方法について教えてください。',
            ]);

        // 同じ質問に、キーワードと一致する回答を2件追加
        QaReply::factory()->create([
            'qa_thread_id' => $matchingThread->id,
            'user_id' => $student->id,
            'body' => 'Laravelを使用すると実装できます。',
        ]);

        QaReply::factory()->create([
            'qa_thread_id' => $matchingThread->id,
            'user_id' => $student->id,
            'body' => 'Laravelの機能を利用する方法もあります。',
        ]);

        // 一致しない質問
        $otherThread = QaThread::factory()
            ->for($certification, 'certification')
            ->create([
                'title' => 'データベースの質問',
                'body' => 'SQLについて教えてください。',
            ]);

        $this->actingAs($student)
            ->get(route('qa-board.index', [
                'keyword' => 'Laravel',
            ]))
            ->assertOk()
            ->assertViewHas('threads', function ($threads) use (
                $matchingThread,
                $otherThread
            ) {
                $ids = $threads->getCollection()->pluck('id')->all();

                return $threads->total() === 1
                    && count($ids) === 1
                    && $ids[0] === $matchingThread->id
                    && ! in_array($otherThread->id, $ids, true);
            });
    }

    public function test_keyword_search_respects_certification_and_status_filters(): void
    {
        $student = User::factory()->student()->create();

        $certificationA = Certification::factory()->published()->create();
        $certificationB = Certification::factory()->published()->create();

        // 検索条件にすべて一致
        $matchingThread = QaThread::factory()
            ->for($certificationA, 'certification')
            ->unresolved()
            ->create([
                'title' => 'Laravelについて',
                'body' => '基本操作を教えてください。',
            ]);

        // 資格が異なる
        QaThread::factory()
            ->for($certificationB, 'certification')
            ->unresolved()
            ->create([
                'title' => 'Laravelについて',
                'body' => '基本操作を教えてください。',
            ]);

        // 解決状態が異なる
        QaThread::factory()
            ->for($certificationA, 'certification')
            ->resolved()
            ->create([
                'title' => 'Laravelについて',
                'body' => '基本操作を教えてください。',
            ]);

        $this->actingAs($student)
            ->get(route('qa-board.index', [
                'certification_id' => $certificationA->id,
                'status' => 'unresolved',
                'keyword' => 'Laravel',
            ]))
            ->assertOk()
            ->assertViewHas('threads', function ($threads) use ($matchingThread) {
                return $threads->total() === 1
                    && $threads->getCollection()->contains('id', $matchingThread->id);
            });
    }

    public function test_thread_index_uses_twenty_per_page_pagination(): void
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        // 21件のスレッドを作成
        QaThread::factory()
            ->count(21)
            ->for($certification, 'certification')
            ->create();

        // 1ページ目：20件表示
        $this->actingAs($student)
            ->get(route('qa-board.index'))
            ->assertOk()
            ->assertViewHas('threads', function ($threads) {
                return $threads instanceof LengthAwarePaginator
                    && $threads->total() === 21
                    && $threads->perPage() === 20
                    && $threads->currentPage() === 1
                    && $threads->count() === 20;
            });

        // 2ページ目：残り1件表示
        $this->actingAs($student)
            ->get(route('qa-board.index', ['page' => 2]))
            ->assertOk()
            ->assertViewHas('threads', function ($threads) {
                return $threads instanceof LengthAwarePaginator
                    && $threads->total() === 21
                    && $threads->perPage() === 20
                    && $threads->currentPage() === 2
                    && $threads->count() === 1;
            });
    }

    public function test_pagination_preserves_search_filters(): void
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        QaThread::factory()
            ->count(21)
            ->for($certification, 'certification')
            ->unresolved()
            ->create([
                'title' => 'Laravelの質問',
                'body' => 'プログラミングについて',
            ]);

        $filters = [
            'certification_id' => $certification->id,
            'status' => 'unresolved',
            'keyword' => 'Laravel',
        ];

        $this->actingAs($student)
            ->get(route('qa-board.index', $filters))
            ->assertOk()
            ->assertViewHas('threads', function ($threads) use ($filters) {
                parse_str(
                    (string) parse_url($threads->url(2), PHP_URL_QUERY),
                    $query
                );

                return $threads->total() === 21
                    && $threads->perPage() === 20
                    && $threads->count() === 20
                    && ($query['certification_id'] ?? null) === (string) $filters['certification_id']
                    && ($query['status'] ?? null) === $filters['status']
                    && ($query['keyword'] ?? null) === $filters['keyword']
                    && ($query['page'] ?? null) === '2';
            });

        $this->actingAs($student)
            ->get(route('qa-board.index', [...$filters, 'page' => 2]))
            ->assertOk()
            ->assertViewHas('threads', function ($threads) {
                return $threads->total() === 21
                    && $threads->perPage() === 20
                    && $threads->currentPage() === 2
                    && $threads->count() === 1;
            });
    }
}
