<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Models\{Store,StoreAccount,User};
class StoreIntegrationTest extends TestCase {
    use RefreshDatabase;
    private function account(string $domain='shop.example'): StoreAccount {
        $store=Store::create(['name'=>$domain,'url'=>'https://'.$domain,'platform'=>'woocommerce','active'=>true,'currency'=>'CLP']);
        $account=new StoreAccount(['name'=>'Manager','email'=>'manager@'.$domain,'password'=>'ExamplePassword123']);
        $account->store_id=$store->id;$account->email_verified_at=now();$account->save();return $account;
    }
    private function key(StoreAccount $account): string {
        $this->actingAs($account,'store')->post(route('portal.integration.rotate'))->assertRedirect();
        return session('integration_token');
    }
    private function order(array $overrides=[]): array {
        return array_replace(['external_id'=>'123','status'=>'completed','currency'=>'CLP','ordered_at'=>'2026-11-20T12:00:00Z','source_updated_at'=>'2026-11-20T13:00:00Z','paid'=>true,'total'=>'20000.00','refunded'=>'0.00','units'=>2,'refunded_units'=>0],$overrides);
    }
    public function test_keys_are_hashed_rotated_revoked_and_require_verified_store_access(): void {
        $this->get(route('portal.integration'))->assertRedirect(route('portal.login'));
        $account=$this->account();$key=$this->key($account);
        $this->assertSame(hash('sha256',$key),DB::table('store_integrations')->value('token_hash'));
        $this->assertNotSame($key,DB::table('store_integrations')->value('token_hash'));
        $new=$this->key($account);
        $this->withToken($key)->postJson('/api/v1/woocommerce/orders',$this->order())->assertUnauthorized();
        $this->withToken($new)->postJson('/api/v1/woocommerce/orders',$this->order())->assertOk();
        $this->delete(route('portal.integration.revoke'))->assertRedirect();
        $this->withToken($new)->getJson('/api/v1/woocommerce/summary')->assertUnauthorized();
        $account->forceFill(['email_verified_at'=>null])->save();
        $this->post(route('portal.integration.rotate'))->assertRedirect(route('portal.verification'));
    }
    public function test_snapshots_are_idempotent_ordered_scoped_and_only_keep_allowed_fields(): void {
        $account=$this->account();$key=$this->key($account);
        for($i=0;$i<2;$i++)$this->withToken($key)->postJson('/api/v1/woocommerce/orders',$this->order()+['email'=>'private@example.com','store_id'=>999])->assertOk();
        $this->assertDatabaseCount('store_orders',1);$this->assertDatabaseHas('store_orders',['store_id'=>$account->store_id,'total'=>20000]);
        $this->withToken($key)->postJson('/api/v1/woocommerce/orders',$this->order(['source_updated_at'=>'2026-11-21T13:00:00Z','refunded'=>5000,'refunded_units'=>1]))->assertOk();
        $this->withToken($key)->postJson('/api/v1/woocommerce/orders',$this->order())->assertOk();
        $this->assertDatabaseHas('store_orders',['refunded'=>5000,'refunded_units'=>1]);
        $other=$this->account('other.example');$otherKey=$this->key($other);
        $this->withToken($otherKey)->postJson('/api/v1/woocommerce/orders',$this->order(['total'=>90000]))->assertOk();
        $this->assertDatabaseCount('store_orders',2);
        $this->assertStringNotContainsString('private@example.com',json_encode(DB::table('store_orders')->get()));
        $response=$this->actingAs($account,'store')->get(route('portal.integration',['from'=>'2026-11-01','to'=>'2026-11-30','store_id'=>$other->store_id]))->assertOk();
        $row=$response->viewData('sales')->first();$this->assertEquals(15000,$row->net);$this->assertEquals(1,$row->units);$this->assertEquals(1,$row->orders);
    }
    public function test_validates_refunds_dates_and_quantities_and_rejects_changed_domains(): void {
        $account=$this->account();$key=$this->key($account);
        $this->withToken($key)->postJson('/api/v1/woocommerce/orders',$this->order(['refunded'=>30000,'refunded_units'=>3,'ordered_at'=>'yesterday']))->assertUnprocessable()->assertJsonValidationErrors(['refunded','refunded_units','ordered_at']);
        $account->store->update(['url'=>'https://changed.example']);
        $this->withToken($key)->postJson('/api/v1/woocommerce/orders',$this->order())->assertForbidden();
        $account->store->update(['url'=>'https://shop.example','active'=>false]);
        $this->withToken($key)->getJson('/api/v1/woocommerce/summary')->assertForbidden();
    }
    public function test_sales_separate_currencies_and_exclude_unpaid_cancelled_and_outside_period(): void {
        $account=$this->account();$key=$this->key($account);
        foreach([[],['currency'=>'USD','total'=>50],['paid'=>false],['status'=>'cancelled'],['status'=>'trash'],['ordered_at'=>'2025-01-01T12:00:00Z']] as $i=>$changes){
            $this->withToken($key)->postJson('/api/v1/woocommerce/orders',$this->order(['external_id'=>(string)$i]+$changes))->assertOk();
        }
        $response=$this->get(route('portal.integration',['from'=>'2026-11-01','to'=>'2026-11-30']))->assertOk();
        $rows=$response->viewData('sales')->keyBy('currency');$this->assertCount(2,$rows);$this->assertEquals(20000,$rows['CLP']->net);$this->assertEquals(50,$rows['USD']->net);
        $this->getJson('/api/v1/woocommerce/summary')->assertOk()->assertHeader('Cache-Control','no-store, private')->assertJsonPath('store','shop.example')->assertJsonStructure(['catalog'=>['published'],'engagement'=>['clicks','topViews','scarce']]);
    }
    public function test_admin_sales_and_plugin_download_are_protected(): void {
        $account=$this->account();$this->actingAs($account,'store');
        $this->get(route('portal.integration.download'))->assertDownload('solodiscos-woocommerce.zip');
        $this->get(route('admin.integration.sales'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(),'web')->get(route('admin.integration.sales'))->assertOk()->assertSee('shop.example');
    }
}
