<?php
namespace Database\Seeders;

use App\Models\Store;
use Illuminate\Database\Seeder;

class BillboardSeeder extends Seeder
{
    public function run(): void
    {
        Store::firstOrCreate(['url'=>'https://www.billboard.cl'],[
            'name'=>'Billboard','platform'=>'bsale','currency'=>'CLP',
            'interval_hours'=>48,'default_condition'=>'unknown','active'=>true,
        ]);
    }
}
