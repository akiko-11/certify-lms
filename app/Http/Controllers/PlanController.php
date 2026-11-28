<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PlanStatus;
use App\Exceptions\Plan\PlanOperationConflictException;
use App\Http\Requests\Plan\IndexRequest;
use App\Http\Requests\Plan\SaveRequest;
use App\Http\Requests\Plan\StoreRequest;
use App\Http\Requests\Plan\UpdateRequest;
use App\Models\Plan;
use App\UseCases\Plan\DestroyAction;
use App\UseCases\Plan\IndexAction;
use App\UseCases\Plan\ShowAction;
use App\UseCases\Plan\StoreAction;
use App\UseCases\Plan\TransitionAction;
use App\UseCases\Plan\UpdateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlanController extends Controller
{
    public function index(IndexRequest $request, IndexAction $action): View|RedirectResponse
    {
        $keyword = $request->validated('keyword');
        $status = $request->validated('status');
        $plans = $action($keyword, $status !== null ? PlanStatus::from($status) : null, (string) $request->validated('page', 1));

        if (is_int($plans)) {
            return redirect()->route('admin.plans.index', ['keyword' => $keyword, 'status' => $status, 'page' => $plans]);
        }

        return view('plan.management.index', ['plans' => $plans, 'keyword' => $keyword, 'status' => $status]);
    }

    public function create(): View
    {
        $this->authorize('create', Plan::class);

        return view('plan.management.create', ['formState' => session(SaveRequest::formStateKey(), [])]);
    }

    public function store(StoreRequest $request, StoreAction $action): RedirectResponse
    {
        $plan = $action($request->user(), $request->validated());

        return redirect()->route('admin.plans.show', $plan)->with('success', 'プランを作成しました。');
    }

    public function show(Plan $plan, ShowAction $action): View
    {
        $this->authorize('view', $plan);

        return view('plan.management.show', $action($plan));
    }

    public function edit(Plan $plan): View
    {
        $this->authorize('update', $plan);

        return view('plan.management.edit', ['plan' => $plan, 'formState' => session(SaveRequest::formStateKey($plan), [])]);
    }

    public function update(UpdateRequest $request, Plan $plan, UpdateAction $action): RedirectResponse
    {
        $updated = $action($request->user(), $plan, $request->validated());

        return redirect()->route('admin.plans.show', $updated)->with('success', 'プランを更新しました。');
    }

    public function publish(Request $request, Plan $plan, TransitionAction $action): RedirectResponse
    {
        return $this->transition($request, $plan, $action, PlanStatus::Published, 'プランを公開しました。');
    }

    public function archive(Request $request, Plan $plan, TransitionAction $action): RedirectResponse
    {
        return $this->transition($request, $plan, $action, PlanStatus::Archived, 'プランをアーカイブしました。');
    }

    public function unarchive(Request $request, Plan $plan, TransitionAction $action): RedirectResponse
    {
        return $this->transition($request, $plan, $action, PlanStatus::Draft, 'プランを下書きに戻しました。');
    }

    public function destroy(Request $request, Plan $plan, DestroyAction $action): RedirectResponse
    {
        $this->authorize('manage', Plan::class);

        try {
            $action($plan);
        } catch (PlanOperationConflictException $exception) {
            return $this->conflictResponse($request, $plan, $exception);
        }

        return redirect()->route('admin.plans.index')->with('success', 'プランを削除しました。');
    }

    private function transition(Request $request, Plan $plan, TransitionAction $action, PlanStatus $target, string $message): RedirectResponse
    {
        $this->authorize('manage', Plan::class);

        try {
            $action($request->user(), $plan, $target);
        } catch (PlanOperationConflictException $exception) {
            return $this->conflictResponse($request, $plan, $exception);
        }

        return redirect()->route('admin.plans.show', $plan)->with('success', $message);
    }

    private function conflictResponse(Request $request, Plan $plan, PlanOperationConflictException $exception): RedirectResponse
    {
        if ($request->expectsJson()) {
            throw $exception;
        }

        return redirect()->route('admin.plans.show', $plan)->with('error', $exception->getMessage());
    }
}
