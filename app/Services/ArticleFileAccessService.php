<?php

namespace App\Services;

use App\Models\ArticleFile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ArticleFileAccessService
{
    public function canView(?User $user, ArticleFile $file): bool
    {
        if (! $user || $file->scan_status !== 'clean') {
            return false;
        }
        $article = $file->article;
        if ($article->isDirectPublication()) {
            if ($user->hasRole('super_admin')) {
                return true;
            }

            return $user->hasRole('publisher') && DB::table('magazine_user')->where('user_id', $user->id)
                ->where('magazine_id', $article->magazine_id)->where(fn ($q) => $q->where('role', 'publisher')->orWhereNull('role'))->exists();
        }
        if ($user->hasRole(['super_admin', 'admin']) || $user->can('approve', $article)) {
            return true;
        }
        if ((int) $article->user_id === (int) $user->id || $article->articleAuthors()->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('co_author_email', $user->email))->exists()) {
            return ! in_array($file->visibility, ['editor_only', 'reviewer_confidential', 'internal'], true);
        }
        if ($user->hasRole('reviewer')) {
            return $file->article_version_id && $article->reviewerAssignments()->where('reviewer_id', $user->id)->where('article_version_id', $file->article_version_id)->whereNull('revoked_at')->whereIn('status', ['accepted', 'in_progress'])->exists()
                && ! in_array($file->file_type, ['plagiarism_report', 'copy_edited_file', 'proof_file', 'publication_pdf'], true);
        }
        if ($user->hasRole(['copy_editor', 'proofreader'])) {
            $assignment = $article->productionAssignments()->where('user_id', $user->id)->whereNull('revoked_at')->latest()->first();
            if (! $assignment) {
                return false;
            }

            return $article->activeAcceptedFileSet?->items()->where('article_file_id', $file->id)->exists()
                || ($file->assignment_type === 'production_assignment' && (int) $file->assignment_id === (int) $assignment->id)
                || $article->proofRounds()->where(fn ($q) => $q->where('source_file_id', $file->id)->orWhere('author_file_id', $file->id)->orWhere('corrected_file_id', $file->id))->exists();
        }
        if ($user->hasRole('publisher')) {
            return $article->publicationRecords()->whereHas('files', fn ($q) => $q->where('article_file_id', $file->id))->exists();
        }

        return false;
    }
}
