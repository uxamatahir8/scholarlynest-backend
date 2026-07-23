<?php

namespace App\Http\Controllers;

use App\Models\UserNotification;
use App\Services\Notifications\NotificationPresenter;
use App\Services\Notifications\NotificationSubjectAccessResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    public function __construct(
        private NotificationPresenter $presenter,
        private NotificationSubjectAccessResolver $subjectAccess,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tab' => ['nullable', Rule::in(['all', 'unread', 'action_required', 'archived', 'dismissed'])],
            'category' => ['nullable', Rule::in(config('notification_system.categories'))],
            'priority' => ['nullable', Rule::in(config('notification_system.priorities'))],
            'article_tracking_code' => 'nullable|string|max:40',
            'q' => 'nullable|string|min:2|max:100',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'limit' => 'nullable|integer|min:1|max:100',
            'sort' => ['nullable', Rule::in(['newest', 'oldest'])],
        ]);

        $query = $this->scopedQuery($request, $validated)->with(['article:id,user_id,tracking_code,title,magazine_id,status', 'article.magazine:id,title,slug,publication_type']);
        $sort = $validated['sort'] ?? 'newest';
        $query->orderBy('created_at', $sort === 'oldest' ? 'asc' : 'desc')->orderBy('id', $sort === 'oldest' ? 'asc' : 'desc');
        $page = $query->cursorPaginate($validated['limit'] ?? 25);
        $counts = $this->countValues($request);

        $items = collect($page->items());
        $access = $this->subjectAccess->resolve($items, $request->user());

        return response()->json([
            'data' => $items->map(function (UserNotification $item) use ($request, $access) {
                $key = $this->subjectAccess->key($item);

                return $this->presenter->present($item, $request->user(), $key ? (bool) $access->get($key, false) : true);
            })->values(),
            'meta' => [
                'next_cursor' => $page->nextCursor()?->encode(),
                'previous_cursor' => $page->previousCursor()?->encode(),
                'has_more' => $page->hasMorePages(),
                ...$counts,
            ],
        ]);
    }

    public function counts(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->countValues($request)]);
    }

    public function show(Request $request, UserNotification $notification): JsonResponse
    {
        $this->authorizeFor($request, $notification, 'view');
        $notification->load(['article.magazine']);

        return response()->json(['data' => $this->presenter->present($notification, $request->user())]);
    }

    public function read(Request $request, UserNotification $notification): JsonResponse
    {
        $this->authorizeFor($request, $notification, 'update');
        $validated = $request->validate(['read' => 'required|boolean']);
        $notification->update(['read_at' => $validated['read'] ? ($notification->read_at ?? now()) : null]);

        return response()->json(['data' => $this->presenter->present($notification->fresh(['article.magazine']), $request->user()), 'meta' => $this->countValues($request)]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'before' => 'required|date',
            'scope.tab' => ['nullable', Rule::in(['all', 'unread', 'action_required'])],
            'scope.category' => ['nullable', Rule::in(config('notification_system.categories'))],
        ]);
        $before = Carbon::parse($validated['before']);
        $query = UserNotification::query()->where('recipient_user_id', $request->user()->id)->where('in_app_visible', true)->whereNull('read_at')->where('created_at', '<=', $before);
        if ($category = data_get($validated, 'scope.category')) {
            $query->where('category', $category);
        }
        if (data_get($validated, 'scope.tab') === 'action_required') {
            $query->where('action_status', 'pending');
        }
        $updated = $query->update(['read_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => ['updated' => $updated, 'snapshot' => $before->toISOString()], 'meta' => $this->countValues($request)]);
    }

    public function visibility(Request $request, UserNotification $notification): JsonResponse
    {
        $this->authorizeFor($request, $notification, 'update');
        $validated = $request->validate(['state' => ['required', Rule::in(['active', 'dismissed', 'archived'])]]);
        $values = match ($validated['state']) {
            'active' => ['dismissed_at' => null, 'archived_at' => null],
            'dismissed' => ['dismissed_at' => $notification->dismissed_at ?? now(), 'archived_at' => null],
            'archived' => ['archived_at' => $notification->archived_at ?? now()],
        };
        $notification->update($values);

        return response()->json(['data' => $this->presenter->present($notification->fresh(['article.magazine']), $request->user()), 'meta' => $this->countValues($request)]);
    }

    private function scopedQuery(Request $request, array $filters): Builder
    {
        $query = UserNotification::query()->where('recipient_user_id', $request->user()->id)->where('in_app_visible', true);
        match ($filters['tab'] ?? 'all') {
            'unread' => $query->whereNull('read_at')->whereNull('archived_at'),
            'action_required' => $query->where('action_status', 'pending')->whereNull('archived_at'),
            'archived' => $query->whereNotNull('archived_at'),
            'dismissed' => $query->whereNotNull('dismissed_at')->whereNull('archived_at'),
            default => $query->whereNull('archived_at')->whereNull('dismissed_at'),
        };
        $query->when($filters['category'] ?? null, fn ($q, $value) => $q->where('category', $value));
        $query->when($filters['priority'] ?? null, fn ($q, $value) => $q->where('priority', $value));
        $query->when($filters['from'] ?? null, fn ($q, $value) => $q->where('created_at', '>=', Carbon::parse($value)->startOfDay()));
        $query->when($filters['to'] ?? null, fn ($q, $value) => $q->where('created_at', '<=', Carbon::parse($value)->endOfDay()));
        $query->when($filters['article_tracking_code'] ?? null, fn ($q, $value) => $q->whereHas('article', fn ($article) => $article->where('tracking_code', 'like', '%'.$value.'%')));
        $query->when($filters['q'] ?? null, function ($q, $value) {
            $term = str_replace(['%', '_'], ['\\%', '\\_'], $value);
            $q->where(fn ($inner) => $inner->where('render_data', 'like', '%'.$term.'%')->orWhere('type', 'like', '%'.$term.'%'));
        });

        return $query;
    }

    private function countValues(Request $request): array
    {
        $base = UserNotification::query()->where('recipient_user_id', $request->user()->id)->where('in_app_visible', true)->whereNull('archived_at')->whereNull('dismissed_at');

        return [
            'unread_count' => (clone $base)->whereNull('read_at')->count(),
            'action_required_count' => (clone $base)->where('action_status', 'pending')->where(fn ($query) => $query->whereNull('action_expires_at')->orWhere('action_expires_at', '>', now()))->count(),
        ];
    }

    private function authorizeFor(Request $request, UserNotification $notification, string $ability): void
    {
        abort_unless($request->user()->can($ability, $notification), 403);
    }
}
