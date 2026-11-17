<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Never write test uploads into the real private storage folder.
        Storage::fake('local');

        // Deterministic clock. Business hours decide whether an escalation opens the real 60-second window (inside
        // 09:00-18:00 IST) or registers the ticket at once (outside), so a suite that follows the wall clock passes at
        // night and fails by day. Every test starts OUTSIDE working hours; tests about the countdown freeze their own
        // in-hours time (travelTo) exactly as before.
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-05 22:30:00', 'Asia/Kolkata'));
    }
}
