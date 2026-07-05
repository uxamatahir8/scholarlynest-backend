<?php

namespace App\Http\Controllers;

use App\Jobs\ScanPendingMedia;
use App\Models\Article;
use App\Models\ArticleAsset;
use App\Models\ArticleFile;
use App\Models\Media;
use App\Models\MediaUploadSession;
use App\Services\Media\DirectS3UploadService;
use App\Services\Media\MediaUploadPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MediaUploadController extends Controller
{
    public function __construct(
        private readonly MediaUploadPolicy $policy,
        private readonly DirectS3UploadService $s3,
    ) {
    }

    public function initiate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'purpose' => ['required', 'string', Rule::in(array_keys(config('media_uploads.purposes')))],
            'attachable_id' => ['nullable', 'integer', 'min:1'],
            'original_filename' => ['required', 'string', 'max:255'],
            'size_bytes' => ['required', 'integer', 'min:1'],
            'declared_mime_type' => ['nullable', 'string', 'max:180'],
            'checksum_sha256' => ['nullable', 'string', 'size:64'],
            'file_fingerprint' => ['nullable', 'string', 'max:180'],
            'assignment_type' => ['nullable', 'string', 'max:80'],
            'assignment_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $purposeConfig = $this->policy->configForPurpose($validated['purpose']);
        $attachable = $this->policy->resolveAttachable($validated['purpose'], $validated['attachable_id'] ?? null);
        $this->policy->authorizeInitiate($request->user(), $validated['purpose'], $attachable, $validated);

        if ($validated['size_bytes'] > (int) $purposeConfig['max_size_bytes']) {
            return response()->json(['message' => 'The selected file exceeds the configured size limit.'], 422);
        }

        $activeCount = MediaUploadSession::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('status', [
                MediaUploadSession::STATUS_INITIATED,
                MediaUploadSession::STATUS_UPLOADING,
                MediaUploadSession::STATUS_UPLOADED_PENDING_SCAN,
                MediaUploadSession::STATUS_SCANNING,
            ])
            ->where('expires_at', '>', now())
            ->count();

        if ($activeCount >= config('media_uploads.max_active_sessions_per_user')) {
            return response()->json(['message' => 'Too many active uploads. Please finish or abort an existing upload first.'], 429);
        }

        $safeName = $this->policy->sanitizeFilename($validated['original_filename']);
        $extension = Str::lower(pathinfo($safeName, PATHINFO_EXTENSION));
        if ($extension && !in_array($extension, $purposeConfig['extensions'] ?? [], true)) {
            return response()->json(['message' => 'This file extension is not allowed for the selected upload purpose.'], 422);
        }

        $mode = $validated['size_bytes'] >= config('media_uploads.multipart_threshold_bytes') ? 'multipart' : 'single';
        $session = new MediaUploadSession([
            'user_id' => $request->user()->id,
            'purpose' => $validated['purpose'],
            'attachable_type' => $attachable ? $attachable::class : null,
            'attachable_id' => $attachable?->getKey(),
            'original_filename' => $validated['original_filename'],
            'safe_display_filename' => $safeName,
            'expected_size_bytes' => $validated['size_bytes'],
            'declared_mime_type' => $validated['declared_mime_type'] ?? null,
            'expected_checksum_sha256' => isset($validated['checksum_sha256']) ? strtolower($validated['checksum_sha256']) : null,
            'disk' => config('media_uploads.disk'),
            's3_incoming_key' => $this->s3->incomingKey($validated['purpose'], $extension),
            'upload_mode' => $mode,
            'status' => MediaUploadSession::STATUS_INITIATED,
            'metadata' => [
                'file_fingerprint' => $validated['file_fingerprint'] ?? null,
                'assignment_type' => $validated['assignment_type'] ?? null,
                'assignment_id' => $validated['assignment_id'] ?? null,
            ],
            'expires_at' => now()->addMinutes(config('media_uploads.session_ttl_minutes')),
        ]);

        if ($mode === 'multipart') {
            $session->s3_upload_id = 'pending';
        }

        $session->save();

        if ($mode === 'multipart') {
            $session->s3_upload_id = $this->s3->createMultipartUpload($session);
            $session->status = MediaUploadSession::STATUS_UPLOADING;
            $session->save();

            $firstParts = range(1, min(5, $this->expectedPartCount($session)));

            return response()->json([
                'upload' => $this->uploadPayload($session),
                'part_size_bytes' => config('media_uploads.part_size_bytes'),
                'parts' => $this->s3->signParts($session, $firstParts),
            ], 201);
        }

        $session->status = MediaUploadSession::STATUS_UPLOADING;
        $session->save();

        return response()->json([
            'upload' => $this->uploadPayload($session),
            'put' => $this->s3->signPut($session),
        ], 201);
    }

    public function signParts(Request $request, MediaUploadSession $upload): JsonResponse
    {
        $this->authorizeSession($request, $upload);
        $validated = $request->validate([
            'part_numbers' => ['required', 'array', 'min:1', 'max:20'],
            'part_numbers.*' => ['required', 'integer', 'min:1'],
        ]);

        if ($upload->upload_mode !== 'multipart' || !$upload->s3_upload_id || $upload->isTerminal()) {
            return response()->json(['message' => 'This upload session cannot sign parts.'], 422);
        }

        $maxPart = $this->expectedPartCount($upload);
        $partNumbers = collect($validated['part_numbers'])
            ->map(fn ($part) => (int) $part)
            ->unique()
            ->filter(fn ($part) => $part >= 1 && $part <= $maxPart)
            ->values()
            ->all();

        if (!$partNumbers) {
            return response()->json(['message' => 'No valid part numbers were requested.'], 422);
        }

        return response()->json([
            'parts' => $this->s3->signParts($upload, $partNumbers),
            'expires_at' => now()->addMinutes(config('media_uploads.presign_ttl_minutes'))->toISOString(),
        ]);
    }

    public function resume(Request $request, MediaUploadSession $upload): JsonResponse
    {
        $this->authorizeSession($request, $upload);
        if ($upload->expires_at?->isPast() || $upload->isTerminal()) {
            return response()->json(['message' => 'This upload session cannot be resumed.'], 422);
        }

        return response()->json([
            'upload' => $this->uploadPayload($upload),
            'uploaded_parts' => $upload->upload_mode === 'multipart' ? $this->s3->listParts($upload) : [],
        ]);
    }

    public function complete(Request $request, MediaUploadSession $upload): JsonResponse
    {
        $this->authorizeSession($request, $upload);
        $validated = $request->validate([
            'parts' => ['nullable', 'array'],
            'parts.*.part_number' => ['required_with:parts', 'integer', 'min:1'],
            'parts.*.etag' => ['required_with:parts', 'string', 'max:255'],
            'parts.*.checksum_sha256' => ['nullable', 'string', 'max:255'],
        ]);

        if ($upload->expires_at?->isPast() || $upload->isTerminal()) {
            return response()->json(['message' => 'This upload session cannot be completed.'], 422);
        }

        if ($upload->upload_mode === 'multipart') {
            $parts = collect($validated['parts'] ?? [])->sortBy('part_number')->values()->all();
            if (count($parts) !== $this->expectedPartCount($upload)) {
                return response()->json(['message' => 'The uploaded part manifest is incomplete.'], 422);
            }
            $this->s3->completeMultipart($upload, $parts);
            $upload->uploaded_part_manifest = $parts;
        }

        $head = $this->s3->head($upload);
        if ((int) ($head['ContentLength'] ?? 0) !== (int) $upload->expected_size_bytes) {
            return response()->json(['message' => 'The uploaded object size does not match the initiated upload.'], 422);
        }

        DB::transaction(function () use ($upload) {
            $recordMetadata = $this->createPendingRecords($upload);
            $upload->forceFill([
                'status' => MediaUploadSession::STATUS_UPLOADED_PENDING_SCAN,
                'metadata' => array_merge($upload->metadata ?: [], $recordMetadata),
                'completed_at' => now(),
                'scan_requested_at' => now(),
                'failure_reason' => null,
            ])->save();
        });

        ScanPendingMedia::dispatch($upload->id);

        return response()->json([
            'message' => 'Upload received and awaiting security scan.',
            'upload' => $this->uploadPayload($upload->fresh()),
        ]);
    }

    public function abort(Request $request, MediaUploadSession $upload): JsonResponse
    {
        $this->authorizeSession($request, $upload);
        if ($upload->upload_mode === 'multipart' && !$upload->isTerminal()) {
            $this->s3->abortMultipart($upload);
        }

        $upload->forceFill([
            'status' => MediaUploadSession::STATUS_ABORTED,
            'failure_reason' => 'aborted_by_user',
        ])->save();

        return response()->json(['message' => 'Upload aborted.']);
    }

    public function status(Request $request, MediaUploadSession $upload): JsonResponse
    {
        $this->authorizeSession($request, $upload);

        return response()->json(['upload' => $this->uploadPayload($upload)]);
    }

    private function createPendingRecords(MediaUploadSession $upload): array
    {
        $config = $this->policy->configForPurpose($upload->purpose);
        if (($config['target'] ?? null) === 'article') {
            /** @var Article $article */
            $article = $upload->attachable;
            $file = app(ArticleFileController::class)->createPendingDirectUploadFile($article, $upload, $config);
            $metadata = ['article_file_id' => $file->id];

            if (!empty($config['create_article_asset'])) {
                $asset = ArticleAsset::create([
                    'article_id' => $article->id,
                    'disk' => $upload->disk,
                    'storage_key' => $upload->s3_incoming_key,
                    'file_path' => $upload->s3_incoming_key,
                    'original_filename' => $upload->safe_display_filename,
                    'safe_original_filename' => $upload->safe_display_filename,
                    'file_size' => $upload->expected_size_bytes,
                    'mime_type' => $upload->declared_mime_type ?: 'application/octet-stream',
                    'scan_status' => MediaUploadSession::STATUS_UPLOADED_PENDING_SCAN,
                ]);
                $file->update(['source_asset_id' => $asset->id]);
                $metadata['article_asset_id'] = $asset->id;
            }

            return $metadata;
        }

        if (($config['target'] ?? null) === 'media') {
            $media = Media::create([
                'filename' => $upload->safe_display_filename,
                'safe_original_name' => $upload->safe_display_filename,
                'url' => null,
                'disk' => $upload->disk,
                'storage_key' => $upload->s3_incoming_key,
                'mime_type' => $upload->declared_mime_type ?: 'application/octet-stream',
                'size' => $upload->expected_size_bytes,
                'scan_status' => MediaUploadSession::STATUS_UPLOADED_PENDING_SCAN,
            ]);

            return ['media_id' => $media->id];
        }

        return [];
    }

    private function uploadPayload(MediaUploadSession $upload): array
    {
        $metadata = $upload->metadata ?: [];

        return [
            'id' => $upload->id,
            'purpose' => $upload->purpose,
            'upload_mode' => $upload->upload_mode,
            'status' => $upload->status,
            'safe_display_filename' => $upload->safe_display_filename,
            'expected_size_bytes' => $upload->expected_size_bytes,
            'part_size_bytes' => config('media_uploads.part_size_bytes'),
            'expires_at' => $upload->expires_at?->toISOString(),
            'completed_at' => $upload->completed_at?->toISOString(),
            'scan_requested_at' => $upload->scan_requested_at?->toISOString(),
            'failure_reason' => $upload->failure_reason,
            'record' => [
                'article_file_id' => $metadata['article_file_id'] ?? null,
                'article_asset_id' => $metadata['article_asset_id'] ?? null,
                'media_id' => $metadata['media_id'] ?? null,
            ],
        ];
    }

    private function authorizeSession(Request $request, MediaUploadSession $upload): void
    {
        if ($upload->user_id !== $request->user()->id) {
            abort(403, 'This action is unauthorized.');
        }
    }

    private function expectedPartCount(MediaUploadSession $upload): int
    {
        return (int) ceil($upload->expected_size_bytes / config('media_uploads.part_size_bytes'));
    }
}
