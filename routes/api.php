<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Admin\RbacController;
use App\Http\Controllers\Admin\ArticleWorkflowController;
use App\Http\Controllers\Admin\EditorSubEditorController;
use App\Http\Controllers\Admin\SearchController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\CmsPageController;
use App\Http\Controllers\MagazineController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\ArticleFileController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\FaqController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\Admin\ArticleTypeController;
use App\Http\Controllers\Admin\ArticleCategoryController;
use App\Http\Controllers\Admin\SubjectAreaController;
use App\Http\Controllers\Admin\LanguageController;
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
Route::get('/cms/{slug}', [CmsPageController::class, 'show']);
Route::get('/faqs', [FaqController::class, 'index']);

// Public Footer & Dynamic Custom Pages
Route::get('/public/footer', [\App\Http\Controllers\FooterController::class, 'index']);
Route::get('/public/footer/pages/{slug}', [\App\Http\Controllers\FooterController::class, 'showPage']);

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
Route::get('/magazines', [MagazineController::class, 'index']);
Route::get('/magazines/latest', [MagazineController::class, 'latest']);
Route::get('/magazines/{slug}/about-and-overview', [MagazineController::class, 'aboutAndOverview']);
Route::get('/magazines/{slug}/table-of-contents', [MagazineController::class, 'tableOfContents']);
Route::get('/magazines/{slug}/latest-published-articles', [MagazineController::class, 'latestPublishedArticles']);
Route::get('/magazines/{slug}/pages/{pageSlug}', [MagazineController::class, 'publicPage']);
Route::get('/magazines/{slug}', [MagazineController::class, 'show']);
Route::get('/magazines/{slug}/articles', [MagazineController::class, 'articles']);
Route::get('/articles/latest', [ArticleController::class, 'latest']);
Route::get('/articles/{slug}', [ArticleController::class, 'show']);
Route::post('/articles/{id}/click', [ArticleController::class, 'trackClick']);
Route::post('/articles/{id}/share-click', [ArticleController::class, 'trackShareClick']);
Route::get('/articles/{id}/download-pdf', [ArticleController::class, 'downloadPdf'])->middleware('throttle:60,1');
Route::get('/articles/assets/{asset_id}/download', [\App\Http\Controllers\ArticleAssetController::class, 'download'])->middleware('throttle:60,1');
Route::get('/articles/files/{file_id}/download', [ArticleFileController::class, 'download'])->middleware('throttle:60,1');

Route::get('/public/magazines', function (\Illuminate\Http\Request $request) {
    $query = \App\Models\Magazine::orderBy('created_at', 'desc');
    if ($request->has('per_page') || $request->has('limit')) {
        $limit = $request->integer('per_page') ?: $request->integer('limit');
        $query->limit($limit);
    }
    $magazines = $query->get();
    return response()->json(['data' => $magazines]);
});

Route::get('/public/newsletters', function () {
    $campaigns = \App\Models\NewsletterCampaign::select(['id', 'subject', 'created_at'])
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
    Route::post('/2fa/verify', [AuthController::class, 'verify2Fa']);
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
    Route::post('/password/request-code', [AuthController::class, 'requestPasswordChangeCode']);
    Route::post('/password/verify-code', [AuthController::class, 'verifyPasswordChangeCode']);
    Route::post('/password/change', [AuthController::class, 'changePassword']);
    Route::post('/password/reset-enforced', [AuthController::class, 'resetEnforcedPassword']);

    // Article submissions
    Route::post('/articles', [ArticleController::class, 'store'])->middleware('permission:articles.create');
    Route::get('/tags', [TagController::class, 'index']);
    
    // Article classifications (lists for dropdown selects in form)
    Route::get('/article-types', [ArticleTypeController::class, 'index']);
    Route::get('/article-categories', [ArticleCategoryController::class, 'index']);
    Route::get('/subject-areas', [SubjectAreaController::class, 'index']);
    Route::get('/languages', [LanguageController::class, 'index']);
 
    // Media polymorphic uploads
    Route::post('/media', [MediaController::class, 'store']);
    Route::delete('/media/{id}', [MediaController::class, 'destroy'])->middleware('super-admin-delete');

    // Article assets
    Route::post('/articles/{id}/assets', [\App\Http\Controllers\ArticleAssetController::class, 'store'])
        ->middleware('permission:articles.manage-assets');
    Route::delete('/articles/assets/{asset_id}', [\App\Http\Controllers\ArticleAssetController::class, 'destroy'])
        ->middleware(['super-admin-delete', 'permission:articles.manage-assets']);
    Route::post('/articles/{id}/files', [ArticleFileController::class, 'store'])
        ->middleware('permission:articles.manage-assets');
 
    // Admin Dashboard
    Route::prefix('admin')->group(function () {
        // Dynamic Classifications CRUD (Settings Submenu)
        Route::apiResource('article-types', ArticleTypeController::class)->except(['index']);
        Route::apiResource('article-categories', ArticleCategoryController::class)->except(['index']);
        Route::apiResource('subject-areas', SubjectAreaController::class)->except(['index']);
        Route::apiResource('languages', LanguageController::class)->except(['index']);

        Route::get('/stats', [ArticleController::class, 'adminStats']);
        Route::get('/users', [RbacController::class, 'users'])->middleware('permission:users.view-any');
        Route::put('/contact-settings', [ContactController::class, 'updateSettings']);
        Route::get('/contact-messages', [ContactController::class, 'getMessages']);
        Route::post('/contact-messages/{id}/reply', [ContactController::class, 'reply']);
        Route::post('/contact-subjects', [ContactController::class, 'storeSubject']);
        Route::put('/contact-subjects/{id}', [ContactController::class, 'updateSubject']);
        Route::delete('/contact-subjects/{id}', [ContactController::class, 'deleteSubject'])->middleware('super-admin-delete');
 
        // Newsletter Campaign Management (Admin only)
        Route::get('/newsletter/subscribers', [NewsletterController::class, 'listSubscribers'])->middleware('permission:newsletters.view-any');
        Route::get('/newsletter/campaigns', [NewsletterController::class, 'listCampaigns'])->middleware('permission:newsletters.view-any');
        Route::post('/newsletter/send', [NewsletterController::class, 'sendCampaign'])->middleware('permission:newsletters.send');
 
        // CMS Content Management (Restricted to Admin / Super Admin)
        Route::put('/cms/{slug}', [CmsPageController::class, 'update'])->middleware('permission:settings.manage');

        // FAQ Management (Restricted to Admin / Super Admin)
        Route::get('/faqs', [FaqController::class, 'adminIndex'])->middleware('permission:settings.manage');
        Route::post('/faqs', [FaqController::class, 'store'])->middleware('permission:settings.manage');
        Route::put('/faqs/{id}', [FaqController::class, 'update'])->middleware('permission:settings.manage');
        Route::delete('/faqs/{id}', [FaqController::class, 'destroy'])->middleware(['super-admin-delete', 'permission:settings.manage']);

        // Footer CMS Categories Management
        Route::get('/footer/categories', [\App\Http\Controllers\Admin\FooterCategoryController::class, 'index'])->middleware('permission:footer.manage');
        Route::post('/footer/categories', [\App\Http\Controllers\Admin\FooterCategoryController::class, 'store'])->middleware('permission:footer.manage');
        Route::put('/footer/categories/{id}', [\App\Http\Controllers\Admin\FooterCategoryController::class, 'update'])->middleware('permission:footer.manage');
        Route::delete('/footer/categories/{id}', [\App\Http\Controllers\Admin\FooterCategoryController::class, 'destroy'])->middleware(['super-admin-delete', 'permission:footer.manage']);

        // Footer CMS Pages Management
        Route::get('/footer/pages', [\App\Http\Controllers\Admin\FooterPageController::class, 'index'])->middleware('permission:footer.manage');
        Route::post('/footer/pages', [\App\Http\Controllers\Admin\FooterPageController::class, 'store'])->middleware('permission:footer.manage');
        Route::put('/footer/pages/{id}', [\App\Http\Controllers\Admin\FooterPageController::class, 'update'])->middleware('permission:footer.manage');
        Route::delete('/footer/pages/{id}', [\App\Http\Controllers\Admin\FooterPageController::class, 'destroy'])->middleware(['super-admin-delete', 'permission:footer.manage']);
 
        // Magazines & Custom Pages (Restricted to Admin / Super Admin)
        Route::get('/magazines', [MagazineController::class, 'adminIndex'])->middleware('permission:magazines.view-own');
        Route::get('/magazines/{slug}', [MagazineController::class, 'adminShow'])->middleware('permission:magazines.view-own');
        Route::post('/magazines', [MagazineController::class, 'store'])->middleware('permission:magazines.create');
        Route::put('/magazines/{id}', [MagazineController::class, 'update'])->middleware('permission:magazines.edit');
        Route::delete('/magazines/{id}', [MagazineController::class, 'destroy'])->middleware(['super-admin-delete', 'permission:magazines.delete']);
        Route::post('/magazines/{id}/pages', [MagazineController::class, 'storePage'])->middleware('permission:magazines.edit');
        Route::put('/magazines/{magazineId}/pages/{pageId}', [MagazineController::class, 'updatePage'])->middleware('permission:magazines.edit');
        Route::delete('/magazines/{magazineId}/pages/{pageId}', [MagazineController::class, 'destroyPage'])->middleware(['super-admin-delete', 'permission:magazines.edit']);
 
        // Tags Management
        Route::post('/tags', [TagController::class, 'store']);
        Route::put('/tags/{id}', [TagController::class, 'update']);
        Route::delete('/tags/{id}', [TagController::class, 'destroy'])->middleware('super-admin-delete');
 
        // Article Review & Update Endpoints
        Route::get('/articles', [ArticleController::class, 'adminList'])->middleware('permission:articles.view-own');
        Route::get('/articles/{id}', [ArticleController::class, 'showById'])->middleware('permission:articles.view-own');
        Route::put('/articles/{id}', [ArticleController::class, 'update'])->middleware('permission:articles.edit-own');
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
        Route::post('/articles/{id}/screen', [ArticleWorkflowController::class, 'screen'])->middleware('permission:articles.approve');
        Route::post('/articles/{id}/assign-sub-editor', [ArticleWorkflowController::class, 'assignSubEditor'])->middleware('permission:articles.approve');
        Route::post('/articles/{id}/assign-reviewer', [ArticleWorkflowController::class, 'assignReviewer'])->middleware('permission:articles.approve');
        Route::post('/sub-editor-assignments/{id}/submit-recommendation', [ArticleWorkflowController::class, 'submitSubEditorRecommendation']);
        Route::post('/reviewer-assignments/{id}/accept', [ArticleWorkflowController::class, 'acceptReviewerAssignment']);
        Route::post('/reviewer-assignments/{id}/submit-review', [ArticleWorkflowController::class, 'submitReview']);
        Route::post('/reviewer-assignments/{id}/reopen', [ArticleWorkflowController::class, 'reopenReviewer'])->middleware('permission:articles.approve');
        Route::post('/articles/{id}/final-decision', [ArticleWorkflowController::class, 'finalDecision'])->middleware('permission:articles.approve');
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
        Route::prefix('rbac')->group(function () {
            Route::get('/roles', [RbacController::class, 'roles'])->middleware('permission:roles.view-any');
            Route::post('/roles', [RbacController::class, 'storeRole'])->middleware('permission:roles.manage');
            Route::put('/roles/{id}', [RbacController::class, 'updateRole'])->middleware('permission:roles.manage');
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
