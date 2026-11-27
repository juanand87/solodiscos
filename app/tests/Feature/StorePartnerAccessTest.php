<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Models\{Store,StoreAccount,Offer,Album,User};
use App\Services\{StoreClient,StorePartnerAccess};

class StorePartnerAccessTest extends TestCase {
    use RefreshDatabase;
    private function account(): StoreAccount {
        $store=Store::create(['name'=>'Partner','url'=>'https://partner.example','platform'=>'woocommerce','active'=>true,'currency'=>'CLP','default_condition'=>'unknown','interval_hours'=>48]);
        $account=new StoreAccount(['name'=>'Manager','email'=>'manager@partner.example','password'=>'ExamplePassword123']);$account->store_id=$store->id;$account->email_verified_at=now();$account->save();return $account;
    }
    private function catalog(Store $store): void {
        $other=Store::create(['name'=>'Competitor','url'=>'https://other.example','platform'=>'shopify','active'=>true,'currency'=>'CLP']);
        for($i=0;$i<33;$i++){
            $album=Album::create(['artist'=>'Artist','title'=>sprintf('Album %02d',$i),'match_key'=>'partner-'.$i]);
            foreach([[$store,20000],[$other,19000]] as [$seller,$price])Offer::create(['store_id'=>$seller->id,'album_id'=>$album->id,'external_id'=>'album-'.$i,'title'=>$album->title,'format'=>'cd','condition'=>'new','currency'=>'CLP','price'=>$price,'availability'=>'in_stock','checked_at'=>now(),'url'=>$seller->url.'/product/'.$i]);
        }
    }
    private function integration(Store $store,string $token): void {DB::table('store_integrations')->insert(['store_id'=>$store->id,'domain'=>'partner.example','token_hash'=>hash('sha256',$token),'created_at'=>now(),'updated_at'=>now()]);}
    private function sample(): void {$this->get(route('portal.prices',['page'=>2,'full_access'=>1,'commercial_agreement_active'=>1]))->assertOk()->assertSee('Muestra gratuita:')->assertViewHas('products',fn($p)=>$p->count()===5);}
    public function test_full_access_requires_both_agreement_and_successful_current_key_connection(): void {
        $account=$this->account();$store=$account->store;$this->catalog($store);$this->actingAs($account,'store');$this->sample();
        $store->update(['commercial_agreement_active'=>true]);$this->sample();
        $token=str_repeat('a',64);$this->integration($store,$token);$this->sample();
        $this->withToken($token)->postJson('/api/v1/woocommerce/orders',[])->assertUnprocessable();
        $this->assertNull(DB::table('store_integrations')->value('connected_token_hash'));$this->sample();
        $this->withToken($token)->getJson('/api/v1/woocommerce/summary')->assertOk();
        $this->assertSame(hash('sha256',$token),DB::table('store_integrations')->value('connected_token_hash'));
        $first=$this->get(route('portal.prices',['format'=>'cd','condition'=>'new']))->assertOk()->assertSee('Acceso completo · Tienda asociada')->assertDontSee('Muestra gratuita:');
        $products=$first->viewData('products');$this->assertCount(25,$products);$this->assertSame(33,$products->total());$this->assertStringContainsString('format=cd',$products->url(2));
        $second=$this->get(route('portal.prices',['page'=>2,'format'=>'cd','condition'=>'new']))->viewData('products');$this->assertCount(8,$second);$this->assertSame([],$products->pluck('album_id')->intersect($second->pluck('album_id'))->all());
        $store->update(['commercial_agreement_active'=>false]);$this->sample();
    }
    public function test_revocation_rotation_and_domain_changes_remove_full_access(): void {
        $account=$this->account();$store=$account->store;$this->catalog($store);$store->update(['commercial_agreement_active'=>true]);$this->actingAs($account,'store');
        $token=str_repeat('b',64);$this->integration($store,$token);$this->withToken($token)->getJson('/api/v1/woocommerce/summary')->assertOk();
        $this->post(route('portal.integration.rotate'))->assertRedirect();$new=session('integration_token');$this->sample();
        $this->withToken($token)->getJson('/api/v1/woocommerce/summary')->assertUnauthorized();$this->sample();
        $this->withToken($new)->getJson('/api/v1/woocommerce/summary')->assertOk();$this->assertTrue(app(StorePartnerAccess::class)->status($store->fresh())['full_access']);
        DB::table('store_integrations')->update(['domain'=>'wrong.example']);$this->assertFalse(app(StorePartnerAccess::class)->status($store->fresh())['full_access']);
        DB::table('store_integrations')->update(['domain'=>'partner.example']);$this->delete(route('portal.integration.revoke'))->assertRedirect();$this->sample();
    }
    public function test_only_admin_can_activate_agreement_and_activation_audit_is_preserved(): void {
        $account=$this->account();$store=$account->store;$data=['name'=>$store->name,'url'=>$store->url,'platform'=>$store->platform,'currency'=>'CLP','default_condition'=>'unknown','interval_hours'=>48,'active'=>1,'commercial_agreement_active'=>1,'commercial_agreement_reference'=>'Folio de prueba','commercial_agreement_signed_at'=>now()->toDateString()];
        $this->actingAs($account,'store')->put(route('stores.update',$store),$data)->assertRedirect(route('login'));$this->assertFalse($store->fresh()->commercial_agreement_active);
        $client=\Mockery::mock(StoreClient::class);$client->shouldReceive('validateUrl')->andReturn(['93.184.215.14']);$this->app->instance(StoreClient::class,$client);
        $admin=User::factory()->create();$this->actingAs($admin,'web')->get(route('stores.edit',$store))->assertOk()->assertSee('Acuerdo comercial activo');
        $this->put(route('stores.update',$store),$data)->assertSessionHasNoErrors();$store->refresh();$this->assertTrue($store->commercial_agreement_active);$this->assertSame($admin->id,$store->commercial_agreement_activated_by);$activated=$store->commercial_agreement_activated_at->toDateTimeString();
        $this->travel(2)->hours();$this->put(route('stores.update',$store),$data)->assertSessionHasNoErrors();$this->assertSame($activated,$store->fresh()->commercial_agreement_activated_at->toDateTimeString());
        $data['commercial_agreement_active']=0;$this->put(route('stores.update',$store),$data)->assertSessionHasNoErrors();$this->assertFalse($store->fresh()->commercial_agreement_active);$this->travelBack();
    }
    public function test_plan_request_is_nonbinding_scoped_and_shows_legal_identity_and_source(): void {
        $account=$this->account();$other=Store::create(['name'=>'Other','url'=>'https://other.example','platform'=>'shopify','active'=>true]);
        $this->get(route('portal.plan'))->assertRedirect(route('portal.login'));
        $this->actingAs($account,'store')->get(route('portal.plan'))->assertOk()->assertSee('4% por venta atribuida')->assertSee('76.879.846-K')->assertSee('IVA de los productos incluido')->assertSee('Borrador')->assertSee(route('portal.integration.download'));
        $this->post(route('portal.plan.request'),['store_id'=>$other->id,'commercial_agreement_active'=>1])->assertRedirect(route('portal.plan'))->assertSessionHasNoErrors();
        $this->assertNotNull($account->store->fresh()->partner_plan_requested_at);$this->assertFalse($account->store->fresh()->commercial_agreement_active);$this->assertNull($other->fresh()->partner_plan_requested_at);
    }
    public function test_store_can_terminate_immediately_without_altering_another_store_or_erasing_records(): void {
        $account=$this->account();$store=$account->store;$store->update(['commercial_agreement_active'=>true]);$this->integration($store,str_repeat('f',64));
        $other=Store::create(['name'=>'Other','url'=>'https://other.example','platform'=>'shopify','active'=>true,'commercial_agreement_active'=>true]);
        $this->actingAs($account,'store')->post(route('portal.plan.terminate'))->assertSessionHasErrors('confirm_termination');$this->assertTrue($store->fresh()->commercial_agreement_active);
        $this->post(route('portal.plan.terminate'),['confirm_termination'=>1,'store_id'=>$other->id])->assertRedirect(route('portal.plan'))->assertSessionHasNoErrors();
        $this->assertFalse($store->fresh()->commercial_agreement_active);$this->assertNotNull($store->fresh()->commercial_agreement_terminated_at);$this->assertTrue($other->fresh()->commercial_agreement_active);$this->assertNull(DB::table('store_integrations')->where('store_id',$store->id)->value('token_hash'));
        $this->withToken(str_repeat('f',64))->getJson('/api/v1/woocommerce/summary')->assertUnauthorized();
        $this->post(route('portal.plan.request'))->assertRedirect();$this->assertFalse($store->fresh()->commercial_agreement_active);
    }
}
