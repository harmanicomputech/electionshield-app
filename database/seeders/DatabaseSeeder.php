<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Nothing to seed: the PU register, agents and results all come from the
     * USSD service (System → Full import, or `php artisan ussd:sync --full`),
     * and the first admin is created at /login with the setup key.
     */
    public function run(): void
    {
        //
    }
}
