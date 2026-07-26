<?php

namespace App\Services;

use App\Constants\ArticleThreadType;
use App\Models\ArticleThread;
use App\Models\ArticleThreadMessage;
use App\Models\User;

class ArticleThreadManifestService
{
    public function __construct(private ArticleThreadAccessService $access, private ArticleThreadReadService $reads) {}

    public function thread(ArticleThread $thread, User $viewer): array
    {
        $thread->loadMissing(['article:id,title,tracking_code,current_version_id', 'version:id,version_number,label', 'activeParticipants.user:id,name', 'messages' => fn ($q) => $q->latest('id')->limit(1)]);
        $canManage = $this->access->canManage($viewer, $thread);
        $participants = $thread->activeParticipants->map(fn ($participant) => [
            'id' => $participant->id,
            'display_name' => $this->participantName($participant->user, $participant->participant_role, $viewer, $thread),
            'role' => $participant->participant_role,
            'access_level' => $participant->access_level,
            'muted' => (bool) $participant->muted_at,
            'user_id' => $canManage ? $participant->user_id : null,
            'can_remove' => $canManage && (int) $participant->user_id !== (int) $viewer->id,
        ])->values();
        $last = $thread->messages->first();

        return [
            'id' => $thread->id, 'article_id' => $thread->article_id,
            'article' => ['tracking_code' => $thread->article->tracking_code, 'title' => $thread->article->title],
            'title' => $thread->title, 'type' => $thread->thread_type,
            'privacy_classification' => $thread->privacy_classification,
            'privacy_label' => ArticleThreadType::AUDIENCE_LABELS[$thread->privacy_classification] ?? 'Restricted',
            'status' => $thread->status, 'context_label' => $this->contextLabel($thread),
            'unread_count' => $this->reads->unreadCount($thread, $viewer),
            'message_count' => $thread->message_count,
            'last_message' => $last ? ['body' => $last->trashed() ? 'Message deleted' : str($last->body)->limit(120)->toString(), 'is_system' => $last->is_system] : null,
            'last_message_at' => $thread->last_message_at?->toISOString(),
            'participants' => $participants,
            'mentionable_users' => $participants->filter(fn ($participant) => $participant['user_id'] !== null || $participant['role'] !== 'reviewer')->map(fn ($participant) => [
                'participant_id' => $participant['id'], 'user_id' => $participant['user_id'], 'display_name' => $participant['display_name'], 'role' => $participant['role'],
            ])->values(),
            'capabilities' => [
                'view' => true, 'send_message' => $this->access->canReply($viewer, $thread),
                'upload_attachment' => $this->access->canReply($viewer, $thread),
                'manage_participants' => $canManage, 'rename' => $canManage,
                'lock' => $canManage && $thread->status === 'active' && $thread->thread_type !== ArticleThreadType::SYSTEM_ACTIVITY,
                'unlock' => $canManage && $thread->status === 'locked',
                'archive' => $canManage && $thread->status !== 'archived' && $thread->thread_type !== ArticleThreadType::SYSTEM_ACTIVITY,
                'reopen' => $canManage && in_array($thread->status, ['archived', 'closed'], true),
                'view_audit' => $canManage,
            ],
            'poll_interval_seconds' => (int) config('article_threads.poll_interval_seconds', 15),
            'created_at' => $thread->created_at?->toISOString(), 'updated_at' => $thread->updated_at?->toISOString(),
        ];
    }

    public function message(ArticleThreadMessage $message, User $viewer, ArticleThread $thread): array
    {
        $message->loadMissing(['sender:id,name', 'parent.sender:id,name', 'attachments.file:id', 'mentions.user:id,name']);
        $deleted = $message->trashed();
        $senderRole = $message->sender ? $this->access->roleFor($message->sender, $thread) : 'former_participant';

        return [
            'id' => $message->id,
            'sender' => $message->is_system ? ['display_name' => 'ScholarlyNest', 'role' => 'system'] : [
                'display_name' => $this->participantName($message->sender, $senderRole, $viewer, $thread),
                'role' => $senderRole,
            ],
            'body' => $deleted ? null : $message->body,
            'parent' => $message->parent ? ['id' => $message->parent->id, 'body' => str($message->parent->body)->limit(120)->toString()] : null,
            'attachments' => $message->attachments->map(fn ($attachment) => [
                'id' => $attachment->id, 'filename' => $attachment->safe_filename, 'mime_type' => $attachment->mime_type,
                'size' => $attachment->size, 'scan_status' => $attachment->scan_status,
                'download_url' => "/api/articles/{$thread->article_id}/threads/{$thread->id}/messages/{$message->id}/attachments/{$attachment->id}/download",
            ])->values(),
            'mentions' => $message->mentions->map(fn ($mention) => ['display_name' => $this->participantName($mention->user, $mention->user ? $this->access->roleFor($mention->user, $thread) : 'former_participant', $viewer, $thread)])->values(),
            'message_type' => $message->message_type, 'is_system' => $message->is_system,
            'edited' => (bool) $message->edited_at, 'deleted' => $deleted,
            'capabilities' => ['edit' => ! $deleted && $this->access->canEditMessage($viewer, $thread, $message), 'delete' => ! $deleted && $this->access->canEditMessage($viewer, $thread, $message)],
            'created_at' => $message->created_at?->toISOString(), 'edited_at' => $message->edited_at?->toISOString(),
        ];
    }

    private function participantName(?User $user, string $role, User $viewer, ArticleThread $thread): string
    {
        if (! $user) {
            return 'Former participant';
        }
        if ($role === 'reviewer' && $thread->privacy_classification === 'author_visible') {
            return 'Reviewer';
        }

        return $user->name ?: str($role)->headline()->toString();
    }

    private function contextLabel(ArticleThread $thread): string
    {
        if ($thread->version) {
            return ($thread->version->label ?: 'Version '.$thread->version->version_number).' · '.str($thread->context_type)->headline();
        }

        return str($thread->context_type)->headline()->toString();
    }
}
