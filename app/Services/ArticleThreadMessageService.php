<?php

namespace App\Services;

use App\Models\ArticleAuditLog;
use App\Models\ArticleFile;
use App\Models\ArticleThread;
use App\Models\ArticleThreadMessage;
use App\Models\ArticleThreadReadState;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ArticleThreadMessageService
{
    public function __construct(
        private ArticleThreadAccessService $access,
        private ArticleFileAccessService $fileAccess,
        private ArticleThreadNotificationService $notifications,
    ) {}

    public function create(ArticleThread $thread, User $sender, array $data): ArticleThreadMessage
    {
        abort_unless($this->access->canReply($sender, $thread), $thread->status === 'active' ? 403 : 409);
        $existing = isset($data['client_request_id']) ? $thread->messages()->where('sender_id', $sender->id)->where('client_request_id', $data['client_request_id'])->first() : null;
        if ($existing) {
            return $existing->load(['sender:id,name', 'attachments.file', 'mentions.user:id,name']);
        }

        $message = DB::transaction(function () use ($thread, $sender, $data) {
            $parent = isset($data['parent_message_id']) ? $thread->messages()->find($data['parent_message_id']) : null;
            if (isset($data['parent_message_id']) && ! $parent) {
                throw ValidationException::withMessages(['parent_message_id' => 'The reply target is outside this thread.']);
            }
            $mentionIds = collect($data['mentions'] ?? [])->map(fn ($id) => (int) $id)->unique();
            $allowedMentions = $thread->activeParticipants()->whereIn('user_id', $mentionIds)->pluck('user_id');
            if ($allowedMentions->count() !== $mentionIds->count()) {
                throw ValidationException::withMessages(['mentions' => 'Only active thread participants may be mentioned.']);
            }
            $files = ArticleFile::query()->whereIn('id', $data['attachment_ids'] ?? [])->get();
            if ($files->count() !== count(array_unique($data['attachment_ids'] ?? []))) {
                throw ValidationException::withMessages(['attachment_ids' => 'One or more attachments are invalid.']);
            }
            foreach ($files as $file) {
                $this->assertAttachment($thread, $sender, $file);
            }

            $message = $thread->messages()->create([
                'sender_id' => $sender->id, 'parent_message_id' => $parent?->id,
                'message_type' => $files->isEmpty() ? 'user_message' : 'attachment_message',
                'body' => trim(strip_tags($data['body'])), 'body_format' => 'plain_text',
                'audience_variant' => $thread->privacy_classification, 'is_system' => false,
                'client_request_id' => $data['client_request_id'] ?? null,
            ]);
            foreach ($files as $file) {
                $message->attachments()->create([
                    'article_file_id' => $file->id, 'safe_filename' => $this->safeFilename($file, $thread),
                    'mime_type' => $file->mime_type, 'size' => $file->size, 'checksum' => $file->checksum_sha256,
                    'scan_status' => $file->scan_status, 'uploaded_by' => $sender->id,
                    'visibility_classification' => $thread->privacy_classification,
                ]);
            }
            foreach ($mentionIds as $id) {
                $message->mentions()->create(['mentioned_user_id' => $id, 'created_at' => now()]);
            }
            $thread->increment('message_count');
            $thread->update(['last_message_at' => $message->created_at]);
            ArticleThreadReadState::updateOrCreate(['thread_id' => $thread->id, 'user_id' => $sender->id], ['last_read_message_id' => $message->id, 'last_read_at' => now()]);
            $this->audit($thread, $sender, 'article_thread.message_created', $message->id);

            return $message;
        });
        $this->notifications->messagePosted($thread->fresh(['article', 'activeParticipants.user.role']), $message, $sender, $data['mentions'] ?? []);

        return $message->load(['sender:id,name', 'parent:id,sender_id,body', 'attachments.file', 'mentions.user:id,name']);
    }

    public function edit(ArticleThread $thread, ArticleThreadMessage $message, User $actor, string $body): ArticleThreadMessage
    {
        abort_unless($this->access->canEditMessage($actor, $thread, $message), 403);
        DB::transaction(function () use ($message, $actor, $body, $thread) {
            $message->revisions()->create(['edited_by' => $actor->id, 'previous_body' => $message->body, 'created_at' => now()]);
            $message->update(['body' => trim(strip_tags($body)), 'edited_at' => now()]);
            $this->audit($thread, $actor, 'article_thread.message_edited', $message->id);
        });

        return $message->fresh(['sender:id,name', 'attachments.file', 'mentions.user:id,name']);
    }

    public function delete(ArticleThread $thread, ArticleThreadMessage $message, User $actor): void
    {
        abort_unless($this->access->canEditMessage($actor, $thread, $message), 403);
        $message->delete();
        $thread->decrement('message_count');
        $this->audit($thread, $actor, 'article_thread.message_deleted', $message->id);
    }

    private function assertAttachment(ArticleThread $thread, User $sender, ArticleFile $file): void
    {
        if ((int) $file->article_id !== (int) $thread->article_id || $file->scan_status !== 'clean' || ! $this->fileAccess->canView($sender, $file)) {
            throw ValidationException::withMessages(['attachment_ids' => 'Attachments must be clean, authorized files from this article.']);
        }
        if ($thread->article_version_id && $file->article_version_id && (int) $file->article_version_id !== (int) $thread->article_version_id) {
            throw ValidationException::withMessages(['attachment_ids' => 'An attachment belongs to another article version.']);
        }
        if ($thread->privacy_classification === 'author_visible' && in_array($file->visibility, ['editor_only', 'reviewer_confidential', 'internal'], true)) {
            throw ValidationException::withMessages(['attachment_ids' => 'A confidential file cannot be attached to an author-visible thread.']);
        }
    }

    private function safeFilename(ArticleFile $file, ArticleThread $thread): string
    {
        if ($thread->privacy_classification === 'author_visible' && str_starts_with((string) $file->assignment_type, 'reviewer')) {
            return 'Reviewer attachment'.($file->safe_original_name ? '.'.pathinfo($file->safe_original_name, PATHINFO_EXTENSION) : '');
        }

        return Str::limit($file->safe_original_name ?: $file->original_name ?: 'Communication attachment', 240, '');
    }

    private function audit(ArticleThread $thread, User $actor, string $event, int $messageId): void
    {
        ArticleAuditLog::create(['article_id' => $thread->article_id, 'actor_id' => $actor->id, 'event' => $event,
            'payload' => ['thread_id' => $thread->id, 'message_id' => $messageId]]);
    }
}
