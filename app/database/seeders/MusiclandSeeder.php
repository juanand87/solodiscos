<?php
namespace Database\Seeders;

use App\Models\Store;
use Illuminate\Database\Seeder;

class MusiclandSeeder extends Seeder
{
    public function run(): void
    {
        Store::firstOrCreate(['url' => 'https://musicland.cl'], [
            'name' => 'Musicland',
            'platform' => 'woocommerce',
            'currency' => 'CLP',
            'interval_hours' => 48,
            'default_condition' => 'unknown',
            'active' => true,
        ]);
    }
}
