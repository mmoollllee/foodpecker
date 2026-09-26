<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Demo data comes with well-known passwords, so it is only seeded for
     * local development and tests — never on a server.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('Demo-Daten werden nur lokal angelegt (APP_ENV=local). Nichts zu tun.');

            return;
        }

        $this->call([
            DemoSeeder::class,
            ObegHohenloheSeeder::class,
        ]);
    }
}
