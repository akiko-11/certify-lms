<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CertificationStatus;
use App\Enums\UserRole;
use App\Http\Requests\QaThread\IndexRequest;
use App\Http\Requests\QaThread\StoreRequest;
use App\Http\Requests\QaThread\UpdateRequest;
use App\Models\Certification;
use App\Models\QaThread;
use App\UseCases\QaThread\DestroyAction;
use App\UseCases\QaThread\IndexAction;
use App\UseCases\QaThread\ResolveAction;
use App\UseCases\QaThread\ShowAction;
use App\UseCases\QaThread\StoreAction;
use App\UseCases\QaThread\UnresolveAction;
use App\UseCases\QaThread\UpdateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * 質問掲示板のスレッドを管理するController。
 */
class QaThreadController extends Controller
{
    /**
     * 質問スレッド一覧を表示する。
     */
    public function index(IndexRequest $request, IndexAction $action): View
    {
        $filters = $request->validated();

        $result = $action($request->user(), $filters);

        return view('qa-thread.index', [
            'threads' => $result['threads'],
            'certifications' => $result['certifications'],
            'filters' => [
                'certification_id' => $filters['certification_id'] ?? '',
                'status' => $filters['status'] ?? '',
                'keyword' => $filters['keyword'] ?? '',
            ],
            'publishedStatus' => CertificationStatus::Published,
        ]);
    }

    /**
     * 質問スレッドの新規投稿画面を表示する。
     */
    public function create(): View
    {
        $this->authorize('create', QaThread::class);

        return view('qa-thread.create', [
            'certifications' => Certification::query()
                ->published()
                ->orderBy('name')
                ->get(),
        ]);
    }

    /**
     * 質問スレッドを新規投稿する。
     */
    public function store(
        StoreRequest $request,
        StoreAction $action
    ): RedirectResponse {
        $thread = $action(
            $request->user(),
            $request->validated()
        );

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '質問を投稿しました。');
    }

    /**
     * 質問スレッドの詳細画面を表示する。
     */
    public function show(QaThread $thread, ShowAction $action): View
    {
        // 公開停止中の資格は管理者以外に存在を知らせない
        if (
            auth()->user()->role !== UserRole::Admin
            && $thread->certification->status !== CertificationStatus::Published
        ) {
            abort(404);
        }

        $this->authorize('view', $thread);

        return view('qa-thread.show', [
            'thread' => $action($thread),
        ]);
    }

    /**
     * 質問スレッドの編集画面を表示する。
     */
    public function edit(QaThread $thread): View
    {
        $this->authorize('update', $thread);

        return view('qa-thread.edit', [
            'thread' => $thread->load('certification'),
        ]);
    }

    /**
     * 質問スレッドのタイトルと本文を更新する。
     */
    public function update(
        QaThread $thread,
        UpdateRequest $request,
        UpdateAction $action
    ): RedirectResponse {
        $action($thread, $request->validated());

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '質問を更新しました。');
    }

    /**
     * 質問スレッドを削除する。
     */
    public function destroy(
        QaThread $thread,
        DestroyAction $action
    ): RedirectResponse|Response {
        // 削除権限と削除不可の理由を確認
        $authorization = Gate::inspect('delete', $thread);

        // 回答がある場合は409とエラーメッセージを返す
        if ($authorization->denied() && $authorization->status() === 409) {
            $message = '回答が付いているスレッドは削除できません。';

            session()->flash('error', $message);

            return response($message, 409);
        }

        // その他の権限違反は403
        $authorization->authorize();

        // スレッドを削除
        $action($thread);

        // 管理者と受講生で削除後の遷移先を分ける
        $redirectRoute = request()->routeIs('admin.qa-board.destroy')
            ? 'admin.qa-board.index'
            : 'qa-board.index';

        return redirect()
            ->route($redirectRoute)
            ->with('success', '質問を削除しました。');
    }

    /**
     * 質問スレッドを解決済みに変更する。
     */
    public function resolve(
        QaThread $thread,
        ResolveAction $action
    ): RedirectResponse {
        $this->authorize('resolve', $thread);

        $action($thread);

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '質問を解決済みにしました。');
    }

    /**
     * 質問スレッドを未解決に戻す。
     */
    public function unresolve(
        QaThread $thread,
        UnresolveAction $action
    ): RedirectResponse {
        $this->authorize('unresolve', $thread);

        $action($thread);

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '質問を未解決に戻しました。');
    }
}
