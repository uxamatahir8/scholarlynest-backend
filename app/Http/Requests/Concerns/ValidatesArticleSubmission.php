<?php

namespace App\Http\Requests\Concerns;

use App\Constants\ArticleStatus;
use App\Models\Article;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidationValidator;

trait ValidatesArticleSubmission
{
    protected array $normalizedAuthors = [];

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

        $this->merge([
            'authors' => $this->normalizedAuthors,
            'co_authors' => $this->normalizedAuthors,
            'keywords' => $this->decodeArrayInput($this->input('keywords', [])),
            'status' => ArticleStatus::normalize($this->input('status')),
        ]);
    }

    protected function articleRules(bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes|required' : 'required';

        return [
            'magazine_id' => "{$required}|exists:magazines,id",
            'title' => "{$required}|string|max:255",
            'abstract' => "{$required}|string",
            'full_text' => "{$required}|string",
            'pdf_file' => 'nullable|file|mimes:pdf|max:10240',
            'pdf_upload_id' => 'nullable|string|exists:media_upload_sessions,id',
            'featured_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:5120',
            'featured_image_upload_id' => 'nullable|string|exists:media_upload_sessions,id',
            'delete_featured_image' => 'nullable|string',
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
            'authors' => 'required|array|min:1',
            'authors.*.name' => 'required|string|max:255',
            'authors.*.email' => 'required|email|max:255',
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
            'seo_title' => 'nullable|string|max:255',
            'seo_description' => 'nullable|string|max:500',
            'seo_keywords' => 'nullable|string|max:500',
            'revision_response' => 'nullable|string|max:10000',
            'change_summary' => 'nullable|string|max:10000',
            'status' => 'nullable|in:' . ArticleStatus::DRAFT . ',' . ArticleStatus::SUBMITTED,
        ];
    }

    protected function articleAfterValidation(ValidationValidator $validator, bool $enforceSubmittingOwner = true): void
    {
        $authors = $this->normalizedAuthors;
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

        if ($enforceSubmittingOwner && !$this->user()?->hasRole('super_admin')) {
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
            'full_text',
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

    protected function decodeArrayInput(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }

    protected function truthy(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
