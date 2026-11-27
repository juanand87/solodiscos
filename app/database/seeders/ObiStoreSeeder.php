<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\Store;
class ObiStoreSeeder extends Seeder {
    public function run(): void { Store::firstOrCreate(['url'=>'https://obivinilos.cl'],['name'=>'Obi Vinilos','platform'=>'woocommerce','currency'=>'CLP','interval_hours'=>48,'default_condition'=>'unknown','active'=>true]); }
}
