<?php
namespace Database\Seeders;
use App\Models\Store;
use Illuminate\Database\Seeder;
class PuntoMusicalSeeder extends Seeder {
    public function run(): void {
        Store::firstOrCreate(['url'=>'https://puntomusical.cl'],[
            'name'=>'Punto Musical','platform'=>'woocommerce','currency'=>'CLP',
            'interval_hours'=>48,'default_condition'=>'unknown','active'=>true,
        ]);
    }
}
