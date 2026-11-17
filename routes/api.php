<?php

use App\Http\Controllers\Api\AgentChatController;
use App\Http\Controllers\Api\AiProviderController;
use App\Http\Controllers\Api\AiReviewController;
use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerAuthController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\KnowledgeController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\WorkspaceController;
use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\WidgetChatController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - V1
|--------------------------------------------------------------------------
|
| Multi-tenant SaaS Live Chat Platform RESTful Endpoints
|
*/

Route::prefix('v1')->group(function () {

    // =========================================================================
    // 1. PUBLIC WIDGET API (Client Sites & Visitors)
    // =========================================================================
    // Every public widget endpoint is rate limited per visitor and per IP (audit H6).
    Route::prefix('widget')->middleware('throttle:widget')->group(function () {
        Route::get('status', [WidgetChatController::class, 'status']);
        Route::post('init', [WidgetChatController::class, 'init'])->middleware('throttle:widget-init');
        Route::post('session', [WidgetChatController::class, 'beginSession'])->middleware('throttle:widget-init');
        Route::post('start', [WidgetChatController::class, 'startConversation'])->middleware(['throttle:widget-messages', 'throttle:widget-start']);
        Route::post('messages', [WidgetChatController::class, 'sendMessage'])->middleware('throttle:widget-messages');
        Route::post('upload', [WidgetChatController::class, 'uploadAttachment'])->middleware(['throttle:widget-messages', 'throttle:widget-uploads']);
        Route::post('conversations/{id}/rate', [WidgetChatController::class, 'submitRating'])->middleware('throttle:widget-messages');
        Route::post('conversations/{id}/transcript', [WidgetChatController::class, 'requestTranscript'])->middleware('throttle:widget-messages');
        Route::post('typing', [WidgetChatController::class, 'typing'])->withoutMiddleware('throttle:widget')->middleware('throttle:widget-typing');
        Route::post('broadcasting/auth', [WidgetChatController::class, 'authBroadcast']);
        Route::get('faqs', [WidgetChatController::class, 'faqs']);
        Route::get('conversations/{id}/sync', [WidgetChatController::class, 'syncMessages']);
        Route::post('heartbeat', [WidgetChatController::class, 'heartbeat']);
        Route::post('conversations/{id}/escalate', [WidgetChatController::class, 'escalate'])->middleware('throttle:widget-messages');

        // Customer Support PIN identity (separate from staff auth). Strict per-IP/per-e-mail limits.
        Route::prefix('customer')->group(function () {
            Route::post('signup', [CustomerAuthController::class, 'signup'])->middleware('throttle:customer-signup');
            Route::post('login', [CustomerAuthController::class, 'login'])->middleware('throttle:customer-login');
            // Chat-style sign-in: tells the widget whether to ask for a new PIN or the existing one.
            Route::post('identify', [CustomerAuthController::class, 'identify'])->middleware('throttle:customer-identify');
            Route::post('forgot-pin', [CustomerAuthController::class, 'forgot'])->middleware('throttle:otp-request');
            Route::post('reset-pin', [CustomerAuthController::class, 'reset'])->middleware('throttle:otp-verify');
            Route::post('logout', [CustomerAuthController::class, 'logout']);
            Route::get('me', [CustomerAuthController::class, 'me']);
            Route::post('verify-email/request', [CustomerAuthController::class, 'requestVerification'])->middleware('throttle:otp-request');
            Route::post('verify-email/confirm', [CustomerAuthController::class, 'confirmVerification'])->middleware('throttle:otp-verify');
            Route::get('conversations/{id}', [CustomerAuthController::class, 'conversation']);
        });
        Route::post('leave', [WidgetChatController::class, 'leave']);
    });

    // Public branding for website-specific admin login pages (/{slug}/admin).
    Route::get('public/websites/{slug}', [WorkspaceController::class, 'publicWebsite'])->middleware('throttle:widget');
    Route::get('public/tenants/{slug}', [WorkspaceController::class, 'publicTenant'])->middleware('throttle:widget');

    // Private attachment downloads: signed, expiring links only (audit C4/H9).
    Route::get('attachments/{attachment}', [AttachmentController::class, 'download'])
        ->middleware(['signed', 'throttle:widget'])
        ->name('attachments.download');

    // =========================================================================
    // 2. PUBLIC AUTHENTICATION ROUTES
    // =========================================================================
    Route::prefix('auth')->middleware('throttle:auth-attempts')->group(function () {
        Route::post('register-tenant', [AuthController::class, 'registerTenant']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('reset-password', [AuthController::class, 'resetPassword']);
    });

    // =========================================================================
    // 3. PROTECTED AGENT & ADMIN ROUTES (Sanctum Authenticated)
    // =========================================================================
    Route::middleware(['auth:sanctum', 'throttle:staff'])->group(function () {

        // Session & Profile
        Route::prefix('auth')->group(function () {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('status', [AuthController::class, 'updateStatus']);
        });

        // Team / Agent Management
        Route::get('agents', [AuthController::class, 'listAgents']);
        Route::post('agents', [AuthController::class, 'createAgent'])->middleware('role:admin,super_admin');
        Route::match(['put', 'post'], 'agents/{id}', [AuthController::class, 'updateAgent'])->middleware('role:admin,super_admin');
        Route::post('agents/{id}/update', [AuthController::class, 'updateAgent'])->middleware('role:admin,super_admin');
        Route::delete('agents/{id}', [AuthController::class, 'deleteAgent'])->middleware('role:admin,super_admin');

        // Super Admin SaaS Platform Management
        Route::get('platform/tenants', [AuthController::class, 'platformTenants'])->middleware('role:super_admin');
        Route::post('platform/tenants', [AuthController::class, 'platformCreateTenant'])->middleware('role:super_admin');
        Route::get('platform/stats', [AuthController::class, 'platformStats'])->middleware('role:super_admin');
        Route::get('platform/users', [AuthController::class, 'platformUsers'])->middleware('role:super_admin');
        Route::match(['put', 'post'], 'platform/users/{id}', [AuthController::class, 'platformUpdateUser'])->middleware('role:super_admin');
        Route::post('platform/users/{id}/update', [AuthController::class, 'platformUpdateUser'])->middleware('role:super_admin');
        Route::delete('platform/users/{id}', [AuthController::class, 'platformDeleteUser'])->middleware('role:super_admin');
        Route::post('platform/flush-cache', [AuthController::class, 'platformFlushCache'])->middleware('role:super_admin');
        Route::put('auth/profile', [AuthController::class, 'updateProfile']);

        // Properties / Websites Management
        Route::apiResource('properties', PropertyController::class)->except(['destroy']);
        Route::delete('properties/{id}', [PropertyController::class, 'destroy'])->middleware('role:admin,super_admin');
        Route::post('properties/{id}/regenerate-keys', [PropertyController::class, 'regenerateKeys'])->middleware('role:admin,super_admin');
        Route::post('properties/{id}/test-webhook', [AgentChatController::class, 'testWebhook'])->middleware('role:admin,super_admin');
        Route::get('properties/{id}/departments', [PropertyController::class, 'listDepartments']);
        Route::post('properties/{id}/departments', [PropertyController::class, 'storeDepartment']); // website admins allowed (checked in controller)
        Route::put('departments/{id}', [PropertyController::class, 'updateDepartment']); // website admins allowed (checked in controller)
        Route::post('departments/{id}/update', [PropertyController::class, 'updateDepartment']); // website admins allowed (checked in controller)
        Route::delete('departments/{id}', [PropertyController::class, 'destroyDepartment']); // website admins allowed (checked in controller)
        Route::post('properties/{id}/canned-responses', [AgentChatController::class, 'storeCannedResponse'])->middleware('role:admin,super_admin');
        Route::delete('canned-responses/{id}', [AgentChatController::class, 'destroyCannedResponse'])->middleware('role:admin,super_admin');
        Route::get('properties/{id}/faqs', [PropertyController::class, 'listFaqs']);
        Route::post('properties/{id}/faqs', [PropertyController::class, 'storeFaq']); // website admins allowed (checked in controller)
        Route::post('properties/{id}/faqs/import-csv', [PropertyController::class, 'importFaqsCsv']); // website admins allowed
        Route::put('faqs/{id}', [PropertyController::class, 'updateFaq']); // website admins allowed (checked in controller)
        Route::delete('faqs/{id}', [PropertyController::class, 'destroyFaq']); // website admins allowed (checked in controller)

        // Real-Time Chat Workspace (Live Inbox)
        Route::prefix('chat')->middleware('company.data')->group(function () {
            Route::post('upload', [AgentChatController::class, 'uploadAttachment']);
            Route::post('visitors/{id}/block', [AgentChatController::class, 'toggleBlockVisitor']);
            Route::get('stats', [AgentChatController::class, 'stats']);
            Route::get('conversations', [AgentChatController::class, 'conversations']);
            Route::get('conversations/{id}', [AgentChatController::class, 'show']);
            Route::post('conversations/{id}/messages', [AgentChatController::class, 'sendMessage']);
            Route::post('conversations/{id}/assign', [AgentChatController::class, 'assign']);
            Route::post('conversations/{id}/status', [AgentChatController::class, 'updateStatus']);
            Route::post('conversations/{id}/transfer', [AgentChatController::class, 'transfer']);
            Route::post('conversations/{id}/read', [AgentChatController::class, 'markAsRead']);
            Route::post('conversations/{id}/typing', [AgentChatController::class, 'typing']);
            Route::post('conversations/{id}/transcript', [AgentChatController::class, 'sendTranscript']);
            Route::get('canned-responses', [AgentChatController::class, 'cannedResponses']);
        });

        // Support tickets
        Route::get('tickets', [TicketController::class, 'index'])->middleware('company.data');
        Route::post('tickets', [TicketController::class, 'store'])->middleware('company.data');
        Route::get('tickets/{id}', [TicketController::class, 'show'])->middleware('company.data');
        Route::post('tickets/{id}/reply', [TicketController::class, 'reply'])->middleware('company.data');
        Route::post('tickets/{id}/notes', [TicketController::class, 'note'])->middleware('company.data');
        Route::match(['put', 'patch'], 'tickets/{id}', [TicketController::class, 'update'])->middleware('company.data');
        Route::post('tickets/{id}/update', [TicketController::class, 'update'])->middleware('company.data');

        // Human takeover / AI control / internal notes / quotations
        Route::post('chat/conversations/{id}/takeover', [TicketController::class, 'takeOver'])->middleware('company.data');
        Route::post('chat/conversations/{id}/resume-ai', [TicketController::class, 'resumeAi'])->middleware('company.data');
        Route::post('chat/conversations/{id}/notes', [TicketController::class, 'conversationNote'])->middleware('company.data');
        Route::post('chat/messages/{id}/quotation', [TicketController::class, 'markQuotation'])->middleware('company.data');

        // Notifications and follow-up tasks
        Route::get('notifications', [NotificationController::class, 'index'])->middleware('company.data');
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->middleware('company.data');
        Route::post('notifications/{id}/read', [NotificationController::class, 'markRead'])->middleware('company.data');
        Route::get('tasks', [NotificationController::class, 'tasks'])->middleware('company.data');
        Route::post('tasks/{id}/complete', [NotificationController::class, 'completeTask'])->middleware('company.data');

        // Telegram channels (company admins)
        Route::get('settings/telegram', [NotificationController::class, 'channels'])->middleware('company.data');
        Route::post('settings/telegram', [NotificationController::class, 'saveChannel'])->middleware('company.data');
        Route::post('settings/telegram/{id}', [NotificationController::class, 'saveChannel'])->middleware('company.data');
        Route::delete('settings/telegram/{id}', [NotificationController::class, 'deleteChannel'])->middleware('company.data');
        Route::post('settings/telegram/{id}/test', [NotificationController::class, 'testChannel'])->middleware('company.data');

        // Knowledge base
        Route::get('knowledge', [KnowledgeController::class, 'index'])->middleware('company.data');
        Route::post('knowledge/manual', [KnowledgeController::class, 'storeManual'])->middleware('company.data');
        Route::post('knowledge/upload', [KnowledgeController::class, 'upload'])->middleware('company.data');
        Route::post('knowledge/search-test', [KnowledgeController::class, 'testSearch'])->middleware('company.data');
        Route::get('knowledge/{id}', [KnowledgeController::class, 'show'])->middleware('company.data');
        Route::post('knowledge/{id}/approve', [KnowledgeController::class, 'approve'])->middleware('company.data');
        Route::post('knowledge/{id}/reject', [KnowledgeController::class, 'reject'])->middleware('company.data');
        Route::post('knowledge/{id}/archive', [KnowledgeController::class, 'archive'])->middleware('company.data');

        // AI settings, budget and usage
        Route::get('ai/settings', [KnowledgeController::class, 'aiSettings'])->middleware('company.data');
        Route::post('ai/settings', [KnowledgeController::class, 'saveAiSettings'])->middleware('company.data');
        Route::get('ai/usage', [KnowledgeController::class, 'aiUsage'])->middleware('company.data');

        // Admin-only review of automated answers: rating, corrections (human-approved), analytics, regression, connection test
        Route::middleware('company.data')->prefix('ai')->group(function () {
            Route::get('answers', [AiReviewController::class, 'answers']);
            Route::post('answers/{messageId}/review', [AiReviewController::class, 'review']);
            Route::post('reviews/{id}/approve', [AiReviewController::class, 'approveCorrection']);
            Route::post('reviews/{id}/reject', [AiReviewController::class, 'rejectCorrection']);
            Route::get('quality', [AiReviewController::class, 'quality']);
            Route::post('regression/run', [AiReviewController::class, 'regression']);
            Route::post('test-connection', [AiReviewController::class, 'testConnection'])->middleware('throttle:5,1');

            // Providers and models (each company: its own keys, models and approvals)
            Route::get('providers', [AiProviderController::class, 'index']);
            Route::post('providers/custom', [AiProviderController::class, 'storeCustom']);
            Route::post('providers/{key}', [AiProviderController::class, 'update']);
            Route::delete('providers/{key}', [AiProviderController::class, 'destroy']);
            Route::post('providers/{key}/test', [AiProviderController::class, 'test'])->middleware('throttle:20,1');
            Route::post('providers/{key}/discover', [AiProviderController::class, 'discover'])->middleware('throttle:20,1');
            Route::post('providers/{key}/models', [AiProviderController::class, 'storeModel']);
            Route::post('models/{id}', [AiProviderController::class, 'updateModel']);
            Route::post('models/{id}/test', [AiProviderController::class, 'testModel'])->middleware('throttle:20,1');
            Route::post('models/{id}/default', [AiProviderController::class, 'makeDefault']);
            Route::delete('models/{id}', [AiProviderController::class, 'destroyModel']);
        });
        Route::post('ai/prices', [KnowledgeController::class, 'savePrice']);

        // Company, websites, analytics
        Route::get('company', [WorkspaceController::class, 'company'])->middleware('company.data');
        Route::post('company', [WorkspaceController::class, 'saveCompany']);
        Route::post('websites/{id}/settings', [WorkspaceController::class, 'saveWebsite']);
        Route::get('websites/{id}/staff', [WorkspaceController::class, 'websiteStaff']);
        Route::post('websites/{id}/staff', [WorkspaceController::class, 'setWebsiteStaff']);
        Route::get('analytics', [WorkspaceController::class, 'analytics'])->middleware('company.data');

        // Platform super admin: plans and subscriptions
        Route::get('platform/overview', [PlatformController::class, 'overview'])->middleware('role:super_admin');
        Route::post('platform/tenants/{id}', [PlatformController::class, 'updateTenant'])->middleware('role:super_admin');
        Route::post('platform/tenants/{id}/status', [PlatformController::class, 'setTenantStatus'])->middleware('role:super_admin');
        Route::post('platform/tenants/{id}/subscription', [PlatformController::class, 'setSubscription'])->middleware('role:super_admin');
        Route::post('platform/plans', [PlatformController::class, 'savePlan'])->middleware('role:super_admin');
        Route::post('platform/plans/{code}', [PlatformController::class, 'savePlan'])->middleware('role:super_admin');
        Route::get('platform/ai/custom-providers', [AiProviderController::class, 'platformList'])->middleware('role:super_admin');
        Route::post('platform/ai/custom-providers/{id}/decision', [AiProviderController::class, 'platformDecide'])->middleware('role:super_admin');
        Route::get('platform/ai-prices', [PlatformController::class, 'aiPrices'])->middleware('role:super_admin');
        Route::get('platform/audit-logs', [PlatformController::class, 'auditLogs'])->middleware('role:super_admin');
        Route::get('platform/system-health', [PlatformController::class, 'systemHealth'])->middleware('role:super_admin');
        Route::get('platform/mail-settings', [PlatformController::class, 'mailSettings'])->middleware('role:super_admin');
        Route::post('platform/mail-settings', [PlatformController::class, 'saveMailSettings'])->middleware('role:super_admin');
        Route::post('platform/mail-settings/test', [PlatformController::class, 'testMailSettings'])->middleware('role:super_admin');

        // Leads Management (company + website scoped)
        // Website customers (Support PIN accounts): list, administrator PIN reset, unlock.
        Route::get('customers', [CustomerController::class, 'index'])->middleware('company.data');
        Route::post('customers/{id}/reset-pin', [CustomerController::class, 'resetPin'])->middleware('company.data');
        Route::post('customers/{id}/unlock', [CustomerController::class, 'unlock'])->middleware('company.data');
        Route::get('leads', [LeadController::class, 'index'])->middleware('company.data');
        Route::get('leads/meta', [LeadController::class, 'meta'])->middleware('company.data');
        Route::get('leads/export', [LeadController::class, 'export'])->middleware('company.data');
        Route::get('leads/{id}', [LeadController::class, 'show'])->middleware('company.data');
        Route::match(['put', 'patch'], 'leads/{id}', [LeadController::class, 'update'])->middleware('company.data');
        Route::post('leads/{id}/update', [LeadController::class, 'update'])->middleware('company.data'); // hosts that block PUT/PATCH
        Route::post('leads/{id}/notes', [LeadController::class, 'addNote'])->middleware('company.data');
        Route::delete('leads/{id}', [LeadController::class, 'destroy'])->middleware(['role:admin,super_admin', 'company.data']);

        // Legacy visitor-based CRM endpoints (kept for the current dashboard; superseded by /leads)
        Route::prefix('crm')->middleware('company.data')->group(function () {
            Route::get('leads/export', [AgentChatController::class, 'exportLeads']);
            Route::get('leads', [AgentChatController::class, 'leads']);
            Route::put('leads/{id}', [AgentChatController::class, 'updateLead']);
            Route::delete('leads/{id}', [AgentChatController::class, 'deleteLead'])->middleware('role:admin,super_admin');
        });
    });

});
