<?php

use App\Http\Controllers\Api\AgentChatController;
use App\Http\Controllers\Api\AuthController;
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
    Route::prefix('widget')->group(function () {
        Route::post('init', [WidgetChatController::class, 'init']);
        Route::post('start', [WidgetChatController::class, 'startConversation'])->middleware('throttle:widget-messages');
        Route::post('messages', [WidgetChatController::class, 'sendMessage'])->middleware('throttle:widget-messages');
        Route::post('typing', [WidgetChatController::class, 'typing']);
        Route::post('broadcasting/auth', [WidgetChatController::class, 'authBroadcast']);
        Route::get('faqs', [WidgetChatController::class, 'faqs']);
        Route::get('conversations/{id}/sync', [WidgetChatController::class, 'syncMessages']);
        Route::post('heartbeat', [WidgetChatController::class, 'heartbeat']);
        Route::post('leave', [WidgetChatController::class, 'leave']);
    });

    // =========================================================================
    // 2. PUBLIC AUTHENTICATION ROUTES
    // =========================================================================
    Route::prefix('auth')->middleware('throttle:auth-attempts')->group(function () {
        Route::post('register-tenant', [AuthController::class, 'registerTenant']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    });

    // =========================================================================
    // 3. PROTECTED AGENT & ADMIN ROUTES (Sanctum Authenticated)
    // =========================================================================
    Route::middleware('auth:sanctum')->group(function () {

        // Session & Profile
        Route::prefix('auth')->group(function () {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('status', [AuthController::class, 'updateStatus']);
        });

        // Team / Agent Management
        Route::get('agents', [AuthController::class, 'listAgents']);
        Route::post('agents', [AuthController::class, 'createAgent'])->middleware('role:admin,super_admin');

        // Super Admin SaaS Platform Management
        Route::get('platform/tenants', [AuthController::class, 'platformTenants'])->middleware('role:super_admin');
        Route::post('platform/tenants', [AuthController::class, 'platformCreateTenant'])->middleware('role:super_admin');
        Route::get('platform/stats', [AuthController::class, 'platformStats'])->middleware('role:super_admin');
        Route::get('platform/users', [AuthController::class, 'platformUsers'])->middleware('role:super_admin');
        Route::put('platform/users/{id}', [AuthController::class, 'platformUpdateUser'])->middleware('role:super_admin');
        Route::delete('platform/users/{id}', [AuthController::class, 'platformDeleteUser'])->middleware('role:super_admin');
        Route::post('platform/flush-cache', [AuthController::class, 'platformFlushCache'])->middleware('role:super_admin');
        Route::put('auth/profile', [AuthController::class, 'updateProfile']);

        // Properties / Websites Management
        Route::apiResource('properties', PropertyController::class)->except(['destroy']);
        Route::delete('properties/{id}', [PropertyController::class, 'destroy'])->middleware('role:admin,super_admin');
        Route::post('properties/{id}/regenerate-keys', [PropertyController::class, 'regenerateKeys'])->middleware('role:admin,super_admin');
        Route::post('properties/{id}/departments', [PropertyController::class, 'storeDepartment'])->middleware('role:admin,super_admin');
        Route::delete('departments/{id}', [PropertyController::class, 'destroyDepartment'])->middleware('role:admin,super_admin');
        Route::post('properties/{id}/canned-responses', [AgentChatController::class, 'storeCannedResponse'])->middleware('role:admin,super_admin');
        Route::delete('canned-responses/{id}', [AgentChatController::class, 'destroyCannedResponse'])->middleware('role:admin,super_admin');
        Route::get('properties/{id}/faqs', [PropertyController::class, 'listFaqs']);
        Route::post('properties/{id}/faqs', [PropertyController::class, 'storeFaq'])->middleware('role:admin,super_admin');
        Route::put('faqs/{id}', [PropertyController::class, 'updateFaq'])->middleware('role:admin,super_admin');
        Route::delete('faqs/{id}', [PropertyController::class, 'destroyFaq'])->middleware('role:admin,super_admin');

        // Real-Time Chat Workspace (Live Inbox)
        Route::prefix('chat')->group(function () {
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
            Route::get('canned-responses', [AgentChatController::class, 'cannedResponses']);
        });

        // CRM / Leads Management
        Route::prefix('crm')->group(function () {
            Route::get('leads', [AgentChatController::class, 'leads']);
            Route::put('leads/{id}', [AgentChatController::class, 'updateLead']);
            Route::delete('leads/{id}', [AgentChatController::class, 'deleteLead'])->middleware('role:admin,super_admin');
        });
    });

});
