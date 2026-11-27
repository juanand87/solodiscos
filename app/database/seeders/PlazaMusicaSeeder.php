<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\Store;
class PlazaMusicaSeeder extends Seeder {
    public function run(): void { Store::firstOrCreate(['url'=>'https://www.plazamusica.cl'],['name'=>'Plaza Música','platform'=>'bsale','currency'=>'CLP','interval_hours'=>48,'default_condition'=>'unknown','active'=>true]); }
}
