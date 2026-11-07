<?php

namespace App\Support;

use App\Models\Property;
use Illuminate\Http\Request;

/**
 * Domain whitelist enforcement for the public widget API (audit H1).
 *
 * Only the browser-controlled Origin header (or Referer as fallback) is trusted;
 * any "origin" value in the request body is ignored.
 */
class WidgetOrigin
{
    public static function fromRequest(Request $request): ?string
    {
        $origin = $request->headers->get('Origin');
        if ($origin && $origin !== 'null') {
            return $origin;
        }

        return $request->headers->get('Referer') ?: null;
    }

    /**
     * @param  bool  $required  true for widget init: when the website has a whitelist,
     *                          a request without any origin information is refused.
     */
    public static function allows(Request $request, Property $property, bool $required = false): bool
    {
        if (empty($property->allowed_domains)) {
            return true;
        }

        $origin = self::fromRequest($request);
        if ($origin === null) {
            return ! $required;
        }

        return $property->isOriginAllowed($origin);
    }
}
