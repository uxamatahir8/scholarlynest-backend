<?php

namespace App\Services\Notifications;

use App\Models\Article;
use App\Models\NotificationEvent;
use App\Models\ProductionAssignment;
use App\Models\ReviewerAssignment;
use App\Models\SubEditorAssignment;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class NotificationRecipientResolver
{
    public function resolve(NotificationEvent $event): Collection
    {
        $article = $event->article?->loadMissing(['user', 'articleAuthors.user', 'magazine']);
        $targets = collect($event->payload['recipient_user_ids'] ?? [])
            ->push($event->payload['recipient_user_id'] ?? null)
            ->filter()->unique()->values();

        if ($targets->isNotEmpty()) {
            $users = User::query()->whereIn('id', $targets)->get();

            return $this->dedupe($users->map(fn (User $user) => [
                'user' => $user,
                'privacy_variant' => $this->targetVariant($event, $user),
            ]));
        }

        if (str_starts_with($event->event_type, 'support.')) {
            return $this->supportRecipients($event);
        }

        if (! $article) {
            return collect();
        }

        $authors = $this->authors($article);
        $editors = $this->editors($article);
        $admins = $this->admins();
        $subEditors = $this->subEditors($article);
        $reviewers = $this->reviewers($article, $event->subject_id);
        $production = $this->production($article, $event->subject_id);
        $publishers = $this->publishers($article);
        $actor = $event->actor ? collect([['user' => $event->actor, 'privacy_variant' => 'assigner']]) : collect();
        $superAdmins = $this->recipients(User::query()->whereHas('role', fn ($query) => $query->where('name', 'super_admin'))->get(), 'admin');
        $directAuthors = $this->recipients(User::query()->whereIn('id', $article->articleAuthors->pluck('user_id')->filter())->get(), 'author');

        $resolved = match ($event->event_type) {
            'direct_publication.created' => $actor,
            'direct_publication.ready' => $publishers->merge($superAdmins),
            'direct_publication.scheduled' => $actor->merge($publishers)->merge($superAdmins),
            'direct_publication.published', 'direct_publication.unpublished' => $directAuthors->merge($publishers)->merge($superAdmins),
            'direct_publication.file_replaced', 'direct_publication.metadata_corrected' => $publishers->merge($superAdmins),
            'article.submitted' => $authors->merge($editors)->merge($admins),
            'article.desk_rejected', 'article.under_review' => $authors->merge($editors)->merge($admins),
            'transfer.requested', 'transfer.accepted', 'transfer.rejected' => $authors->merge($editors)->merge($admins),
            'sub_editor.assigned' => $subEditors->merge($authors)->merge($actor)->merge($admins),
            'sub_editor.recommendation_submitted' => $editors->merge($admins),
            'reviewer.invited', 'review.invitation_reminded' => $reviewers,
            'reviewer.assigned' => $authors->merge($actor)->merge($admins),
            'review.accepted', 'review.declined', 'review.started', 'review.draft_saved', 'review.submitted', 'review.submitted_after_decision' => $editors->merge($subEditors)->merge($admins),
            'review.decision_proceeded_open', 'review.closed_without_review' => $reviewers,
            'editorial_decision.pending_reviews' => $editors->merge($subEditors)->merge($admins),
            'review.invitation_expired' => $reviewers->merge($editors)->merge($subEditors)->merge($admins),
            'review.reopened' => $reviewers->merge($editors)->merge($admins),
            'revision.requested' => $authors,
            'article.resubmitted', 'article.version_created' => $authors->merge($editors)->merge($subEditors)->merge($admins),
            'article.accepted', 'article.rejected' => $authors->merge($editors)->merge($admins),
            'accepted_file_set.created', 'accepted_file_set.superseded' => $production->merge($editors)->merge($publishers)->merge($admins),
            'article_file.available', 'article_file.rejected' => $actor->merge($production)->merge($subEditors)->merge($reviewers),
            'production.assigned' => $production->merge($authors)->merge($actor)->merge($admins),
            'production.completed', 'author.final_review_denied' => $authors->merge($production)->merge($editors)->merge($publishers)->merge($admins),
            'author.final_review_requested', 'author.final_review_reminder' => $authors,
            'author.final_review_approved', 'author.final_review_auto_approved', 'article.ready_for_publication',
            'article.issue_assigned', 'issue.published', 'article.published', 'post_publication.recorded' => $authors->merge($editors)->merge($publishers)->merge($admins),
            'deadline.due_in_3_days', 'deadline.due_today', 'deadline.overdue_3_days', 'deadline.changed' => $reviewers->merge($subEditors)->merge($production)->merge($editors),
            default => collect(),
        };

        if ($event->actor_id && ! str_starts_with($event->event_type, 'account.') && ! str_starts_with($event->event_type, 'direct_publication.')) {
            $resolved = $resolved->reject(fn ($item) => (int) ($item['user']?->id ?? 0) === (int) $event->actor_id);
        }

        return $this->dedupe($resolved);
    }

    private function authors(Article $article): Collection
    {
        $items = collect();
        if ($article->user) {
            $items->push(['user' => $article->user, 'privacy_variant' => 'author']);
        }
        foreach ($article->articleAuthors as $author) {
            if ($author->user) {
                $items->push([
                    'user' => $author->user,
                    'privacy_variant' => ($author->is_owner || $author->is_corresponding) ? 'author' : 'co_author',
                ]);
            }
        }

        return $this->dedupe($items);
    }

    private function editors(Article $article): Collection
    {
        $users = User::query()->with(['role', 'magazines'])->whereHas('magazines', fn ($query) => $query->where('magazines.id', $article->magazine_id))->get()
            ->filter(fn (User $user) => $user->canEditPublication($article->magazine));

        return $this->recipients($users, 'editor');
    }

    private function admins(): Collection
    {
        return $this->recipients(User::query()->whereHas('role', fn ($query) => $query->whereIn('name', ['super_admin', 'admin']))->get(), 'admin');
    }

    private function subEditors(Article $article): Collection
    {
        return $this->recipients(User::query()->whereIn('id', SubEditorAssignment::query()->where('article_id', $article->id)->whereIn('status', ['pending', 'in_progress'])->pluck('sub_editor_id'))->get(), 'sub_editor');
    }

    private function reviewers(Article $article, ?int $subjectId): Collection
    {
        $query = ReviewerAssignment::query()->where('article_id', $article->id)->whereNotNull('reviewer_id');
        if ($subjectId) {
            $query->whereKey($subjectId);
        } else {
            $query->whereIn('status', ['pending', 'accepted', 'review_in_progress', 'reopened']);
        }

        return $this->recipients(User::query()->whereIn('id', $query->pluck('reviewer_id'))->get(), 'reviewer');
    }

    private function production(Article $article, ?int $subjectId): Collection
    {
        $query = ProductionAssignment::query()->where('article_id', $article->id);
        if ($subjectId) {
            $query->whereKey($subjectId);
        } else {
            $query->whereIn('status', ['pending', 'in_progress']);
        }

        return $this->recipients(User::query()->whereIn('id', $query->pluck('user_id'))->get(), 'assignee');
    }

    private function publishers(Article $article): Collection
    {
        $users = User::query()->with('role')->whereHas('magazines', fn ($query) => $query->where('magazines.id', $article->magazine_id))->get()
            ->filter(fn (User $user) => $user->hasRole('publisher'));

        return $this->recipients($users, 'publisher');
    }

    private function supportRecipients(NotificationEvent $event): Collection
    {
        $ticket = SupportTicket::find($event->subject_id ?? ($event->payload['support_ticket_id'] ?? null));
        if (! $ticket) {
            return collect();
        }
        $owner = $ticket->user ? collect([['user' => $ticket->user, 'privacy_variant' => 'support_owner']]) : collect();
        $staff = $this->recipients(User::query()->with('role')->get()->filter(fn (User $user) => $user->hasPermission('support_ticket_management')), 'support_staff');

        $recipients = $owner->merge($staff);
        if ($event->event_type !== 'support.ticket_created' && $event->actor_id) {
            $recipients = $recipients->reject(fn ($item) => (int) $item['user']->id === (int) $event->actor_id);
        }

        return $this->dedupe($recipients);
    }

    private function recipients(Collection $users, string $variant): Collection
    {
        return collect($users->all())->filter(fn ($user) => $user instanceof User && $user->email)
            ->map(fn (User $user) => ['user' => $user, 'privacy_variant' => $variant]);
    }

    private function dedupe(Collection $items): Collection
    {
        $unique = $items->filter(fn ($item) => ($item['user'] ?? null) instanceof User)
            ->unique(fn ($item) => $item['user']->id.'|'.$item['privacy_variant']);
        $authorIds = $unique->where('privacy_variant', 'author')->map(fn ($item) => (int) $item['user']->id)->all();

        return $unique->reject(fn ($item) => $item['privacy_variant'] === 'co_author' && in_array((int) $item['user']->id, $authorIds, true))
            ->values();
    }

    private function targetVariant(NotificationEvent $event, User $user): string
    {
        $explicit = $event->payload['recipient_privacy_variant'] ?? null;
        $variant = $explicit ?: match (true) {
            str_starts_with($event->event_type, 'account.') => 'account',
            str_starts_with($event->event_type, 'support.') => $this->supportTargetVariant($event, $user),
            in_array($event->event_type, ['reviewer.invited', 'review.invitation_reminded', 'review.invitation_expired', 'review.reopened', 'review.decision_proceeded_open', 'review.closed_without_review'], true) => 'reviewer',
            in_array($event->event_type, ['author.final_review_requested', 'author.final_review_reminder'], true) => 'author',
            str_starts_with($event->event_type, 'deadline.') => match ($event->subject_type) {
                'reviewer_assignment' => 'reviewer',
                'sub_editor_assignment' => 'sub_editor',
                'production_assignment' => 'assignee',
                default => throw new InvalidArgumentException("A privacy variant is required for targeted event [{$event->event_type}]."),
            },
            default => throw new InvalidArgumentException("A privacy variant is required for targeted event [{$event->event_type}]."),
        };

        if (! in_array($variant, config('notification_system.privacy_variants', []), true)) {
            throw new InvalidArgumentException("Unsupported notification privacy variant [{$variant}].");
        }
        if ($explicit && ! in_array($variant, $this->compatibleTargetVariants($event), true)) {
            throw new InvalidArgumentException("Privacy variant [{$variant}] is incompatible with targeted event [{$event->event_type}].");
        }

        return $variant;
    }

    private function compatibleTargetVariants(NotificationEvent $event): array
    {
        return match (true) {
            str_starts_with($event->event_type, 'article_thread.') => ['author', 'reviewer', 'sub_editor', 'assignee', 'editor', 'publisher', 'admin'],
            str_starts_with($event->event_type, 'account.') => ['account'],
            str_starts_with($event->event_type, 'support.') => ['support_owner', 'support_staff'],
            in_array($event->event_type, ['reviewer.invited', 'review.invitation_reminded', 'review.invitation_expired', 'review.reopened', 'review.decision_proceeded_open', 'review.closed_without_review'], true) => ['reviewer'],
            in_array($event->event_type, ['author.final_review_requested', 'author.final_review_reminder'], true) => ['author'],
            str_starts_with($event->event_type, 'deadline.') => ['reviewer', 'sub_editor', 'assignee', 'editor'],
            $event->event_type === 'article_file.rejected' => ['assignee'],
            default => [],
        };
    }

    private function supportTargetVariant(NotificationEvent $event, User $user): string
    {
        $ticket = SupportTicket::find($event->subject_id ?? ($event->payload['support_ticket_id'] ?? null));

        return $ticket && (int) $ticket->user_id === (int) $user->id ? 'support_owner' : 'support_staff';
    }
}
