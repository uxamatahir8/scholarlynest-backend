<?php

use App\Http\Controllers\Admin\AdvertisementController;
use App\Http\Controllers\Admin\ArticleCategoryController;
use App\Http\Controllers\Admin\ArticleTypeController;
use App\Http\Controllers\Admin\ArticleWorkflowController;
use App\Http\Controllers\Admin\DeskObserverController;
use App\Http\Controllers\Admin\EditorSubEditorController;
use App\Http\Controllers\Admin\FooterCategoryController;
use App\Http\Controllers\Admin\FooterPageController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\LanguageController;
use App\Http\Controllers\Admin\RbacController;
use App\Http\Controllers\Admin\SearchController;
use App\Http\Controllers\Admin\SharedPublicPageController;
use App\Http\Controllers\Admin\SubjectAreaController;
use App\Http\Controllers\AdvertisementResolutionController;
use App\Http\Controllers\ArticleAssetController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\ArticleFileController;
use App\Http\Controllers\ArticleTransferController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CmsPageController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\FaqController;
use App\Http\Controllers\FooterController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\MagazineController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\MediaObjectController;
use App\Http\Controllers\MediaUploadController;
use App\Http\Controllers\MfaController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\TagController;
use App\Models\Magazine;
use App\Models\NewsletterCampaign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ==========================================
// 1. PUBLIC ENDPOINTS (Throttled & Read-Only)
// ==========================================

// Public Dynamic CMS Pages Fetching
Route::get('/advertisements/resolve', AdvertisementResolutionController::class)->middleware('throttle:60,1');
Route::get('/cms/{slug}', [CmsPageController::class, 'show']);
Route::get('/faqs', [FaqController::class, 'index']);
Route::get('/public/faqs', [FaqController::class, 'publicIndex']);

// Public Footer & Dynamic Custom Pages
Route::get('/public/footer', [FooterController::class, 'index']);
Route::get('/public/footer/pages/{slug}', [FooterController::class, 'showPage']);

// Unified Global Search
Route::get('/search/preview', [GlobalSearchController::class, 'preview']);
Route::get('/search/full', [GlobalSearchController::class, 'full']);

// Public Contact Page Settings & Submission
Route::get('/contact-settings', [ContactController::class, 'getSettings']);
Route::get('/contact-subjects', [ContactController::class, 'getSubjects']);
Route::post('/contact', [ContactController::class, 'submit']);

// Public Newsletter Subscription
Route::post('/newsletter/subscribe', [NewsletterController::class, 'subscribe']);
Route::get('/newsletter/unsubscribe/{token}', [NewsletterController::class, 'unsubscribe']);

// Public Magazines & Articles Read Routes
Route::get('/magazines', [MagazineController::class, 'index'])->defaults('publication_type', 'magazine');
Route::get('/magazines/latest', [MagazineController::class, 'latest'])->defaults('publication_type', 'magazine');
Route::get('/magazines/{slug}/about-and-overview', [MagazineController::class, 'aboutAndOverview'])->defaults('publication_type', 'magazine');
Route::get('/magazines/{slug}/table-of-contents', [MagazineController::class, 'tableOfContents'])->defaults('publication_type', 'magazine');
Route::get('/magazines/{slug}/latest-published-articles', [MagazineController::class, 'latestPublishedArticles'])->defaults('publication_type', 'magazine');
Route::get('/magazines/{slug}/pages/{pageSlug}', [MagazineController::class, 'publicPage'])->defaults('publication_type', 'magazine');
Route::get('/magazines/{slug}', [MagazineController::class, 'show'])->defaults('publication_type', 'magazine');
Route::get('/magazines/{slug}/articles', [MagazineController::class, 'articles'])->defaults('publication_type', 'magazine');
Route::prefix('journals')->group(function () {
    Route::get('/', [MagazineController::class, 'index'])->defaults('publication_type', 'journal');
    Route::get('/latest', [MagazineController::class, 'latest'])->defaults('publication_type', 'journal');
    Route::get('/{slug}/about-and-overview', [MagazineController::class, 'aboutAndOverview'])->defaults('publication_type', 'journal');
    Route::get('/{slug}/table-of-contents', [MagazineController::class, 'tableOfContents'])->defaults('publication_type', 'journal');
    Route::get('/{slug}/latest-published-articles', [MagazineController::class, 'latestPublishedArticles'])->defaults('publication_type', 'journal');
    Route::get('/{slug}/pages/{pageSlug}', [MagazineController::class, 'publicPage'])->defaults('publication_type', 'journal');
    Route::get('/{slug}', [MagazineController::class, 'show'])->defaults('publication_type', 'journal');
    Route::get('/{slug}/articles', [MagazineController::class, 'articles'])->defaults('publication_type', 'journal');
});
Route::get('/magazines/{publicationSlug}/articles/{articleSlug}', [ArticleController::class, 'showForPublication'])
    ->defaults('publication_type', 'magazine');
Route::get('/journals/{publicationSlug}/articles/{articleSlug}', [ArticleController::class, 'showForPublication'])
    ->defaults('publication_type', 'journal');
Route::get('/articles/latest', [ArticleController::class, 'latest']);
Route::get('/public/homepage-stats', [ArticleController::class, 'publicHomepageStats']);
Route::get('/articles/{slug}', [ArticleController::class, 'show']);
Route::post('/articles/{id}/click', [ArticleController::class, 'trackClick']);
Route::post('/articles/{id}/share-click', [ArticleController::class, 'trackShareClick']);
Route::get('/articles/{id}/download-pdf', [ArticleController::class, 'downloadPdf'])->middleware('throttle:60,1');
Route::get('/articles/assets/{asset_id}/download', [ArticleAssetController::class, 'download'])->middleware('throttle:media-download');
Route::get('/articles/publication-sections/{section_id}/image', [ArticleWorkflowController::class, 'publicationSectionImage'])->middleware('throttle:media-download');
Route::get('/articles/files/{file_id}/download', [ArticleFileController::class, 'download'])->middleware('throttle:media-download');
Route::post('/reviewer-invitations/{id}/accept', [ArticleWorkflowController::class, 'acceptReviewerInvitation'])->middleware('throttle:20,1');
Route::post('/reviewer-invitations/{id}/decline', [ArticleWorkflowController::class, 'declineReviewerInvitation'])->middleware('throttle:20,1');
Route::get('/reviewer-invitations/{id}', [ArticleWorkflowController::class, 'showReviewerInvitation'])->middleware('throttle:20,1');
Route::get('/media/objects/{token}', [MediaObjectController::class, 'show'])->middleware('throttle:media-download');

Route::get('/public/magazines', function (Request $request) {
    $query = Magazine::where('publication_type', Magazine::TYPE_MAGAZINE)->orderBy('created_at', 'desc');
    if ($request->has('per_page') || $request->has('limit')) {
        $limit = $request->integer('per_page') ?: $request->integer('limit');
        $query->limit($limit);
    }
    $magazines = $query->get();

    return response()->json(['data' => $magazines]);
});

Route::get('/public/journals', function (Request $request) {
    $query = Magazine::where('publication_type', Magazine::TYPE_JOURNAL)->orderBy('created_at', 'desc');
    if ($request->has('per_page') || $request->has('limit')) {
        $limit = $request->integer('per_page') ?: $request->integer('limit');
        $query->limit($limit);
    }
    $journals = $query->get();

    return response()->json(['data' => $journals]);
});

Route::get('/public/newsletters', function () {
    $campaigns = NewsletterCampaign::select(['id', 'subject', 'created_at'])
        ->orderBy('created_at', 'desc')
        ->take(3)
        ->get();

    return response()->json(['data' => $campaigns]);
});

// Authentication (Strictly throttled to 10 requests per minute per IP)
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/verify', [AuthController::class, 'verify']);
    Route::post('/verify/resend', [AuthController::class, 'resendVerificationCode']);
    Route::post('/2fa/verify', [MfaController::class, 'verifyLogin']);
    Route::post('/auth/mfa/verify', [MfaController::class, 'verifyLogin']);
    Route::post('/auth/mfa/email/resend', [MfaController::class, 'resendEmail']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/password/verify-reset-code', [AuthController::class, 'verifyPasswordResetCode']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    Route::post('/auth/google/signin', [AuthController::class, 'googleSignIn']);
    Route::post('/auth/google/signup', [AuthController::class, 'googleSignUp']);
});

// ==========================================
// 2. PROTECTED ENDPOINTS (Sanctum Protected)
// ==========================================
Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {

    // Session profile & Logout
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::put('/user/profile', [AuthController::class, 'updateProfile']);
    Route::post('/profile/email/request-current-code', [AuthController::class, 'requestCurrentEmailCode']);
    Route::post('/profile/email/verify-current-code', [AuthController::class, 'verifyCurrentEmailCode']);
    Route::post('/profile/email/request-new-code', [AuthController::class, 'requestNewEmailCode']);
    Route::post('/profile/email/verify-new-code', [AuthController::class, 'verifyNewEmailCode']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/2fa/enable', [AuthController::class, 'enable2Fa']);
    Route::post('/2fa/request-disable-code', [AuthController::class, 'request2FaDisableCode']);
    Route::post('/2fa/disable', [AuthController::class, 'disable2Fa']);
    Route::get('/me/security/mfa', [MfaController::class, 'status']);
    Route::post('/me/security/mfa/totp/setup', [MfaController::class, 'startTotpSetup'])->middleware('throttle:mfa-setup');
    Route::post('/me/security/mfa/totp/verify', [MfaController::class, 'verifyTotpSetup'])->middleware('throttle:mfa-verify');
    Route::post('/me/security/mfa/totp/disable', [MfaController::class, 'disableTotp'])->middleware('throttle:mfa-sensitive');
    Route::post('/me/security/mfa/default-method', [MfaController::class, 'setDefault']);
    Route::post('/me/security/mfa/recovery-codes/regenerate', [MfaController::class, 'regenerateRecoveryCodes'])->middleware('throttle:mfa-sensitive');
    Route::post('/password/request-code', [AuthController::class, 'requestPasswordChangeCode']);
    Route::post('/password/verify-code', [AuthController::class, 'verifyPasswordChangeCode']);
    Route::post('/password/change', [AuthController::class, 'changePassword']);
    Route::post('/password/reset-enforced', [AuthController::class, 'resetEnforcedPassword']);

    // Article submissions
    Route::post('/articles', [ArticleController::class, 'store'])->middleware('permission:articles.create');
    Route::get('/articles/{article}/transfer-target-magazines', [ArticleTransferController::class, 'targetMagazines'])->middleware('permission:articles.view-own');
    Route::post('/articles/{article}/transfer-requests', [ArticleTransferController::class, 'store'])->middleware('permission:articles.approve');
    Route::get('/articles/{article}/transfer-request', [ArticleTransferController::class, 'show'])->middleware('permission:articles.view-own');
    Route::post('/articles/{article}/transfer-requests/{transferRequest}/accept', [ArticleTransferController::class, 'accept'])->middleware('permission:articles.view-own');
    Route::post('/articles/{article}/transfer-requests/{transferRequest}/reject', [ArticleTransferController::class, 'reject'])->middleware('permission:articles.view-own');
    Route::get('/tags', [TagController::class, 'index']);

    // Article classifications (lists for dropdown selects in form)
    Route::get('/article-types', [ArticleTypeController::class, 'index']);
    Route::get('/article-categories', [ArticleCategoryController::class, 'index']);
    Route::get('/subject-areas', [SubjectAreaController::class, 'index']);
    Route::get('/languages', [LanguageController::class, 'index']);

    // Media polymorphic uploads
    Route::post('/media', [MediaController::class, 'store']);
    Route::delete('/media/{id}', [MediaController::class, 'destroy'])->middleware('super-admin-delete');

    Route::prefix('media/uploads')->group(function () {
        Route::post('/initiate', [MediaUploadController::class, 'initiate'])->middleware('throttle:media-upload-initiate');
        Route::post('/{upload}/sign-parts', [MediaUploadController::class, 'signParts'])->middleware('throttle:media-upload-sign-parts');
        Route::get('/{upload}/resume', [MediaUploadController::class, 'resume'])->middleware('throttle:media-upload-read');
        Route::post('/{upload}/complete', [MediaUploadController::class, 'complete'])->middleware('throttle:media-upload-complete');
        Route::delete('/{upload}/abort', [MediaUploadController::class, 'abort'])->middleware('throttle:media-upload-read');
        Route::get('/{upload}/status', [MediaUploadController::class, 'status'])->middleware('throttle:media-upload-read');
    });

    // Article assets
    Route::post('/articles/{id}/assets', [ArticleAssetController::class, 'store'])
        ->middleware('permission:articles.manage-assets');
    Route::delete('/articles/assets/{asset_id}', [ArticleAssetController::class, 'destroy'])
        ->middleware(['super-admin-delete', 'permission:articles.manage-assets']);
    Route::post('/articles/{id}/files', [ArticleFileController::class, 'store'])
        ->middleware('permission:articles.manage-assets');

    // Support tickets
    Route::get('/support/tickets', [SupportTicketController::class, 'index']);
    Route::post('/support/tickets', [SupportTicketController::class, 'store']);
    Route::get('/support/tickets/attachments/{attachment}/download', [SupportTicketController::class, 'downloadAttachment'])
        ->middleware('throttle:media-download');
    Route::get('/support/tickets/{ticket}', [SupportTicketController::class, 'show']);
    Route::get('/support/tickets/{ticket}/messages', [SupportTicketController::class, 'messages']);
    Route::post('/support/tickets/{ticket}/messages', [SupportTicketController::class, 'reply']);
    Route::get('/support/tickets/{ticket}/activities', [SupportTicketController::class, 'activities']);

    // Admin Dashboard
    Route::prefix('admin')->group(function () {
        Route::middleware('permission:advertisements.manage')->prefix('advertisements')->group(function () {
            Route::get('/static-pages', [AdvertisementController::class, 'staticPages']);
            Route::get('/publications', [AdvertisementController::class, 'publications']);
            Route::get('/publications/{publication}/pages', [AdvertisementController::class, 'publicationPages']);
            Route::get('/publications/{publication}/published-articles', [AdvertisementController::class, 'publishedArticles']);
            Route::get('/', [AdvertisementController::class, 'index']);
            Route::post('/', [AdvertisementController::class, 'store']);
            Route::get('/{advertisement}', [AdvertisementController::class, 'show']);
            Route::put('/{advertisement}', [AdvertisementController::class, 'update']);
            Route::patch('/{advertisement}/status', [AdvertisementController::class, 'status']);
            Route::delete('/{advertisement}', [AdvertisementController::class, 'destroy'])->middleware('super-admin-delete');
        });
        // Dynamic Classifications CRUD (Settings Submenu)
        Route::apiResource('article-types', ArticleTypeController::class)->except(['index']);
        Route::apiResource('article-categories', ArticleCategoryController::class)->except(['index']);
        Route::apiResource('subject-areas', SubjectAreaController::class)->except(['index']);
        Route::apiResource('languages', LanguageController::class)->except(['index']);

        Route::get('/stats', [ArticleController::class, 'adminStats']);
        Route::get('/users', [RbacController::class, 'users'])->middleware('permission:users.view-any');
        Route::get('/users/magazine-assignment-options', [RbacController::class, 'magazineAssignmentOptions'])->middleware(['super-admin', 'permission:users.view-any']);
        Route::post('/users', [RbacController::class, 'store'])->middleware('super-admin');
        Route::get('/users/{id}', [RbacController::class, 'show'])->middleware('super-admin');
        Route::patch('/users/{id}', [RbacController::class, 'update'])->middleware('super-admin');
        Route::post('/users/{id}/impersonate', [ImpersonationController::class, 'start'])->middleware('super-admin');
        Route::get('/desk-observer/users', [DeskObserverController::class, 'users'])->middleware('super-admin');
        Route::get('/impersonation/status', [ImpersonationController::class, 'status']);
        Route::post('/impersonation/stop', [ImpersonationController::class, 'stop']);
        Route::put('/contact-settings', [ContactController::class, 'updateSettings']);
        Route::get('/contact-messages', [ContactController::class, 'getMessages']);
        Route::post('/contact-messages/{id}/reply', [ContactController::class, 'reply']);
        Route::post('/contact-subjects', [ContactController::class, 'storeSubject']);
        Route::put('/contact-subjects/{id}', [ContactController::class, 'updateSubject']);
        Route::delete('/contact-subjects/{id}', [ContactController::class, 'deleteSubject'])->middleware('super-admin-delete');

        Route::get('/support/tickets', [SupportTicketController::class, 'index'])->middleware('permission:support_ticket_management');
        Route::get('/support/tickets/{ticket}', [SupportTicketController::class, 'show'])->middleware('permission:support_ticket_management');
        Route::post('/support/tickets/{ticket}/messages', [SupportTicketController::class, 'reply'])->middleware('permission:support_ticket_management');
        Route::patch('/support/tickets/{ticket}/status', [SupportTicketController::class, 'updateStatus'])->middleware('permission:support_ticket_management');
        Route::get('/support/tickets/{ticket}/activities', [SupportTicketController::class, 'activities'])->middleware('permission:support_ticket_management');

        // Newsletter Campaign Management (Admin only)
        Route::get('/newsletter/subscribers', [NewsletterController::class, 'listSubscribers'])->middleware('permission:newsletters.view-any');
        Route::get('/newsletter/campaigns', [NewsletterController::class, 'listCampaigns'])->middleware('permission:newsletters.view-any');
        Route::post('/newsletter/send', [NewsletterController::class, 'sendCampaign'])->middleware('permission:newsletters.send');

        // CMS Content Management (Restricted to Admin / Super Admin)
        Route::get('/cms/{slug}', [CmsPageController::class, 'adminShow']);
        Route::put('/cms/{slug}', [CmsPageController::class, 'update'])->middleware('permission:settings.manage');

        // FAQ Management (Restricted to Admin / Super Admin)
        Route::get('/faqs', [FaqController::class, 'adminIndex'])->middleware('permission:settings.manage');
        Route::post('/faqs', [FaqController::class, 'store'])->middleware('permission:settings.manage');
        Route::put('/faqs/{id}', [FaqController::class, 'update'])->middleware('permission:settings.manage');
        Route::delete('/faqs/{id}', [FaqController::class, 'destroy'])->middleware(['super-admin-delete', 'permission:settings.manage']);

        // Footer CMS Categories Management
        Route::get('/footer/categories', [FooterCategoryController::class, 'index'])->middleware('permission:footer.manage');
        Route::post('/footer/categories', [FooterCategoryController::class, 'store'])->middleware('permission:footer.manage');
        Route::put('/footer/categories/{id}', [FooterCategoryController::class, 'update'])->middleware('permission:footer.manage');
        Route::delete('/footer/categories/{id}', [FooterCategoryController::class, 'destroy'])->middleware(['super-admin-delete', 'permission:footer.manage']);

        // Footer CMS Pages Management
        Route::get('/footer/pages', [FooterPageController::class, 'index'])->middleware('permission:footer.manage');
        Route::post('/footer/pages', [FooterPageController::class, 'store'])->middleware('permission:footer.manage');
        Route::put('/footer/pages/{id}', [FooterPageController::class, 'update'])->middleware('permission:footer.manage');
        Route::delete('/footer/pages/{id}', [FooterPageController::class, 'destroy'])->middleware(['super-admin-delete', 'permission:footer.manage']);

        // Magazines & Custom Pages (Restricted to Admin / Super Admin)
        Route::get('/magazines', [MagazineController::class, 'adminIndex'])->middleware('permission:magazines.view-own');
        Route::get('/magazines/{slug}', [MagazineController::class, 'adminShow'])->middleware('permission:magazines.view-own');
        Route::post('/magazines', [MagazineController::class, 'store'])->middleware('permission:magazines.create');
        Route::put('/magazines/{id}', [MagazineController::class, 'update'])->middleware('permission:magazines.edit');
        Route::delete('/magazines/{id}', [MagazineController::class, 'destroy'])->middleware(['super-admin-delete', 'permission:magazines.delete']);
        Route::post('/magazines/{id}/pages', [MagazineController::class, 'storePage'])->middleware('permission:magazines.edit');
        Route::put('/magazines/{magazineId}/pages/{pageId}', [MagazineController::class, 'updatePage'])->middleware('permission:magazines.edit');
        Route::delete('/magazines/{magazineId}/pages/{pageId}', [MagazineController::class, 'destroyPage'])->middleware(['super-admin-delete', 'permission:magazines.edit']);
        Route::prefix('journals')->group(function () {
            Route::get('/', [MagazineController::class, 'adminIndex'])->defaults('publication_type', 'journal')->middleware('permission:magazines.view-own');
            Route::get('/{slug}', [MagazineController::class, 'adminShow'])->defaults('publication_type', 'journal')->middleware('permission:magazines.view-own');
            Route::post('/', [MagazineController::class, 'store'])->defaults('publication_type', 'journal')->middleware('permission:magazines.create');
            Route::put('/{id}', [MagazineController::class, 'update'])->defaults('publication_type', 'journal')->middleware('permission:magazines.edit');
            Route::delete('/{id}', [MagazineController::class, 'destroy'])->defaults('publication_type', 'journal')->middleware(['super-admin-delete', 'permission:magazines.delete']);
            Route::post('/{id}/pages', [MagazineController::class, 'storePage'])->middleware('permission:magazines.edit');
            Route::put('/{magazineId}/pages/{pageId}', [MagazineController::class, 'updatePage'])->middleware('permission:magazines.edit');
            Route::delete('/{magazineId}/pages/{pageId}', [MagazineController::class, 'destroyPage'])->middleware(['super-admin-delete', 'permission:magazines.edit']);
        });

        // Tags Management
        Route::post('/tags', [TagController::class, 'store']);
        Route::put('/tags/{id}', [TagController::class, 'update']);
        Route::delete('/tags/{id}', [TagController::class, 'destroy'])->middleware('super-admin-delete');

        // Article Review & Update Endpoints
        Route::get('/articles', [ArticleController::class, 'adminList'])->middleware('permission:articles.view-own');
        Route::get('/articles/status-options', [ArticleController::class, 'adminStatusOptions'])->middleware('permission:articles.view-own');
        Route::get('/articles/filter-options', [ArticleController::class, 'adminFilterOptions'])->middleware('permission:articles.view-own');
        Route::get('/articles/{id}', [ArticleController::class, 'showById'])->middleware('permission:articles.view-own');
        Route::put('/articles/{id}', [ArticleController::class, 'update'])->middleware('permission:articles.edit-own');
        Route::patch('/articles/{id}', [ArticleController::class, 'update'])->middleware('permission:articles.edit-own');
        Route::patch('/articles/{id}/review', [ArticleController::class, 'review'])->middleware('permission:articles.approve');
        Route::patch('/articles/{id}/seo', [ArticleController::class, 'updateSeo']);
        Route::get('/articles/{id}/workflow', [ArticleWorkflowController::class, 'context'])->middleware('permission:articles.view-own');
        Route::get('/articles/{id}/versions', [ArticleWorkflowController::class, 'versions'])->middleware('permission:articles.view-own');
        Route::get('/workflow/assignees', [ArticleWorkflowController::class, 'assignees'])->middleware('permission:articles.view-own');
        Route::get('/my-sub-editor-assignments', [ArticleWorkflowController::class, 'mySubEditorAssignments'])->middleware('permission:articles.view-own');
        Route::get('/my-reviewer-assignments', [ArticleWorkflowController::class, 'myReviewerAssignments'])->middleware('permission:articles.view-own');
        Route::get('/my-production-assignments', [ArticleWorkflowController::class, 'myProductionAssignments'])->middleware('permission:articles.view-own');
        Route::get('/publisher-dashboard', [ArticleWorkflowController::class, 'publisherDashboard'])->middleware('permission:articles.view-own');

        // Recruiter-scoped Sub Editors
        Route::get('/editor/sub-editors', [EditorSubEditorController::class, 'index'])->middleware('permission:articles.view-own');
        Route::post('/editor/sub-editors', [EditorSubEditorController::class, 'store'])->middleware('permission:articles.view-own');
        Route::post('/editor/sub-editors/{subEditorId}/unassign', [EditorSubEditorController::class, 'unassign'])->middleware('permission:articles.view-own');

        // Scoped Panel Search
        Route::get('/search', [SearchController::class, 'search'])->middleware('permission:articles.view-own');
        Route::prefix('shared-pages')->middleware('permission:shared_pages.manage')->group(function () {
            Route::get('/publications', [SharedPublicPageController::class, 'publications']);
            Route::get('/', [SharedPublicPageController::class, 'index']);
            Route::post('/', [SharedPublicPageController::class, 'store']);
            Route::get('/{sharedPage}', [SharedPublicPageController::class, 'show']);
            Route::put('/{sharedPage}', [SharedPublicPageController::class, 'update']);
            Route::delete('/{sharedPage}', [SharedPublicPageController::class, 'destroy']);
            Route::patch('/{sharedPage}/status', [SharedPublicPageController::class, 'status']);
        });
        Route::post('/articles/{id}/screen', [ArticleWorkflowController::class, 'screen'])->middleware('permission:articles.approve');
        Route::post('/articles/{id}/assign-sub-editor', [ArticleWorkflowController::class, 'assignSubEditor'])->middleware('permission:articles.approve');
        Route::post('/articles/{id}/assign-reviewer', [ArticleWorkflowController::class, 'assignReviewer'])->middleware('permission:articles.approve');
        Route::post('/sub-editor-assignments/{id}/submit-recommendation', [ArticleWorkflowController::class, 'submitSubEditorRecommendation']);
        Route::post('/reviewer-assignments/{id}/accept', [ArticleWorkflowController::class, 'acceptReviewerAssignment']);
        Route::post('/reviewer-assignments/{id}/submit-review', [ArticleWorkflowController::class, 'submitReview']);
        Route::post('/reviewer-assignments/{id}/reopen', [ArticleWorkflowController::class, 'reopenReviewer'])->middleware('permission:articles.approve');
        Route::post('/reviewer-assignments/{id}/remind', [ArticleWorkflowController::class, 'remindReviewer'])->middleware('permission:articles.approve');
        Route::get('/review-questionnaire', [ArticleWorkflowController::class, 'questionnaire'])->middleware('super-admin');
        Route::post('/review-questionnaire', [ArticleWorkflowController::class, 'storeQuestionnaire'])->middleware('super-admin');
        Route::post('/articles/{id}/final-decision', [ArticleWorkflowController::class, 'finalDecision'])->middleware('permission:articles.approve');
        Route::post('/articles/{id}/author-final-review', [ArticleWorkflowController::class, 'authorFinalReview'])->middleware('permission:articles.view-own');
        Route::post('/articles/{id}/production-assignments', [ArticleWorkflowController::class, 'assignProduction'])->middleware('permission:articles.approve');
        Route::post('/production-assignments/{id}/complete', [ArticleWorkflowController::class, 'completeProduction']);
        Route::get('/issues', [ArticleWorkflowController::class, 'issues'])->middleware('permission:articles.view-own');
        Route::get('/issues/magazines', [ArticleWorkflowController::class, 'issueMagazines'])->middleware('permission:articles.view-own');
        Route::get('/issues/eligible-articles', [ArticleWorkflowController::class, 'eligibleIssueArticles'])->middleware('permission:articles.view-own');
        Route::post('/issues', [ArticleWorkflowController::class, 'storeIssue'])->middleware('permission:articles.approve');
        Route::get('/issues/{id}', [ArticleWorkflowController::class, 'showIssue'])->middleware('permission:articles.view-own');
        Route::post('/issues/{id}', [ArticleWorkflowController::class, 'updateIssue'])->middleware('permission:articles.approve');
        Route::post('/issues/{id}/publish', [ArticleWorkflowController::class, 'publishIssue'])->middleware('permission:articles.approve');
        Route::post('/issues/{id}/unpublish', [ArticleWorkflowController::class, 'unpublishIssue'])->middleware('permission:articles.approve');
        Route::post('/articles/{id}/publish', [ArticleWorkflowController::class, 'publish'])->middleware('permission:articles.approve');
        Route::post('/articles/{id}/post-publication-actions', [ArticleWorkflowController::class, 'postPublication'])->middleware('permission:articles.approve');
        Route::get('/articles/{id}/audit-logs', [ArticleWorkflowController::class, 'auditLogs'])->middleware('permission:articles.view-own');
        Route::patch('/magazines/{slug}/seo', [MagazineController::class, 'updateSeo']);
        Route::patch('/cms/{slug}/seo', [CmsPageController::class, 'updateSeo']);

        // Dynamic RBAC Management
        Route::prefix('user-management')->middleware('super-admin')->group(function () {
            Route::get('/registration-settings', [RbacController::class, 'registrationSettings'])->middleware('permission:settings.view-any');
            Route::patch('/registration-settings', [RbacController::class, 'updateRegistrationSettings'])->middleware('permission:settings.manage');
            Route::get('/registration-role-options', [RbacController::class, 'registrationRoleOptions'])->middleware('permission:settings.view-any');
        });

        Route::prefix('rbac')->middleware('super-admin')->group(function () {
            Route::get('/roles', [RbacController::class, 'roles'])->middleware('permission:roles.view-any');
            Route::post('/roles', [RbacController::class, 'storeRole'])->middleware('permission:roles.manage');
            Route::put('/roles/{id}', [RbacController::class, 'updateRole'])->middleware('permission:roles.manage');
            Route::patch('/roles/{id}', [RbacController::class, 'updateRole'])->middleware('permission:roles.manage');
            Route::delete('/roles/{id}', [RbacController::class, 'deleteRole'])->middleware(['super-admin-delete', 'permission:roles.manage']);
            Route::get('/permissions', [RbacController::class, 'permissions'])->middleware('permission:roles.view-any');
            Route::post('/roles/{id}/permissions', [RbacController::class, 'syncRolePermissions'])->middleware('permission:roles.manage');

            Route::get('/users', [RbacController::class, 'users'])->middleware('permission:users.view-any');
            Route::post('/users', [RbacController::class, 'storeUser'])->middleware('permission:users.create');
            Route::patch('/users/{id}/role', [RbacController::class, 'updateUserRole'])->middleware('permission:users.manage');
            Route::patch('/users/{id}', [RbacController::class, 'updateUser'])->middleware('permission:users.manage');

            // Default Registration Role Settings
            Route::get('/settings/registration-role', [RbacController::class, 'getRegistrationRole'])->middleware('permission:settings.view-any');
            Route::post('/settings/registration-role', [RbacController::class, 'updateRegistrationRole'])->middleware('permission:settings.manage');
        });
    });
});
