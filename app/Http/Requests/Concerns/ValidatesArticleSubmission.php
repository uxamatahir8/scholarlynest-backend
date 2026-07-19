<?php

namespace App\Http\Requests\Concerns;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\Magazine;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidationValidator;

trait ValidatesArticleSubmission
{
    protected array $normalizedAuthors = [];
    protected array $normalizedReviewerPreferences = [
        'suggested' => [],
        'opposed' => [],
    ];

    protected function prepareArticleSubmissionForValidation(): void
    {
        $authors = $this->decodeArrayInput($this->input('authors', $this->input('co_authors', [])));
        $authors = array_values(array_filter($authors, fn ($author) => is_array($author)));
        $user = $this->user();
        $isSuperAdmin = $user && $user->hasRole('super_admin');

        if (count($authors) === 0 && $this->route('id')) {
            $existingArticle = Article::with(['user', 'articleAuthors'])->find($this->route('id'));
            if ($existingArticle) {
                $authors = $existingArticle->articleAuthors->map(fn ($author) => [
                    'name' => $author->co_author_name,
                    'email' => $author->co_author_email,
                    'affiliation' => $author->affiliation ?? $author->university_name,
                    'department' => $author->department,
                    'country' => $author->country,
                    'orcid' => $author->orcid,
                    'author_order' => $author->author_order,
                    'is_owner' => $author->is_owner,
                    'is_corresponding' => $author->is_corresponding,
                    'can_edit' => $author->can_edit,
                    'create_account' => (bool) $author->user_id,
                    'contribution_statement' => $author->contribution_statement,
                    'user_id' => $author->user_id,
                ])->all();

                if (count($authors) === 0 && $existingArticle->user) {
                    $authors[] = [
                        'name' => $existingArticle->user->name,
                        'email' => $existingArticle->user->email,
                        'affiliation' => $existingArticle->user->university_name,
                        'is_owner' => true,
                        'is_corresponding' => true,
                        'can_edit' => true,
                        'create_account' => false,
                        'user_id' => $existingArticle->user_id,
                    ];
                }

                if (count($authors) > 0 && $existingArticle->user && !collect($authors)->contains(fn ($author) => $this->truthy($author['is_owner'] ?? false))) {
                    array_unshift($authors, [
                        'name' => $existingArticle->user->name,
                        'email' => $existingArticle->user->email,
                        'affiliation' => $existingArticle->user->university_name,
                        'is_owner' => true,
                        'is_corresponding' => true,
                        'can_edit' => true,
                        'create_account' => false,
                        'user_id' => $existingArticle->user_id,
                    ]);
                }
            }
        }

        if (!$isSuperAdmin && $user) {
            $currentEmail = strtolower((string) $user->email);
            $hasCurrentUser = collect($authors)->contains(function ($author) use ($currentEmail) {
                return strtolower(trim((string) ($author['email'] ?? ''))) === $currentEmail;
            });

            if (!$hasCurrentUser) {
                array_unshift($authors, [
                    'name' => $user->name,
                    'email' => $user->email,
                    'affiliation' => $user->university_name,
                    'university_name' => $user->university_name,
                    'is_owner' => true,
                    'is_corresponding' => true,
                    'can_edit' => true,
                    'create_account' => false,
                ]);
            }
        }

        if (!$isSuperAdmin && count($authors) === 0 && $user) {
            $authors[] = [
                'name' => $user->name,
                'email' => $user->email,
                'affiliation' => $user->university_name,
                'university_name' => $user->university_name,
                'is_owner' => true,
                'is_corresponding' => true,
                'can_edit' => true,
                'create_account' => false,
            ];
        }

        $this->normalizedAuthors = collect($authors)
            ->map(function (array $author, int $index) use ($user, $isSuperAdmin) {
                $email = strtolower(trim((string) ($author['email'] ?? '')));
                $isCurrentUser = $user && $email !== '' && $email === strtolower((string) $user->email);

                return [
                    'name' => trim((string) ($author['name'] ?? $author['co_author_name'] ?? '')),
                    'email' => $email,
                    'affiliation' => trim((string) ($author['affiliation'] ?? $author['university_name'] ?? '')),
                    'department' => trim((string) ($author['department'] ?? '')),
                    'country' => trim((string) ($author['country'] ?? '')),
                    'orcid' => trim((string) ($author['orcid'] ?? '')),
                    'author_order' => (int) ($author['author_order'] ?? $index + 1),
                    'is_owner' => $this->truthy($author['is_owner'] ?? (!$isSuperAdmin && $isCurrentUser)),
                    'is_corresponding' => $this->truthy($author['is_corresponding'] ?? (!$isSuperAdmin && $isCurrentUser)),
                    'can_edit' => $this->truthy($author['can_edit'] ?? (!$isSuperAdmin && $isCurrentUser)),
                    'create_account' => $this->truthy($author['create_account'] ?? false),
                    'contribution_statement' => trim((string) ($author['contribution_statement'] ?? '')),
                    'user_id' => $author['user_id'] ?? null,
                ];
            })
            ->sortBy('author_order')
            ->values()
            ->map(function (array $author, int $index) {
                $author['author_order'] = $index + 1;
                $author['university_name'] = $author['affiliation'];

                return $author;
            })
            ->all();

        $this->normalizeReviewerPreferenceRows();

        $this->merge([
            'authors' => $this->normalizedAuthors,
            'co_authors' => $this->normalizedAuthors,
            'suggested_reviewers' => $this->normalizedReviewerPreferences['suggested'],
            'opposed_reviewers' => $this->normalizedReviewerPreferences['opposed'],
            'keywords' => $this->decodeArrayInput($this->input('keywords', [])),
            'additional_manuscript_files' => $this->decodeArrayInput($this->input('additional_manuscript_files', [])),
            'status' => ArticleStatus::normalize($this->input('status')),
            'publication_type' => $this->input('publication_type', Magazine::TYPE_MAGAZINE),
        ]);
    }

    protected function articleRules(bool $isUpdate = false): array
    {
        $isDraft = ArticleStatus::normalize($this->input('status')) === ArticleStatus::DRAFT;
        $required = $isUpdate ? 'sometimes|required' : 'required';
        $draftRequired = $isDraft ? 'nullable' : $required;
        $authorRule = $isDraft ? 'nullable|array' : 'required|array|min:1';
        $authorFieldRule = $isDraft ? 'nullable' : 'required';

        return [
            'publication_type' => 'required|in:' . Magazine::TYPE_MAGAZINE . ',' . Magazine::TYPE_JOURNAL,
            'magazine_id' => "{$draftRequired}|integer|exists:magazines,id",
            'title' => "{$draftRequired}|string|max:255",
            'abstract' => "{$draftRequired}|string",
            'pdf_file' => 'nullable|file|mimes:pdf,doc,docx|max:25600',
            'pdf_upload_id' => 'nullable|string|exists:media_upload_sessions,id',
            'revision_response_upload_id' => 'nullable|string|exists:media_upload_sessions,id',
            'additional_file_ids' => 'nullable|array',
            'additional_file_ids.*' => 'integer|exists:article_files,id',
            'additional_manuscript_files' => 'nullable|array',
            'additional_manuscript_files.*.file_title' => 'required|string|max:255',
            'additional_manuscript_files.*.upload_id' => 'nullable|string|exists:media_upload_sessions,id|required_without:additional_manuscript_files.*.article_file_id',
            'additional_manuscript_files.*.article_file_id' => 'nullable|integer|exists:article_files,id|required_without:additional_manuscript_files.*.upload_id',
            'revision_response' => 'prohibited',
            'tags' => 'nullable',
            'keywords' => 'nullable|array',
            'keywords.*' => 'nullable|string|max:120',
            'article_category' => 'nullable|string|max:120',
            'article_type' => 'nullable|string|max:120',
            'subject_area' => 'nullable|string|max:120',
            'language' => 'nullable|string|max:32',
            'ethical_approval_statement' => 'nullable|string|max:5000',
            'conflict_of_interest_statement' => 'nullable|string|max:5000',
            'funding_statement' => 'nullable|string|max:5000',
            'data_availability_statement' => 'nullable|string|max:5000',
            'author_contribution_statement' => 'nullable|string|max:5000',
            'authors' => $authorRule,
            'authors.*.name' => "{$authorFieldRule}|string|max:255",
            'authors.*.email' => "{$authorFieldRule}|email|max:255",
            'authors.*.affiliation' => 'nullable|string|max:255',
            'authors.*.department' => 'nullable|string|max:255',
            'authors.*.country' => 'nullable|string|max:120',
            'authors.*.orcid' => ['nullable', 'string', 'max:32', 'regex:/^$|^\\d{4}-\\d{4}-\\d{4}-[\\dX]{4}$/i'],
            'authors.*.author_order' => 'required|integer|min:1',
            'authors.*.is_owner' => 'boolean',
            'authors.*.is_corresponding' => 'boolean',
            'authors.*.can_edit' => 'boolean',
            'authors.*.create_account' => 'boolean',
            'authors.*.contribution_statement' => 'nullable|string|max:5000',
            'suggested_reviewers' => 'nullable|array',
            'suggested_reviewers.*.name' => 'required_with:suggested_reviewers|string|max:255',
            'suggested_reviewers.*.email' => 'required_with:suggested_reviewers|email|max:255',
            'suggested_reviewers.*.affiliation' => 'nullable|string|max:255',
            'suggested_reviewers.*.designation' => 'nullable|string|max:255',
            'opposed_reviewers' => 'nullable|array',
            'opposed_reviewers.*.name' => 'required_with:opposed_reviewers|string|max:255',
            'opposed_reviewers.*.email' => 'required_with:opposed_reviewers|email|max:255',
            'opposed_reviewers.*.affiliation' => 'nullable|string|max:255',
            'opposed_reviewers.*.designation' => 'nullable|string|max:255',
            'seo_title' => 'nullable|string|max:255',
            'seo_description' => 'nullable|string|max:500',
            'seo_keywords' => 'nullable|string|max:500',
            'change_summary' => 'nullable|string|max:10000',
            'status' => 'nullable|in:' . ArticleStatus::DRAFT . ',' . ArticleStatus::SUBMITTED,
            // The user id and timestamp are deliberately server-owned; a browser may only affirm acceptance.
            'terms_accepted' => $isDraft ? 'nullable|boolean' : 'required|accepted',
        ];
    }

    protected function articleAfterValidation(ValidationValidator $validator, bool $enforceSubmittingOwner = true): void
    {
        $authors = $this->normalizedAuthors;
        $uploadIds = collect($this->input('additional_manuscript_files', []))
            ->pluck('upload_id')
            ->filter();
        if ($uploadIds->count() !== $uploadIds->unique()->count()) {
            $validator->errors()->add('additional_manuscript_files', 'The same upload session cannot be attached more than once.');
        }
        $articleFileIds = collect($this->input('additional_manuscript_files', []))
            ->pluck('article_file_id')
            ->filter()
            ->map(fn ($id) => (int) $id);
        if ($articleFileIds->count() !== $articleFileIds->unique()->count()) {
            $validator->errors()->add('additional_manuscript_files', 'The same article file cannot be attached more than once.');
        }
        $isDraft = ArticleStatus::normalize($this->input('status')) === ArticleStatus::DRAFT;
        if ($isDraft && count($authors) === 0) {
            $this->validatePublicationDestination($validator);
            return;
        }

        $this->validatePublicationDestination($validator);

        $emails = array_filter(array_map(fn ($author) => $author['email'] ?? null, $authors));

        if (count($emails) !== count(array_unique($emails))) {
            $validator->errors()->add('authors', 'Author emails must be unique.');
        }

        $ownerCount = collect($authors)->where('is_owner', true)->count();
        if ($ownerCount !== 1) {
            $validator->errors()->add('authors', 'Exactly one article owner is required.');
        }

        $correspondingCount = collect($authors)->where('is_corresponding', true)->count();
        if ($correspondingCount < 1) {
            $validator->errors()->add('authors', 'At least one corresponding author is required.');
        }

        if (!$isDraft && $enforceSubmittingOwner && !$this->user()?->hasRole('super_admin')) {
            $currentEmail = strtolower((string) $this->user()?->email);
            $owner = collect($authors)->firstWhere('is_owner', true);
            if (!$owner || ($owner['email'] ?? null) !== $currentEmail) {
                $validator->errors()->add('authors', 'The submitting author must be the article owner.');
            }
        }

        if ($enforceSubmittingOwner && $this->user()?->hasRole('super_admin')) {
            $currentEmail = strtolower((string) $this->user()?->email);
            $owner = collect($authors)->firstWhere('is_owner', true);
            if ($owner && ($owner['email'] ?? null) === $currentEmail) {
                $validator->errors()->add('authors', 'Super admins must assign manuscript ownership to an article author.');
            }
        }

        $suggestedEmails = collect($this->normalizedReviewerPreferences['suggested'])->pluck('email')->filter()->values();
        $opposedEmails = collect($this->normalizedReviewerPreferences['opposed'])->pluck('email')->filter()->values();
        if ($suggestedEmails->count() !== $suggestedEmails->unique()->count()) {
            $validator->errors()->add('suggested_reviewers', 'Suggested reviewer emails must be unique.');
        }
        if ($opposedEmails->count() !== $opposedEmails->unique()->count()) {
            $validator->errors()->add('opposed_reviewers', 'Opposing reviewer emails must be unique.');
        }
        if ($suggestedEmails->intersect($opposedEmails)->isNotEmpty()) {
            $validator->errors()->add('suggested_reviewers', 'The same reviewer cannot appear in suggested and opposing reviewer lists.');
        }

        $authorEmails = collect($authors)->pluck('email')->filter()->map(fn ($email) => strtolower($email))->values();
        $selfEmail = strtolower((string) $this->user()?->email);
        foreach ($suggestedEmails as $email) {
            if ($email === $selfEmail || $authorEmails->contains($email)) {
                $validator->errors()->add('suggested_reviewers', 'Authors and co-authors cannot be suggested as reviewers.');
                break;
            }
        }
    }

    private function validatePublicationDestination(ValidationValidator $validator): void
    {
        if (!$this->filled('magazine_id') || !$this->filled('publication_type')) {
            return;
        }

        $matches = Magazine::whereKey($this->input('magazine_id'))
            ->where('publication_type', $this->input('publication_type'))
            ->exists();

        if (!$matches) {
            $validator->errors()->add('magazine_id', 'The selected destination does not match the publication type.');
        }
    }

    public function academicAuthors(): array
    {
        return $this->normalizedAuthors;
    }

    public function articlePayload(): array
    {
        return Arr::only($this->validated(), [
            'magazine_id',
            'title',
            'abstract',
            'keywords',
            'article_category',
            'article_type',
            'subject_area',
            'language',
            'ethical_approval_statement',
            'conflict_of_interest_statement',
            'funding_statement',
            'data_availability_statement',
            'author_contribution_statement',
        ]);
    }

    public function reviewerPreferencesPayload(): array
    {
        return $this->normalizedReviewerPreferences;
    }

    protected function decodeArrayInput(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }

    private function normalizeReviewerPreferenceRows(): void
    {
        $this->normalizedReviewerPreferences = [
            'suggested' => $this->normalizeReviewerRows($this->decodeArrayInput($this->input('suggested_reviewers', [])), 'suggested'),
            'opposed' => $this->normalizeReviewerRows($this->decodeArrayInput($this->input('opposed_reviewers', [])), 'opposed'),
        ];
    }

    private function normalizeReviewerRows(array $rows, string $type): array
    {
        return collect($rows)
            ->filter(fn ($row) => is_array($row) && (trim((string) ($row['name'] ?? '')) !== '' || trim((string) ($row['email'] ?? '')) !== ''))
            ->map(fn ($row) => [
                'type' => $type,
                'name' => trim((string) ($row['name'] ?? '')),
                'email' => strtolower(trim((string) ($row['email'] ?? ''))),
                'affiliation' => trim((string) ($row['affiliation'] ?? '')),
                'designation' => trim((string) ($row['designation'] ?? '')),
            ])
            ->values()
            ->all();
    }

    protected function truthy(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
