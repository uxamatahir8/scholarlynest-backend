<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Admin\RbacController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\CmsPageController;
use App\Http\Controllers\MagazineController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\FaqController;
use App\Http\Controllers\GlobalSearchController;
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

// Unified Global Search
Route::get('/search/preview', [GlobalSearchController::class, 'preview']);
Route::get('/search/full', [GlobalSearchController::class, 'full']);

// Public Contact Page Settings & Submission
Route::get('/contact-settings', [ContactController::class, 'getSettings']);
Route::post('/contact', [ContactController::class, 'submit']);

// Public Newsletter Subscription
Route::post('/newsletter/subscribe', [NewsletterController::class, 'subscribe']);
Route::get('/newsletter/unsubscribe/{token}', [NewsletterController::class, 'unsubscribe']);

// Public Magazines & Articles Read Routes
Route::get('/magazines', [MagazineController::class, 'index']);
Route::get('/magazines/latest', [MagazineController::class, 'latest']);
Route::get('/magazines/{slug}', [MagazineController::class, 'show']);
Route::get('/magazines/{slug}/articles', [MagazineController::class, 'articles']);
Route::get('/articles/latest', [ArticleController::class, 'latest']);
Route::get('/articles/{slug}', [ArticleController::class, 'show']);
Route::post('/articles/{id}/click', [ArticleController::class, 'trackClick']);
Route::post('/articles/{id}/share-click', [ArticleController::class, 'trackShareClick']);
Route::get('/articles/{id}/download-pdf', [ArticleController::class, 'downloadPdf']);

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
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/2fa/enable', [AuthController::class, 'enable2Fa']);
    Route::post('/2fa/request-disable-code', [AuthController::class, 'request2FaDisableCode']);
    Route::post('/2fa/disable', [AuthController::class, 'disable2Fa']);
    Route::post('/password/request-code', [AuthController::class, 'requestPasswordChangeCode']);
    Route::post('/password/verify-code', [AuthController::class, 'verifyPasswordChangeCode']);
    Route::post('/password/change', [AuthController::class, 'changePassword']);

    // Article submissions
    Route::post('/articles', [ArticleController::class, 'store'])->middleware('permission:articles.create');
    Route::get('/tags', [TagController::class, 'index']);
 
    // Media polymorphic uploads
    Route::post('/media', [MediaController::class, 'store']);
    Route::delete('/media/{id}', [MediaController::class, 'destroy']);
 
    // Admin Dashboard
    Route::prefix('admin')->group(function () {
        Route::get('/stats', [ArticleController::class, 'adminStats']);
        Route::put('/contact-settings', [ContactController::class, 'updateSettings']);
        Route::get('/contact-messages', [ContactController::class, 'getMessages']);
        Route::post('/contact-messages/{id}/reply', [ContactController::class, 'reply']);
 
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
        Route::delete('/faqs/{id}', [FaqController::class, 'destroy'])->middleware('permission:settings.manage');
 
        // Magazines & Custom Pages (Restricted to Admin / Super Admin)
        Route::post('/magazines', [MagazineController::class, 'store'])->middleware('permission:magazines.create');
        Route::put('/magazines/{id}', [MagazineController::class, 'update'])->middleware('permission:magazines.edit');
        Route::delete('/magazines/{id}', [MagazineController::class, 'destroy'])->middleware('permission:magazines.delete');
        Route::post('/magazines/{id}/pages', [MagazineController::class, 'storePage'])->middleware('permission:magazines.edit');
        Route::put('/magazines/{magazineId}/pages/{pageId}', [MagazineController::class, 'updatePage'])->middleware('permission:magazines.edit');
        Route::delete('/magazines/{magazineId}/pages/{pageId}', [MagazineController::class, 'destroyPage'])->middleware('permission:magazines.edit');
 
        // Tags Management
        Route::post('/tags', [TagController::class, 'store']);
        Route::put('/tags/{id}', [TagController::class, 'update']);
        Route::delete('/tags/{id}', [TagController::class, 'destroy']);
 
        // Article Review & Update Endpoints
        Route::get('/articles', [ArticleController::class, 'adminList'])->middleware('permission:articles.view-own');
        Route::get('/articles/{id}', [ArticleController::class, 'showById'])->middleware('permission:articles.view-own');
        Route::put('/articles/{id}', [ArticleController::class, 'update'])->middleware('permission:articles.edit-own');
        Route::patch('/articles/{id}/review', [ArticleController::class, 'review'])->middleware('permission:articles.approve');
        Route::patch('/articles/{id}/seo', [ArticleController::class, 'updateSeo']);
        Route::patch('/magazines/{slug}/seo', [MagazineController::class, 'updateSeo']);
        Route::patch('/cms/{slug}/seo', [CmsPageController::class, 'updateSeo']);
 
        // Dynamic RBAC Management
        Route::prefix('rbac')->group(function () {
            Route::get('/roles', [RbacController::class, 'roles'])->middleware('permission:roles.view-any');
            Route::post('/roles', [RbacController::class, 'storeRole'])->middleware('permission:roles.manage');
            Route::delete('/roles/{id}', [RbacController::class, 'deleteRole'])->middleware('permission:roles.manage');
            Route::get('/permissions', [RbacController::class, 'permissions'])->middleware('permission:roles.view-any');
            Route::post('/roles/{id}/permissions', [RbacController::class, 'syncRolePermissions'])->middleware('permission:roles.manage');
            
            Route::get('/users', [RbacController::class, 'users'])->middleware('permission:users.view-any');
            Route::post('/users', [RbacController::class, 'storeUser'])->middleware('permission:users.create');
            Route::patch('/users/{id}/role', [RbacController::class, 'updateUserRole'])->middleware('permission:users.manage');

            // Default Registration Role Settings
            Route::get('/settings/registration-role', [RbacController::class, 'getRegistrationRole'])->middleware('permission:settings.view-any');
            Route::post('/settings/registration-role', [RbacController::class, 'updateRegistrationRole'])->middleware('permission:settings.manage');
        });
    });
});
