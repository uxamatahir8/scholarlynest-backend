# Article media delivery deployment notes

## Code deployment

1. Back up `advertisements`, `advertisement_targets`, and `advertisement_events` when present.
2. Deploy the backend and run `php artisan migrate --force`.
3. Restart queue workers so clean-scan jobs use the deployed code.
4. Deploy the frontend and revalidate affected article routes.
5. Verify left/right article ads, public gallery images, and authorized/unauthorized file downloads.

Private clean files are authorized by Laravel and then delivered through short-lived S3 redirects. The normal render/download path deliberately does not issue an S3 `HEAD`; upload completion remains responsible for object and size verification. Do not enable public bucket access for manuscript or supplementary objects.

## Infrastructure not changed by this patch

- The configured S3 region must continue to match the bucket region. Development verification on 2026-07-19 returned `ap-south-1` for both.
- If a CDN is introduced, configure its domain through environment-backed filesystem/media settings; do not hardcode it. Keep private objects behind signed CDN URLs or cookies.
- Bucket/CDN CORS should allow `GET` and `HEAD` from the deployed frontend origins, expose `Content-Length`, `Content-Range`, `Content-Type`, `Content-Disposition`, `ETag`, and `Accept-Ranges`, and avoid unnecessary custom request headers.
- Preserve byte-range requests at S3/CDN for large PDF and supplementary downloads.
- Apply `public, max-age=31536000, immutable` only to genuinely public, content-addressed/versioned image objects. Private files must retain private caching policy.
- Uploaded objects must retain accurate `Content-Type`; signed downloads set `Content-Disposition` and the user-facing filename.
- No thumbnail/derivative worker exists in this repository today. Gallery cards now reserve a stable aspect ratio and lazy-load, but a future clean-scan-triggered derivative pipeline should create `thumbnail`, `small`, and `medium` variants rather than doing work during public requests.
