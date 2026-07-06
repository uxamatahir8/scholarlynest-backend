# S3 Direct Media Pipeline Deployment

Bucket: `scholarynest-ap-south-1`
Region: `ap-south-1`
Disk: `s3`

Required Laravel environment variables:

```env
MEDIA_S3_DISK=s3
MEDIA_UPLOAD_MULTIPART_THRESHOLD_BYTES=16777216
MEDIA_UPLOAD_PART_SIZE_BYTES=8388608
MEDIA_UPLOAD_SESSION_TTL_MINUTES=60
MEDIA_DOWNLOAD_URL_TTL_MINUTES=5
MEDIA_SCAN_FAIL_CLOSED=true
MEDIA_SCAN_DRIVER=clamav
MEDIA_SCAN_QUEUE=media-scan
MEDIA_MIGRATION_QUEUE=media-migration
AWS_DEFAULT_REGION=ap-south-1
AWS_BUCKET=scholarynest-ap-south-1
```

Do not place AWS credentials in the frontend or public documentation.

S3 CORS, replace the origins with the deployed frontend domains only:

```json
[
  {
    "AllowedHeaders": [
      "content-type",
      "content-disposition",
      "x-amz-checksum-sha256",
      "x-amz-content-sha256",
      "x-amz-date",
      "x-amz-security-token"
    ],
    "AllowedMethods": ["PUT", "HEAD"],
    "AllowedOrigins": ["https://YOUR-PRODUCTION-FRONTEND-DOMAIN"],
    "ExposeHeaders": ["ETag", "x-amz-checksum-sha256", "x-amz-version-id"],
    "MaxAgeSeconds": 300
  }
]
```

IAM review: keep bucket-scoped permissions only. Required S3 actions are object put/head/get/copy/delete for controlled prefixes, multipart create/list/upload/complete/abort, and temporary GET generation. Do not attach broad AWS managed admin policies.

Lifecycle recommendations:

- Abort incomplete multipart uploads after 7 days.
- Remove noncurrent versions after 180 days.
- Remove quarantine objects after 7 days if quarantine is retained.
- Remove expired pending upload objects under `incoming/` after the upload-session retention window.

Operational requirements:

- Install and enable `clamd`/`clamdscan`.
- Keep virus definitions updated with the OS package updater or `freshclam`.
- Run Laravel queue workers for `media-scan`, `media-finalize`, `media-migration`, and `media-cleanup`.
- Production queue driver must not be `sync`.
- Deploy workers and verify scanner health before enabling frontend upload controls.

Migration:

```bash
php artisan media:migrate-to-s3 --dry-run --limit=100 --report=storage/app/media-migration-dry-run.json
php artisan media:migrate-to-s3 --execute --limit=25 --report=storage/app/media-migration-pilot.json
```

Local originals are retained. Cleanup is separate and requires confirmation:

```bash
php artisan media:purge-legacy-local --verified-before=YYYY-MM-DD
```

Rollback: disable frontend direct-upload controls, leave queue workers running to finish already pending scans, keep local originals, and switch affected UI surfaces back to legacy records where needed. Do not make the S3 bucket public.
