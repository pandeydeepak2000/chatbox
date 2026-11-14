<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $channels = DB::table('notification_channels')->get();
        foreach ($channels as $channel) {
            $events = json_decode($channel->events ?? '[]', true) ?: [];
            $modified = false;
            if (! in_array('chat.started', $events, true)) {
                $events[] = 'chat.started';
                $modified = true;
            }
            if (! in_array('tickets.reminders', $events, true)) {
                $events[] = 'tickets.reminders';
                $modified = true;
            }
            if ($modified) {
                DB::table('notification_channels')->where('id', $channel->id)->update([
                    'events' => json_encode(array_values(array_unique($events))),
                ]);
            }
        }
    }

    public function down(): void
    {
    }
};
