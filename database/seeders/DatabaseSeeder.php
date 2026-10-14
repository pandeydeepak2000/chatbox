<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database idempotently:
     * Keeps ONLY Super Admin and SaluteHost company admin/agent, removing all other demo accounts.
     */
    public function run(): void
    {
        Artisan::call('chatbox:clean-accounts');
    }
}
