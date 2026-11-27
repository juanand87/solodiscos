<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\Store;
class InitialStoreSeeder extends Seeder {
    public function run(): void {
        Store::where('url','https://www.vinilospormayor.cl')->update(['url'=>'https://vinilospormayor.cl']);
        Store::firstOrCreate(['url'=>'https://vinilospormayor.cl'],['name'=>'Vinilos Por Mayor','platform'=>'shopify','currency'=>'CLP','interval_hours'=>48,'default_condition'=>'unknown','active'=>true]);
    }
}
