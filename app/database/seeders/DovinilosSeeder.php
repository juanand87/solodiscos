<?php
namespace Database\Seeders;

use App\Models\Store;
use Illuminate\Database\Seeder;

class DovinilosSeeder extends Seeder
{
    public function run(): void
    {
        Store::firstOrCreate(['url' => 'https://dovinilos.cl'], [
            'name' => 'Dreams on Vinyl',
            'platform' => 'woocommerce',
            'currency' => 'CLP',
            'interval_hours' => 48,
            'default_condition' => 'unknown',
            'active' => true,
        ]);
    }
}
