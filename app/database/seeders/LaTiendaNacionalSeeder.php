<?php
namespace Database\Seeders;
use App\Models\Store;
use Illuminate\Database\Seeder;
class LaTiendaNacionalSeeder extends Seeder {
    public function run(): void {
        Store::firstOrCreate(['url'=>'https://latiendanacional.cl'],[
            'name'=>'La Tienda Nacional','platform'=>'shopify','currency'=>'CLP',
            'interval_hours'=>48,'default_condition'=>'unknown','active'=>true,
        ]);
    }
}
