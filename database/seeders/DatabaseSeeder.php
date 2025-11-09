<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (App::environment('local')) {
            $this->call(UserSeeder::class);
        }

        $this->call([
            FinancialEntitiesSeeder::class,
            PaymentServicesSeeder::class,
        ]);
    }
}
