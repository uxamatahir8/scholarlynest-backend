<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleFile extends Model
{
    public const MANUSCRIPT = 'manuscript';
    public const SUPPLEMENTARY = 'supplementary';
    public const PLAGIARISM_REPORT = 'plagiarism_report';
    public const ANNOTATED_MANUSCRIPT = 'annotated_manuscript';
    public const REVIEWED_MANUSCRIPT = 'reviewed_manuscript';
    public const REVISION_RESPONSE = 'revision_response';
    public const ADDITIONAL_MANUSCRIPT_FILE = 'additional_manuscript_file';
    public const COPY_EDITED_FILE = 'copy_edited_file';
    public const PROOF_FILE = 'proof_file';
    public const PUBLICATION_PDF = 'publication_pdf';

    public const TYPES = [
        self::MANUSCRIPT,
        self::SUPPLEMENTARY,
        self::PLAGIARISM_REPORT,
        self::ANNOTATED_MANUSCRIPT,
        self::REVIEWED_MANUSCRIPT,
        self::REVISION_RESPONSE,
        self::ADDITIONAL_MANUSCRIPT_FILE,
        self::COPY_EDITED_FILE,
        self::PROOF_FILE,
        self::PUBLICATION_PDF,
    ];

    public function isPrimaryManuscript(): bool
    {
        return $this->file_type === self::MANUSCRIPT && $this->assignment_type === null;
    }

    protected $fillable = [
        'article_id',
        'article_version_id',
        'media_upload_session_id',
        'source_asset_id',
        'uploaded_by',
        'assignment_type',
        'assignment_id',
        'file_type',
        'file_title',
        'visibility',
        'disk',
        'file_path',
        'storage_key',
        'original_name',
        'safe_original_name',
        'mime_type',
        'size',
        'checksum_sha256',
        'scan_status',
        'scan_engine',
        'scanned_at',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'scanned_at' => 'datetime',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ArticleVersion::class, 'article_version_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
