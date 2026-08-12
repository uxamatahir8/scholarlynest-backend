<?php

namespace App\Services;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\ProductionAssignment;
use App\Models\User;

class ProductionWorkflowService
{
    public function assignCopyEditor(Article $article, User $actor, int $userId, ?string $dueAt, string $key): array
    {
        return app(ArticleLifecycleService::class)->command($article, $actor, 'assign-copy-editor', $key, ['user_id' => $userId, 'due_at' => $dueAt], 'production.assigned', 'production.assigned', function (Article $locked) use ($actor, $userId, $dueAt, $key) {
            $set = $locked->activeAcceptedFileSet()->lockForUpdate()->first();
            if (! $set || (int) $locked->accepted_version_id !== (int) $set->article_version_id) {
                app(ArticleLifecycleService::class)->conflict('An active accepted version and accepted file set are required.');
            }
            $copyEditor = User::query()->with('role')->find($userId);
            if (! $copyEditor?->hasRole('copy_editor')) {
                app(ArticleLifecycleService::class)->conflict('The assignee must have the copy editor role.');
            }
            ProductionAssignment::query()->where('article_id', $locked->id)->where('role', 'copy_editor')->whereNull('revoked_at')->update(['status' => 'superseded', 'revoked_at' => now()]);
            $assignment = ProductionAssignment::create(['article_id' => $locked->id, 'article_version_id' => $set->article_version_id, 'accepted_file_set_id' => $set->id, 'user_id' => $userId, 'role' => 'copy_editor', 'assigned_by' => $actor->id, 'status' => 'pending', 'due_date' => $dueAt, 'idempotency_key' => $key]);
            $locked->update(['status' => ArticleStatus::COPY_EDITING]);

            return ['assignment_id' => $assignment->id, 'accepted_file_set_id' => $set->id];
        });
    }

    public function complete(ProductionAssignment $assignment, User $actor, int $copyeditedFileId, ?string $notes, string $key): array
    {
        return app(ArticleLifecycleService::class)->command($assignment->article, $actor, 'complete-copyediting', $key, ['assignment_id' => $assignment->id, 'article_file_id' => $copyeditedFileId], 'production.completed', 'production.completed', function (Article $locked) use ($assignment, $actor, $copyeditedFileId, $notes) {
            $task = ProductionAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            if ((int) $task->user_id !== (int) $actor->id || $task->role !== 'copy_editor' || $task->revoked_at || ! in_array($task->status, ['pending', 'in_progress', 'correction_required'], true)) {
                app(ArticleLifecycleService::class)->conflict('This copyediting assignment is not active.');
            }
            $file = $locked->files()->whereKey($copyeditedFileId)->where('file_type', 'copy_edited_file')->where('assignment_type', 'production_assignment')->where('assignment_id', $task->id)->where('scan_status', 'clean')->first();
            if (! $file) {
                app(ArticleLifecycleService::class)->conflict('A clean copyedited manuscript uploaded for this assignment is required.');
            }
            $task->update(['status' => 'completed', 'completed_at' => now(), 'notes' => $notes]);
            $locked->update(['status' => ArticleStatus::PROOFREADING]);

            return ['assignment_id' => $task->id, 'copyedited_file_id' => $file->id];
        });
    }
}
