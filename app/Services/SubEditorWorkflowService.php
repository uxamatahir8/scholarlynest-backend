<?php

namespace App\Services;

use App\Models\Article;
use App\Models\SubEditorAssignment;
use App\Models\User;

class SubEditorWorkflowService
{
    public function assign(Article $article, User $actor, int $versionId, int $subEditorId, ?string $dueAt, string $key): array
    {
        return app(ArticleLifecycleService::class)->command($article, $actor, 'assign-sub-editor', $key, compact('versionId', 'subEditorId', 'dueAt'), 'sub_editor.assigned', 'sub_editor.assigned',
            function (Article $locked) use ($actor, $versionId, $subEditorId, $dueAt, $key) {
                if ((int) $locked->current_version_id !== $versionId) {
                    app(ArticleLifecycleService::class)->conflict('Sub-editors can only be assigned to the current version.');
                }
                $previous = SubEditorAssignment::query()->where('article_version_id', $versionId)->whereNull('revoked_at')->lockForUpdate()->get();
                $previous->each->update(['status' => 'superseded', 'revoked_at' => now()]);
                $assignment = SubEditorAssignment::create(['article_id' => $locked->id, 'article_version_id' => $versionId, 'round_number' => 1, 'sub_editor_id' => $subEditorId, 'assigned_by' => $actor->id, 'status' => 'pending', 'due_date' => $dueAt, 'idempotency_key' => $key]);
                $previous->each(fn ($old) => $old->update(['superseded_by_id' => $assignment->id]));

                return ['assignment_id' => $assignment->id];
            });
    }

    public function recommend(SubEditorAssignment $assignment, User $actor, string $recommendation, ?string $authorComments, ?string $internalComments, string $key): array
    {
        return app(ArticleLifecycleService::class)->command($assignment->article, $actor, 'sub-editor-recommendation', $key, ['assignment_id' => $assignment->id, 'recommendation' => $recommendation], 'sub_editor.recommendation_submitted', 'sub_editor.recommendation_submitted',
            function () use ($assignment, $actor, $recommendation, $authorComments, $internalComments) {
                $locked = SubEditorAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
                if ((int) $locked->sub_editor_id !== (int) $actor->id || $locked->revoked_at) {
                    app(ArticleLifecycleService::class)->conflict('This sub-editor assignment is no longer active.');
                }
                if ($locked->completed_at) {
                    app(ArticleLifecycleService::class)->conflict('The recommendation is immutable after submission.');
                }
                $locked->update(['status' => 'completed', 'completed_at' => now(), 'recommendation' => $recommendation, 'comments' => $authorComments, 'author_comments' => $authorComments, 'internal_comments' => $internalComments]);

                return ['assignment_id' => $locked->id];
            });
    }
}
