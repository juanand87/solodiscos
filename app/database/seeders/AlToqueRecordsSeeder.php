<?php
namespace Database\Seeders;
use App\Models\Store;
use Illuminate\Database\Seeder;
class AlToqueRecordsSeeder extends Seeder {
    public function run(): void {
        Store::firstOrCreate(['url'=>'https://altoquerecords.cl'],[
            'name'=>'Al Toque Records','platform'=>'shopify','currency'=>'CLP',
            'interval_hours'=>48,'default_condition'=>'unknown','active'=>true,
        ]);
    }
}
