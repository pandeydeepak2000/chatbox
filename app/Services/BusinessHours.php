<?php

namespace App\Services;

use App\Models\Property;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Working hours. Default: 09:00–18:00 Asia/Kolkata, every day (AmbitionHost operating rule).
 * Company default lives in tenants.settings.working_hours; a website may override it through
 * widget_config.operating_hours (enabled/start/end/timezone). Overnight ranges are supported.
 */
class BusinessHours
{
    public const DEFAULT = ['timezone' => 'Asia/Kolkata', 'start' => '09:00', 'end' => '18:00', 'days' => [1, 2, 3, 4, 5, 6, 7]];

    public static function config(?Property $property): array
    {
        $cfg = self::DEFAULT;
        $tenantSettings = $property?->tenant?->settings ?? [];
        if (! empty($tenantSettings['working_hours']) && is_array($tenantSettings['working_hours'])) {
            $cfg = array_merge($cfg, array_intersect_key($tenantSettings['working_hours'], $cfg));
        }
        $override = $property?->widget_config['operating_hours'] ?? null;
        if (is_array($override) && ! empty($override['enabled'])) {
            $cfg = array_merge($cfg, array_filter([
                'timezone' => $override['timezone'] ?? null,
                'start' => $override['start'] ?? null,
                'end' => $override['end'] ?? null,
            ]));
        }
        if (! in_array($cfg['timezone'], timezone_identifiers_list(), true)) {
            $cfg['timezone'] = 'Asia/Kolkata';
        }

        return $cfg;
    }

    public static function isOpen(?Property $property, ?CarbonInterface $at = null): bool
    {
        $cfg = self::config($property);
        $local = CarbonImmutable::instance($at ?? now())->setTimezone($cfg['timezone']);
        if (! in_array($local->dayOfWeekIso, $cfg['days'], true)) {
            return false;
        }

        return \App\Http\Controllers\Api\WidgetChatController::isWithinHours($local->format('H:i'), $cfg['start'], $cfg['end']);
    }

    /** Minutes inside working hours between two instants (used for "30 working minutes" rules). */
    public static function workingMinutesBetween(?Property $property, CarbonInterface $from, CarbonInterface $to): int
    {
        if ($to <= $from) {
            return 0;
        }
        $cfg = self::config($property);
        $tz = $cfg['timezone'];
        $from = CarbonImmutable::instance($from)->setTimezone($tz);
        $to = CarbonImmutable::instance($to)->setTimezone($tz);

        $total = 0;
        for ($day = $from->startOfDay()->subDay(); $day <= $to; $day = $day->addDay()) {
            if (! in_array($day->dayOfWeekIso, $cfg['days'], true)) {
                continue;
            }
            [$sh, $sm] = array_map('intval', explode(':', $cfg['start']));
            [$eh, $em] = array_map('intval', explode(':', $cfg['end']));
            $winStart = $day->setTime($sh, $sm);
            $winEnd = $day->setTime($eh, $em);
            if ($winEnd <= $winStart) {
                $winEnd = $winEnd->addDay(); // overnight window
            }
            $s = max($winStart->getTimestamp(), $from->getTimestamp());
            $e = min($winEnd->getTimestamp(), $to->getTimestamp());
            if ($e > $s) {
                $total += intdiv($e - $s, 60);
            }
        }

        return $total;
    }

    /** Next moment the team is working (for honest "we'll be back at…" messages). */
    public static function nextOpening(?Property $property, ?CarbonInterface $at = null): CarbonImmutable
    {
        $cfg = self::config($property);
        $cursor = CarbonImmutable::instance($at ?? now())->setTimezone($cfg['timezone'])->startOfMinute();
        if (self::isOpen($property, $cursor)) {
            return $cursor;
        }
        // Step through whole minutes of the configured start time on the next 8 days.
        [$sh, $sm] = array_map('intval', explode(':', $cfg['start']));
        for ($d = 0; $d <= 8; $d++) {
            $candidate = $cursor->startOfDay()->addDays($d)->setTime($sh, $sm);
            if ($candidate > $cursor && self::isOpen($property, $candidate)) {
                return $candidate;
            }
        }

        return $cursor;
    }
}
