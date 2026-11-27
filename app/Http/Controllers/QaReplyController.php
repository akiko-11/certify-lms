<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\QaReply\StoreRequest;
use App\Http\Requests\QaReply\UpdateRequest;
use App\Models\QaReply;
use App\Models\QaThread;
use App\UseCases\QaReply\DestroyAction;
use App\UseCases\QaReply\StoreAction;
use App\UseCases\QaReply\UpdateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class QaReplyController extends Controller
{
    /**
     * 質問スレッドに回答を投稿する。
     */
    public function store(
        QaThread $thread,
        StoreRequest $request,
        StoreAction $action
    ): RedirectResponse {
        $action(
            $thread,
            $request->user(),
            $request->validated()
        );

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '回答を投稿しました。');
    }

    /**
     * 回答の編集画面を表示する。
     */
    public function edit(QaThread $thread, QaReply $reply): View
    {
        $this->authorize('update', $reply);

        return view('qa-thread.reply-edit', [
            'thread' => $thread,
            'reply' => $reply,
        ]);
    }

    /**
     * 回答本文を更新する。
     */
    public function update(
        QaThread $thread,
        QaReply $reply,
        UpdateRequest $request,
        UpdateAction $action
    ): RedirectResponse {
        $action($reply, $request->validated());

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '回答を更新しました。');
    }

    /**
     * 回答を削除する。
     */
    public function destroy(
        QaThread $thread,
        QaReply $reply,
        DestroyAction $action
    ): RedirectResponse {
        // 回答の削除権限を確認
        $this->authorize('delete', $reply);

        // 回答を削除
        $action($reply);

        // 管理者ルートかどうかで遷移先を分ける
        $redirectRoute = request()->routeIs('admin.qa-board.replies.destroy')
            ? 'admin.qa-board.show'
            : 'qa-board.show';

        return redirect()
            ->route($redirectRoute, $thread)
            ->with('success', '回答を削除しました。');
    }
}
