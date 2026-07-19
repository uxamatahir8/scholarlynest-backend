<?php

namespace App\Services;

use App\Models\Article;
use App\Models\Magazine;
use App\Models\SlugRedirect;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SlugService
{
    private const MAX_ATTEMPTS = 100;

    public function base(string $value, string $fallback = 'item'): string
    {
        return Str::slug($value) ?: $fallback;
    }

    public function createPublication(array $attributes): Magazine
    {
        return $this->createWithRetry(
            fn (int $attempt) => Magazine::create(array_merge($attributes, [
                'slug' => $this->candidate($this->base($attributes['title'], 'publication'), $attempt),
            ])),
            'A unique publication URL could not be allocated. Please try again.'
        );
    }

    public function createArticle(array $attributes): Article
    {
        $hasTitle = trim((string) ($attributes['title'] ?? '')) !== '';
        $base = $hasTitle ? $this->base((string) $attributes['title'], 'draft') : 'draft-pending';

        return $this->createWithRetry(
            function (int $attempt) use ($attributes, $base, $hasTitle) {
                $article = Article::create(array_merge($attributes, ['slug' => $this->candidate($base, $attempt)]));
                if (!$hasTitle) $article->update(['slug' => 'draft-'.$article->id]);
                return $article;
            },
            'A unique article URL could not be allocated. Please try again.'
        );
    }

    public function publicationSlug(string $title, ?int $ignoreId = null): string
    {
        return $this->available($this->base($title, 'publication'), fn (string $slug) => Magazine::query()
            ->where('slug', $slug)->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))->exists());
    }

    public function articleSlug(int $magazineId, string $title, ?int $ignoreId = null): string
    {
        return $this->available($this->base($title, 'draft'), fn (string $slug) => Article::query()
            ->where('magazine_id', $magazineId)->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))->exists());
    }

    public function candidate(string $base, int $attempt): string
    {
        return $attempt === 1 ? $base : $base.'-'.$attempt;
    }

    public function recordRedirect(string $type, int $entityId, string $oldSlug, string $newSlug, ?int $parentId = null, bool $force = false): void
    {
        if ($oldSlug === $newSlug && !$force) return;
        SlugRedirect::updateOrCreate([
            'scope_key' => $type.':'.((int) ($parentId ?? 0)).':'.$oldSlug,
        ], [
            'entity_type' => $type,
            'entity_id' => $entityId,
            'old_slug' => $oldSlug,
            'new_slug' => $newSlug,
            'parent_type' => $parentId ? 'magazine' : null,
            'parent_id' => $parentId,
        ]);
    }

    private function available(string $base, callable $exists): string
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $candidate = $this->candidate($base, $attempt);
            if (!$exists($candidate)) return $candidate;
        }
        throw ValidationException::withMessages(['slug' => ['A unique URL could not be allocated. Please choose a different title.']]);
    }

    private function createWithRetry(callable $create, string $failureMessage)
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(fn () => $create($attempt));
            } catch (QueryException $exception) {
                if (!$this->isUniqueViolation($exception)) throw $exception;
            }
        }
        throw ValidationException::withMessages(['slug' => [$failureMessage]]);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return str_starts_with((string) $exception->getCode(), '23')
            || in_array((int) ($exception->errorInfo[1] ?? 0), [1062, 1555, 2067], true);
    }
}
