<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\CustomerAccount;
use App\Services\CustomerAuthService;
use Illuminate\Http\Request;

/**
 * Ownership check for every widget conversation request (audit H8).
 *
 *  - A conversation linked to a customer account is accessible ONLY with a valid Support PIN
 *    session of that account (X-Customer-Token). Knowing/stealing the device visitor_token is
 *    not enough, and the customer can continue it from any device after logging in.
 *  - A guest conversation (no account) is accessible only with the visitor token of the device
 *    that created it.
 */
class WidgetAccess
{
    public static function conversation(Request $request, string $conversationId, ?string $visitorToken, ?string $propertyId = null): ?Conversation
    {
        $conversation = Conversation::with(['property', 'visitor'])->find($conversationId);
        if (! $conversation || ! $conversation->property || ! $conversation->property->is_active) {
            return null;
        }
        if ($propertyId !== null && $conversation->property_id !== $propertyId) {
            return null;
        }

        if ($conversation->customer_account_id) {
            $customer = CustomerAuthService::resolve($request, $conversation->property);

            return $customer && $customer->id === $conversation->customer_account_id ? $conversation : null;
        }

        $token = (string) $visitorToken;
        if ($token === '' || ! $conversation->visitor || ! hash_equals((string) $conversation->visitor->visitor_token, $token)) {
            return null;
        }

        return $conversation;
    }

    public static function customer(Request $request, \App\Models\Property $property): ?CustomerAccount
    {
        return CustomerAuthService::resolve($request, $property);
    }
}
