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
        return array_replace(['external_id'=>'123','status'=>'completed','currency'=>'CLP','ordered_at'=>'2026-11-20T12:00:00Z','source_updated_at'=>'2026-11-20T13:00:00Z','paid'=>true,'total'=>'20000.00','refunded'=>'0.00','units'=>2,'refunded_units'=>0,'attributed_to_solodiscos'=>true],$overrides);
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
    public function test_attribution_dashboard_separates_sources_refunds_currencies_and_stores(): void {
        $account=$this->account();$key=$this->key($account);
        foreach([
            ['attributed_to_solodiscos'=>true,'refunded'=>5000],
            ['attributed_to_solodiscos'=>true,'currency'=>'USD','total'=>40],
            ['attributed_to_solodiscos'=>false],[],
            ['attributed_to_solodiscos'=>true,'paid'=>false],
            ['attributed_to_solodiscos'=>true,'status'=>'cancelled'],
            ['attributed_to_solodiscos'=>true,'ordered_at'=>'2025-01-01T12:00:00Z'],
        ] as $i=>$changes){$payload=$this->order(['external_id'=>(string)$i]+$changes);if($i===3)unset($payload['attributed_to_solodiscos']);$this->withToken($key)->postJson('/api/v1/woocommerce/orders',$payload)->assertOk();}
        $album=\App\Models\Album::create(['artist'=>'Artist','title'=>'Album','match_key'=>'attribution-test']);
        DB::table('engagement_days')->insert(['metric_key'=>'attribution-clicks','day'=>'2026-11-20','kind'=>'click','album_id'=>$album->id,'format'=>'cd','store_id'=>$account->store_id,'total'=>20]);
        $other=$this->account('other.example');$otherKey=$this->key($other);
        $this->withToken($otherKey)->postJson('/api/v1/woocommerce/orders',$this->order(['attributed_to_solodiscos'=>true,'total'=>99999]))->assertOk();
        $query='?from=2026-11-01&to=2026-11-30&store_id='.$other->store_id;
        $data=$this->withToken($key)->getJson('/api/v1/woocommerce/dashboard'.$query)->assertOk()->assertHeader('Cache-Control','no-store, private')
            ->assertJsonPath('clicks',20)->assertJsonPath('orders',2)->assertJsonPath('conversion_rate',10)->json();
        $amounts=collect($data['amounts'])->keyBy('currency');$this->assertEquals(15000,$amounts['CLP']['net']);$this->assertEquals(40,$amounts['USD']['net']);
        $this->getJson('/api/v1/woocommerce/attributed-orders'.$query)->assertOk()->assertJsonPath('total',2)->assertJsonCount(2,'data')->assertJsonMissing(['total'=>99999])->assertJsonMissingPath('data.0.store_id')->assertJsonMissingPath('data.0.token_hash');
        $this->withToken($otherKey)->getJson('/api/v1/woocommerce/dashboard'.$query)->assertOk()->assertJsonPath('conversion_rate',null);
        $this->withToken('invalid')->getJson('/api/v1/woocommerce/attributed-orders')->assertUnauthorized();
    }
    public function test_attributed_sales_paginate_and_use_the_same_chilean_calendar_as_clicks(): void {
        $account=$this->account();$key=$this->key($account);
        // 00:30 UTC on the 21st is still the 20th in Santiago.
        for($i=0;$i<22;$i++)$this->withToken($key)->postJson('/api/v1/woocommerce/orders',$this->order(['external_id'=>(string)$i,'ordered_at'=>'2026-11-21T00:30:00Z','attributed_to_solodiscos'=>true]))->assertOk();
        $this->getJson('/api/v1/woocommerce/attributed-orders?from=2026-11-20&to=2026-11-20&page=2')->assertOk()->assertJsonPath('total',22)->assertJsonPath('last_page',2)->assertJsonCount(2,'data');
        $this->getJson('/api/v1/woocommerce/dashboard?from=2026-11-21&to=2026-11-21')->assertOk()->assertJsonPath('orders',0)->assertJsonPath('conversion_rate',null);
        $this->getJson('/api/v1/woocommerce/attributed-orders?page=0')->assertUnprocessable();
        $this->getJson('/api/v1/woocommerce/dashboard?from=2026-11-21&to=2026-11-20')->assertUnprocessable();
    }
    public function test_legacy_snapshots_never_invent_or_erase_attribution(): void {
        $key=$this->key($this->account());
        $legacy=$this->order();unset($legacy['attributed_to_solodiscos']);
        $this->withToken($key)->postJson('/api/v1/woocommerce/orders',$legacy)->assertOk();
        $this->assertDatabaseCount('store_orders',0);
        $this->assertNull(DB::table('store_orders')->value('attributed_to_solodiscos'));
        $this->postJson('/api/v1/woocommerce/orders',$this->order(['attributed_to_solodiscos'=>true]))->assertOk();
        $legacy['source_updated_at']='2026-11-21T13:00:00Z';$legacy['refunded']=20000;
        $this->postJson('/api/v1/woocommerce/orders',$legacy)->assertOk();
        $this->assertEquals(1,DB::table('store_orders')->value('attributed_to_solodiscos'));
        $this->getJson('/api/v1/woocommerce/dashboard?from=2026-11-01&to=2026-11-30')->assertOk()->assertJsonPath('orders',1)->assertJsonPath('amounts.0.net',fn($value)=>(float)$value===0.0);
    }
    public function test_unrelated_orders_are_ignored_and_product_base_is_validated_and_scoped(): void {
        $account=$this->account();$key=$this->key($account);
        $this->withToken($key)->postJson('/api/v1/woocommerce/orders',$this->order(['attributed_to_solodiscos'=>false]))->assertOk();$this->assertDatabaseCount('store_orders',0);
        $data=$this->order(['refunded'=>1000,'products_total'=>18000,'products_refunded'=>1000,'commission_base'=>17000]);
        $this->postJson('/api/v1/woocommerce/orders',$data)->assertOk();$this->assertDatabaseHas('store_orders',['store_id'=>$account->store_id,'commission_base'=>17000]);
        $bad=$data;$bad['commission_base']=18000;$this->postJson('/api/v1/woocommerce/orders',$bad)->assertUnprocessable()->assertJsonValidationErrors('commission_base');
        $this->getJson('/api/v1/woocommerce/attributed-orders?from=2026-11-01&to=2026-11-30')->assertOk()->assertJsonPath('data.0.commission_base',fn($value)=>(float)$value===17000.0);
        $other=$this->account('elsewhere.example');$otherKey=$this->key($other);
        $withdraw=['external_id'=>'123','source_updated_at'=>'2026-11-21T13:00:00Z'];$this->withToken($otherKey)->postJson('/api/v1/woocommerce/orders/withdraw',$withdraw)->assertOk();
        $this->assertDatabaseHas('store_orders',['store_id'=>$account->store_id,'attributed_to_solodiscos'=>true]);
        $this->withToken($key)->postJson('/api/v1/woocommerce/orders/withdraw',$withdraw)->assertOk();$this->assertDatabaseHas('store_orders',['store_id'=>$account->store_id,'attributed_to_solodiscos'=>false]);
        $this->getJson('/api/v1/woocommerce/attributed-orders?from=2026-11-01&to=2026-11-30')->assertOk()->assertJsonPath('total',0);
    }
}
