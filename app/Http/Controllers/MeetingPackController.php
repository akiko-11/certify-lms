<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MeetingPackStatus;
use App\Exceptions\MeetingPack\MeetingPackInvalidTransitionException;
use App\Exceptions\MeetingPack\MeetingPackNotDeletableException;
use App\Http\Requests\MeetingPack\IndexRequest;
use App\Http\Requests\MeetingPack\SaveRequest;
use App\Http\Requests\MeetingPack\StoreRequest;
use App\Http\Requests\MeetingPack\UpdateRequest;
use App\Models\MeetingPack;
use App\UseCases\MeetingPack\DestroyAction;
use App\UseCases\MeetingPack\IndexAction;
use App\UseCases\MeetingPack\ShowAction;
use App\UseCases\MeetingPack\StoreAction;
use App\UseCases\MeetingPack\TransitionAction;
use App\UseCases\MeetingPack\UpdateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * 管理者向け面談パック管理 Controller。
 */
class MeetingPackController extends Controller
{
    public function index(
        IndexRequest $request,
        IndexAction $action,
    ): View {
        $validated = $request->validated();

        $keyword = $validated['keyword'] ?? null;
        $status = $validated['status'] ?? null;
        $filterErrors = $request->session()->get('errors')?->getBag(IndexRequest::ERROR_BAG);
        $oldKeyword = $filterErrors?->has('keyword') ? $request->session()->get(IndexRequest::OLD_KEYWORD) : null;

        return view('meeting-pack.management.index', [
            'plans' => $action(
                $keyword,
                $status !== null
                    ? MeetingPackStatus::from($status)
                    : null,
                page: (int) ($validated['page'] ?? 1),
            ),
            'keyword' => $keyword ?? (is_string($oldKeyword) ? $oldKeyword : null),
            'status' => $status,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', MeetingPack::class);

        return view('meeting-pack.management.create', [
            'formState' => session(SaveRequest::formStateKey(), []),
        ]);
    }

    public function store(
        StoreRequest $request,
        StoreAction $action,
    ): RedirectResponse {
        $pack = $action(
            $request->user(),
            $request->validated(),
        );

        return redirect()
            ->route('admin.meeting-packs.show', $pack)
            ->with('success', '面談パックを作成しました。');
    }

    public function show(
        MeetingPack $plan,
        ShowAction $action,
    ): View {
        $this->authorize('view', $plan);

        return view('meeting-pack.management.show', [
            'plan' => $action($plan),
        ]);
    }

    public function edit(MeetingPack $plan): View
    {
        $this->authorize('update', $plan);

        return view('meeting-pack.management.edit', [
            'plan' => $plan,
            'formState' => session(SaveRequest::formStateKey($plan), []),
        ]);
    }

    public function update(
        UpdateRequest $request,
        MeetingPack $plan,
        UpdateAction $action,
    ): RedirectResponse {
        $pack = $action(
            $request->user(),
            $plan,
            $request->validated(),
        );

        return redirect()
            ->route('admin.meeting-packs.show', $pack)
            ->with('success', '面談パックを更新しました。');
    }

    public function destroy(
        MeetingPack $plan,
        DestroyAction $action,
    ): RedirectResponse|Response {
        $this->authorize('delete', $plan);

        try {
            $action($plan);
        } catch (MeetingPackNotDeletableException $e) {
            if (request()->expectsJson()) {
                throw $e;
            }

            session()->flash('error', $e->getMessage());

            return response($e->getMessage(), 409);
        }

        return redirect()
            ->route('admin.meeting-packs.index')
            ->with('success', '面談パックを削除しました。');
    }

    public function publish(
        MeetingPack $plan,
        TransitionAction $action,
    ): RedirectResponse|Response {
        $this->authorize('publish', $plan);

        return $this->transition(
            $plan,
            $action,
            MeetingPackStatus::Published,
            '面談パックを公開しました。',
        );
    }

    public function archive(
        MeetingPack $plan,
        TransitionAction $action,
    ): RedirectResponse|Response {
        $this->authorize('archive', $plan);

        return $this->transition(
            $plan,
            $action,
            MeetingPackStatus::Archived,
            '面談パックをアーカイブしました。',
        );
    }

    public function unarchive(
        MeetingPack $plan,
        TransitionAction $action,
    ): RedirectResponse|Response {
        $this->authorize('unarchive', $plan);

        return $this->transition(
            $plan,
            $action,
            MeetingPackStatus::Draft,
            '面談パックを下書きに戻しました。',
        );
    }

    /**
     * 面談パックの状態遷移とレスポンスを処理する。
     */
    private function transition(
        MeetingPack $plan,
        TransitionAction $action,
        MeetingPackStatus $targetStatus,
        string $successMessage,
    ): RedirectResponse|Response {
        try {
            $pack = $action(
                request()->user(),
                $plan,
                $targetStatus,
            );
        } catch (MeetingPackInvalidTransitionException $e) {
            if (request()->expectsJson()) {
                throw $e;
            }

            session()->flash('error', $e->getMessage());

            return response($e->getMessage(), 409);
        }

        return redirect()
            ->route('admin.meeting-packs.show', $pack)
            ->with('success', $successMessage);
    }
}
