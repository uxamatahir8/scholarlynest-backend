<?php

namespace App\Services;

use App\Models\Article;
use App\Models\ArticleAuditLog;
use App\Models\User;
use App\Models\WorkflowIdempotencyKey;
use App\Services\Notifications\NotificationEventRecorder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class ArticleLifecycleService
{
    public function command(
        Article $article,
        User $actor,
        string $command,
        string $idempotencyKey,
        array $requestPayload,
        string $auditEvent,
        string $notificationEvent,
        callable $mutation,
    ): array {
        if (trim($idempotencyKey) === '') {
            throw new HttpResponseException(response()->json(['message' => 'An Idempotency-Key header is required.'], 422));
        }
        $hash = hash('sha256', json_encode($requestPayload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($article, $actor, $command, $idempotencyKey, $hash, $requestPayload, $auditEvent, $notificationEvent, $mutation) {
            $locked = Article::query()->whereKey($article->id)->lockForUpdate()->firstOrFail();
            $existing = WorkflowIdempotencyKey::query()
                ->where('actor_id', $actor->id)->where('command', $command)
                ->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw new HttpResponseException(response()->json(['message' => 'The idempotency key was already used with a different request.'], 409));
                }

                return ['replayed' => true, 'status' => $existing->response_status, 'data' => $existing->response_payload];
            }

            $from = app(LifecycleStatusProjector::class)->canonical($locked);
            $allowedSources = config("article_lifecycle.transitions.{$command}.from", []);
            if ($allowedSources && ! in_array($from, $allowedSources, true)) {
                $this->conflict("The {$command} command is not valid while the article is in [{$from}] state.");
            }

            $key = WorkflowIdempotencyKey::create([
                'article_id' => $locked->id, 'actor_id' => $actor->id, 'command' => $command,
                'idempotency_key' => $idempotencyKey, 'request_hash' => $hash,
            ]);
            $result = $mutation($locked);
            $locked->increment('lifecycle_sequence');
            $locked->unsetRelations();
            $to = app(LifecycleStatusProjector::class)->synchronize($locked->fresh());
            $safePayload = app(PrivacyPayloadSanitizer::class)->sanitize(array_merge($requestPayload, ['command' => $command]));
            $audit = ArticleAuditLog::create([
                'article_id' => $locked->id, 'actor_id' => $actor->id, 'event' => $auditEvent,
                'from_status' => $from, 'to_status' => $to, 'payload' => $safePayload,
            ]);
            app(NotificationEventRecorder::class)->record(
                $notificationEvent, $locked->fresh(), $actor,
                array_merge($safePayload, ['from_status' => $from, 'to_status' => $to]),
                subjectType: 'article', subjectId: $locked->id,
                deduplicationKey: "lifecycle:{$command}:{$actor->id}:{$idempotencyKey}",
                articleAuditLogId: $audit->id,
            );
            $response = ['result' => $result, 'status' => app(LifecycleStatusProjector::class)->projection($locked->fresh(), $actor)];
            $key->update(['response_status' => 200, 'response_payload' => $response]);

            return ['replayed' => false, 'status' => 200, 'data' => $response];
        }, 3);
    }

    public function conflict(string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => $message], 409));
    }
}
