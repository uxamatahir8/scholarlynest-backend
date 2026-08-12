<?php

namespace App\Http\Controllers;

use App\Constants\ArticleThreadType;
use App\Http\Requests\StoreArticleThreadMessageRequest;
use App\Http\Requests\StoreArticleThreadRequest;
use App\Models\Article;
use App\Models\ArticleAuditLog;
use App\Models\ArticleThread;
use App\Models\ArticleThreadMessage;
use App\Models\ArticleThreadMessageAttachment;
use App\Models\ArticleThreadParticipant;
use App\Models\User;
use App\Services\ArticleThreadAccessService;
use App\Services\ArticleThreadManifestService;
use App\Services\ArticleThreadMessageService;
use App\Services\ArticleThreadParticipantService;
use App\Services\ArticleThreadReadService;
use App\Services\ArticleThreadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ArticleThreadController extends Controller
{
    public function __construct(
        private ArticleThreadAccessService $access,
        private ArticleThreadService $threads,
        private ArticleThreadManifestService $manifest,
        private ArticleThreadMessageService $messages,
        private ArticleThreadParticipantService $participants,
        private ArticleThreadReadService $reads,
    ) {}

    public function index(Request $request, Article $article): JsonResponse
    {
        $query = $this->access->accessibleQuery($request->user())->where('article_id', $article->id)
            ->when(! $request->boolean('archived'), fn ($q) => $q->where('status', '!=', 'archived'))
            ->with(['article', 'version', 'activeParticipants.user', 'messages' => fn ($q) => $q->latest('id')->limit(1)])
            ->orderByDesc('last_message_at')->orderBy('id');
        $items = $query->get()->map(fn ($thread) => $this->manifest->thread($thread, $request->user()));

        return response()->json(['data' => $items, 'meta' => ['unread_count' => $items->sum('unread_count')]]);
    }

    public function store(StoreArticleThreadRequest $request, Article $article): JsonResponse
    {
        abort_unless($request->user()->hasRole('super_admin') || ($request->user()->isPublicationEditor() && $article->magazine && $request->user()->canEditPublication($article->magazine)), 403);
        if ($request->filled('article_version_id')) {
            abort_unless($article->versions()->whereKey($request->integer('article_version_id'))->exists(), 422);
        }
        $thread = $this->threads->createRestricted($article, $request->user(), $request->validated());

        return response()->json(['data' => $this->manifest->thread($thread, $request->user())], 201);
    }

    public function show(Request $request, Article $article, ArticleThread $thread): JsonResponse
    {
        $this->scope($request, $article, $thread);

        return response()->json(['data' => $this->manifest->thread($thread, $request->user())]);
    }

    public function update(Request $request, Article $article, ArticleThread $thread): JsonResponse
    {
        $this->scope($request, $article, $thread, true);
        $data = $request->validate(['title' => ['required', 'string', 'min:3', 'max:180']]);
        $thread->update(['title' => trim($data['title'])]);
        ArticleAuditLog::create(['article_id' => $article->id, 'actor_id' => $request->user()->id, 'event' => 'article_thread.title_changed', 'payload' => ['thread_id' => $thread->id]]);

        return response()->json(['data' => $this->manifest->thread($thread->fresh(), $request->user())]);
    }

    public function transition(Request $request, Article $article, ArticleThread $thread, string $state): JsonResponse
    {
        $this->scope($request, $article, $thread, true);

        return response()->json(['data' => $this->manifest->thread($this->threads->transition($thread, $request->user(), $state), $request->user())]);
    }

    public function lock(Request $request, Article $article, ArticleThread $thread): JsonResponse
    {
        return $this->transition($request, $article, $thread, 'locked');
    }

    public function unlock(Request $request, Article $article, ArticleThread $thread): JsonResponse
    {
        return $this->transition($request, $article, $thread, 'active');
    }

    public function archive(Request $request, Article $article, ArticleThread $thread): JsonResponse
    {
        return $this->transition($request, $article, $thread, 'archived');
    }

    public function reopen(Request $request, Article $article, ArticleThread $thread): JsonResponse
    {
        return $this->transition($request, $article, $thread, 'active');
    }

    public function messages(Request $request, Article $article, ArticleThread $thread): JsonResponse
    {
        $this->scope($request, $article, $thread);
        $paginator = $thread->messages()->withTrashed()->with(['sender:id,name', 'parent.sender:id,name', 'attachments.file:id', 'mentions.user:id,name'])
            ->orderByDesc('id')->cursorPaginate(min(max($request->integer('per_page', 30), 1), 100));

        return response()->json(['data' => collect($paginator->items())->reverse()->values()->map(fn ($message) => $this->manifest->message($message, $request->user(), $thread)),
            'meta' => ['next_cursor' => $paginator->nextCursor()?->encode(), 'has_more' => $paginator->hasMorePages()]]);
    }

    public function storeMessage(StoreArticleThreadMessageRequest $request, Article $article, ArticleThread $thread): JsonResponse
    {
        $this->scope($request, $article, $thread);
        $message = $this->messages->create($thread, $request->user(), $request->validated());

        return response()->json(['data' => $this->manifest->message($message, $request->user(), $thread)], 201);
    }

    public function updateMessage(Request $request, Article $article, ArticleThread $thread, ArticleThreadMessage $message): JsonResponse
    {
        $this->scopeMessage($request, $article, $thread, $message);
        $data = $request->validate(['body' => ['required', 'string', 'min:1', 'max:'.config('article_threads.max_message_length', 10000)]]);

        return response()->json(['data' => $this->manifest->message($this->messages->edit($thread, $message, $request->user(), $data['body']), $request->user(), $thread)]);
    }

    public function deleteMessage(Request $request, Article $article, ArticleThread $thread, ArticleThreadMessage $message): JsonResponse
    {
        $this->scopeMessage($request, $article, $thread, $message);
        $this->messages->delete($thread, $message, $request->user());

        return response()->json(['message' => 'Message deleted.']);
    }

    public function participants(Request $request, Article $article, ArticleThread $thread): JsonResponse
    {
        $this->scope($request, $article, $thread);

        return response()->json(['data' => $this->manifest->thread($thread, $request->user())['participants']]);
    }

    public function addParticipant(Request $request, Article $article, ArticleThread $thread): JsonResponse
    {
        $this->scope($request, $article, $thread, true);
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id'], 'access_level' => ['nullable', Rule::in(['read', 'reply', 'manage', 'read_only'])]]);
        $participant = $this->participants->add($thread, User::findOrFail($data['user_id']), $request->user(), $data['access_level'] ?? 'reply');

        return response()->json(['data' => $participant], 201);
    }

    public function removeParticipant(Request $request, Article $article, ArticleThread $thread, ArticleThreadParticipant $participant): JsonResponse
    {
        $this->scope($request, $article, $thread, true);
        abort_unless((int) $participant->thread_id === (int) $thread->id, 404);
        $this->participants->remove($thread, $participant, $request->user());

        return response()->json(['message' => 'Participant removed.']);
    }

    public function mentionable(Request $request, Article $article, ArticleThread $thread): JsonResponse
    {
        $this->scope($request, $article, $thread);

        return response()->json(['data' => $this->manifest->thread($thread, $request->user())['mentionable_users']]);
    }

    public function markRead(Request $request, Article $article, ArticleThread $thread): JsonResponse
    {
        $this->scope($request, $article, $thread);
        $data = $request->validate(['message_id' => ['nullable', 'integer']]);
        $message = isset($data['message_id']) ? $thread->messages()->findOrFail($data['message_id']) : null;
        $state = $this->reads->markRead($thread, $request->user(), $message);

        return response()->json(['data' => ['last_read_message_id' => $state->last_read_message_id, 'last_read_at' => $state->last_read_at?->toISOString(), 'unread_count' => 0]]);
    }

    public function search(Request $request, Article $article): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100'], 'thread_type' => ['nullable', Rule::in(ArticleThreadType::ALL)], 'archived' => ['nullable', 'boolean']]);
        $term = str_replace(['%', '_'], ['\\%', '\\_'], $data['q']);
        $query = $this->access->accessibleQuery($request->user())->where('article_id', $article->id)
            ->when(isset($data['thread_type']), fn ($q) => $q->where('thread_type', $data['thread_type']))
            ->when(! ($data['archived'] ?? false), fn ($q) => $q->where('status', '!=', 'archived'))
            ->where(fn ($q) => $q->where('title', 'like', "%{$term}%")->orWhereHas('messages', fn ($m) => $m->where('body', 'like', "%{$term}%")));

        return response()->json(['data' => $query->limit(50)->get()->map(fn ($thread) => $this->manifest->thread($thread, $request->user()))]);
    }

    public function unreadCount(Request $request, Article $article): JsonResponse
    {
        $threads = $this->access->accessibleQuery($request->user())->where('article_id', $article->id)->get();

        return response()->json(['data' => ['article_id' => $article->id, 'unread_count' => $threads->sum(fn ($thread) => $this->reads->unreadCount($thread, $request->user()))]]);
    }

    public function dashboardUnreadCount(Request $request): JsonResponse
    {
        $threads = $this->access->accessibleQuery($request->user())->with('article:id,title,tracking_code,submission_mode')->get();
        $items = $threads->groupBy('article_id')->map(function ($articleThreads) use ($request) {
            $article = $articleThreads->first()->article;
            $count = $articleThreads->sum(fn ($thread) => $this->reads->unreadCount($thread, $request->user()));

            return ['article_id' => $article->id, 'tracking_code' => $article->tracking_code, 'title' => $article->title,
                'unread_count' => $count, 'direct_publication' => $article->isDirectPublication()];
        })->filter(fn ($item) => $item['unread_count'] > 0)->values();

        return response()->json(['data' => ['unread_count' => $items->sum('unread_count'), 'articles' => $items]]);
    }

    public function eligibleUsers(Request $request, Article $article, ArticleThread $thread): JsonResponse
    {
        $this->scope($request, $article, $thread, true);
        $term = trim((string) $request->query('q', ''));
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $term);
        $users = User::query()->with('role')->when($term !== '', fn ($q) => $q->where(fn ($nested) => $nested->where('name', 'like', "%{$escaped}%")->orWhere('email', 'like', "%{$escaped}%")))
            ->limit(100)->get()->filter(fn ($user) => $this->access->participantIsEligible($user, $thread))
            ->take(20)->map(fn ($user) => ['id' => $user->id, 'name' => $user->name, 'role' => $this->access->roleFor($user, $thread)])->values();

        return response()->json(['data' => $users]);
    }

    public function audit(Request $request, Article $article, ArticleThread $thread): JsonResponse
    {
        $this->scope($request, $article, $thread, true);
        $logs = ArticleAuditLog::with('actor:id,name')->where('article_id', $article->id)->where('payload->thread_id', $thread->id)->latest()->paginate(50);

        return response()->json(['data' => $logs]);
    }

    public function download(Request $request, Article $article, ArticleThread $thread, ArticleThreadMessage $message, ArticleThreadMessageAttachment $attachment)
    {
        $this->scopeMessage($request, $article, $thread, $message);
        abort_unless((int) $attachment->message_id === (int) $message->id, 404);

        return app(ArticleFileController::class)->download($request, $attachment->article_file_id);
    }

    private function scope(Request $request, Article $article, ArticleThread $thread, bool $manage = false): void
    {
        abort_unless((int) $thread->article_id === (int) $article->id, 404);
        abort_unless($manage ? $this->access->canManage($request->user(), $thread) : $this->access->canView($request->user(), $thread), 404);
    }

    private function scopeMessage(Request $request, Article $article, ArticleThread $thread, ArticleThreadMessage $message): void
    {
        $this->scope($request, $article, $thread);
        abort_unless((int) $message->thread_id === (int) $thread->id, 404);
    }
}
