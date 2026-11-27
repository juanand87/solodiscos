<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth,DB,Hash,Notification,Password,URL};
use App\Models\{Album,Offer,SmtpSetting,Store,StoreAccount,StoreCorrection,User};
use App\Notifications\StorePortalNotification;

class StorePortalTest extends TestCase {
    use RefreshDatabase;
    private function store(string $host='shop.example'): Store {return Store::create(['name'=>$host,'url'=>'https://www.'.$host,'platform'=>'shopify','active'=>true,'currency'=>'CLP']);}
    private function account(Store $store,bool $verified=true): StoreAccount {
        $account=new StoreAccount(['name'=>'Store manager','email'=>'manager@'.preg_replace('/^www\./','',parse_url($store->url,PHP_URL_HOST)),'password'=>'ExamplePassword123']);
        $account->store_id=$store->id;$account->email_verified_at=$verified?now():null;$account->save();return $account;
    }
    private function smtp(): SmtpSetting {return SmtpSetting::create(['id'=>1,'enabled'=>true,'host'=>'smtp.example','port'=>587,'scheme'=>'smtp','username'=>'sender@example.com','password'=>'smtp-secret-123','from_address'=>'sender@example.com','from_name'=>'SoloDiscos']);}
    private function offer(Store $store,string $title='My product',int $price=10000,?Album $album=null): Offer {
        $album??=Album::create(['artist'=>'Artist','title'=>$title,'match_key'=>hash('sha256',$title)]);
        return Offer::create(['store_id'=>$store->id,'album_id'=>$album->id,'external_id'=>$title,'title'=>$title,'price'=>$price,'currency'=>'CLP','format'=>'cd','condition'=>'new','availability'=>'in_stock','checked_at'=>now(),'url'=>$store->url.'/disc']);
    }
    public function test_registration_verifies_domain_and_keeps_store_accounts_out_of_admin(): void {
        Notification::fake();$this->smtp();$store=$this->store();
        $this->post(route('portal.register'),['store_id'=>$store->id,'name'=>'Manager','email'=>'MANAGER@SHOP.EXAMPLE','password'=>'ExamplePassword123','password_confirmation'=>'ExamplePassword123','email_verified_at'=>now(),'role'=>'admin'])
            ->assertRedirect(route('portal.verification'));
        $account=StoreAccount::first();$this->assertNull($account->email_verified_at);$this->assertSame($store->id,$account->store_id);$this->assertTrue(Hash::check('ExamplePassword123',$account->password));
        Notification::assertSentTo($account,StorePortalNotification::class);$this->assertDatabaseCount('users',0);
        $this->get(route('portal.catalog'))->assertRedirect(route('portal.verification'));
        $this->get('/admin')->assertRedirect(route('login'));
    }
    public function test_registration_rejects_lookalike_domains_personal_email_inactive_and_unlisted_stores(): void {
        Notification::fake();$this->smtp();$store=$this->store();
        foreach(['manager@gmail.com','manager@shop.example.attacker.com','manager@evilshop.example','manager@sub.shop.example'] as $email){
            $this->post(route('portal.register'),['store_id'=>$store->id,'name'=>'Manager','email'=>$email,'password'=>'ExamplePassword123','password_confirmation'=>'ExamplePassword123'])->assertSessionHasErrors('email');
        }
        $store->update(['active'=>false]);
        $this->post(route('portal.register'),['store_id'=>$store->id,'name'=>'Manager','email'=>'manager@shop.example','password'=>'ExamplePassword123','password_confirmation'=>'ExamplePassword123'])->assertSessionHasErrors('email');
        $payload=['store_id'=>9999,'name'=>'Manager','email'=>'manager@other.example','password'=>'ExamplePassword123','password_confirmation'=>'ExamplePassword123'];
        $this->post(route('portal.register'),$payload)->assertStatus(429);
        $this->travel(61)->minutes();$this->post(route('portal.register'),$payload)->assertSessionHasErrors('store_id');$this->travelBack();
        $this->assertDatabaseCount('store_accounts',0);Notification::assertNothingSent();
    }
    public function test_registration_stays_closed_until_real_mail_is_configured(): void {
        $store=$this->store();$this->get(route('portal.register'))->assertOk()->assertSee('El registro estará disponible');
        $this->post(route('portal.register'),['store_id'=>$store->id,'name'=>'Manager','email'=>'manager@shop.example','password'=>'ExamplePassword123','password_confirmation'=>'ExamplePassword123'])->assertSessionHasErrors('email');
        $this->assertDatabaseCount('store_accounts',0);
    }
    public function test_verification_requires_valid_signature_account_hash_and_unexpired_link(): void {
        $account=$this->account($this->store(),false);$this->actingAs($account,'store');
        $valid=URL::temporarySignedRoute('portal.verify',now()->addMinutes(60),['id'=>$account->id,'hash'=>sha1($account->email)]);
        $this->get(route('portal.verify',[$account->id,sha1($account->email)]))->assertForbidden();
        $wrong=URL::temporarySignedRoute('portal.verify',now()->addMinutes(60),['id'=>$account->id,'hash'=>sha1('other@example.com')]);$this->get($wrong)->assertForbidden();
        $this->travel(61)->minutes();$this->get($valid)->assertForbidden();$this->travelBack();
        $this->assertNull($account->fresh()->email_verified_at);$this->get($valid)->assertRedirect(route('portal.dashboard'));$this->assertNotNull($account->fresh()->email_verified_at);
        $this->get(route('portal.dashboard'))->assertOk()->assertHeader('Cache-Control','no-store, private');
    }
    public function test_catalog_is_scoped_even_with_a_forged_store_parameter_and_corrections_cannot_target_other_stores(): void {
        $store=$this->store();$other=$this->store('other.example');$own=$this->offer($store);$foreign=$this->offer($other,'Private foreign product');$account=$this->account($store);
        $this->actingAs($account,'store')->get(route('portal.catalog',['store_id'=>$other->id]))->assertOk()->assertSee($own->title)->assertDontSee($foreign->title)->assertDontSee('Correo SMTP');
        $this->get(route('portal.correction',$foreign))->assertNotFound();$this->post(route('portal.correction',$foreign),['field'=>'format','message'=>'Incorrect format'])->assertNotFound();
        $this->post(route('portal.correction',$own),['field'=>'format','message'=>'The format should be vinyl','store_id'=>$other->id,'status'=>'resolved'])->assertRedirect(route('portal.corrections'));
        $correction=StoreCorrection::first();$this->assertSame($store->id,$correction->store_id);$this->assertSame('pending',$correction->status);$this->assertSame('cd',$own->fresh()->format);
        $this->post(route('portal.correction',$own),['field'=>'format','message'=>'Duplicate request'])->assertSessionHasErrors('message');$this->assertDatabaseCount('store_corrections',1);
    }
    public function test_inactive_or_changed_domain_revokes_access_and_admin_account_does_not_enter_portal(): void {
        $store=$this->store();$account=$this->account($store);$this->actingAs($account,'store');$store->update(['url'=>'https://changed.example']);$this->get(route('portal.catalog'))->assertForbidden();
        $store->update(['url'=>'https://shop.example','active'=>false]);$this->get(route('portal.dashboard'))->assertForbidden();Auth::guard('store')->logout();
        $admin=User::factory()->create();$this->actingAs($admin,'web')->get(route('portal.dashboard'))->assertRedirect(route('portal.login'));
    }
    public function test_statistics_ignore_other_store_and_respect_dates_and_format(): void {
        $store=$this->store();$other=$this->store('other.example');$account=$this->account($store);$own=$this->offer($store);$foreign=$this->offer($other,'Foreign statistic');
        foreach([[$own,2],[$foreign,99]] as [$offer,$units])DB::table('offer_events')->insert(['event_key'=>'sale-'.$offer->id,'offer_id'=>$offer->id,'store_id'=>$offer->store_id,'type'=>'possible_sale','occurred_at'=>now(),'availability'=>'in_stock','initial_load'=>false,'historical'=>false,'units'=>$units,'stock_before'=>3,'stock_after'=>1]);
        $response=$this->actingAs($account,'store')->get(route('portal.statistics',['store_id'=>$other->id,'from'=>now()->toDateString(),'to'=>now()->toDateString(),'format'=>'cd']))->assertOk()->assertDontSee($foreign->title);
        $this->assertEquals(2,$response->viewData('counts')['possible_sale']);
        $this->assertCount(0,$this->get(route('portal.statistics',['format'=>'vinyl']))->viewData('topSales'));
        $this->assertCount(0,$this->get(route('portal.statistics',['from'=>now()->subDays(10)->toDateString(),'to'=>now()->subDays(5)->toDateString()]))->viewData('events')->items());
    }
    public function test_prices_deduplicate_own_offers_include_ties_and_separate_condition(): void {
        $store=$this->store();$other=$this->store('other.example');$own=$this->offer($store);$this->offer($store,'Duplicate own',11000,$own->album);$foreign=$this->offer($other,'Foreign price',9000,$own->album);
        $this->actingAs($this->account($store),'store');$result=$this->get(route('portal.prices'))->assertOk()->viewData('products');$this->assertSame(1,$result->total());$this->assertEquals(10000,$result->first()->price);
        $foreign->update(['price'=>10000]);$this->assertSame(0,$this->get(route('portal.prices'))->viewData('products')->total());$this->assertSame(1,$this->get(route('portal.prices',['mode'=>'best']))->viewData('products')->total());
        $foreign->update(['condition'=>'used']);$this->assertSame(0,$this->get(route('portal.prices',['mode'=>'best']))->viewData('products')->total());
    }
    public function test_only_admin_can_configure_smtp_password_is_encrypted_and_not_returned_or_flashed(): void {
        $account=$this->account($this->store());$this->actingAs($account,'store')->get(route('admin.smtp'))->assertRedirect(route('login'));
        $admin=User::factory()->create();$this->actingAs($admin,'web');$payload=['enabled'=>1,'host'=>'smtp.example','port'=>587,'scheme'=>'smtp','username'=>'sender','password'=>'smtp-secret-123','from_address'=>'sender@example.com','from_name'=>'SoloDiscos'];
        $this->put(route('admin.smtp.save'),$payload)->assertRedirect();$this->assertNotSame('smtp-secret-123',DB::table('smtp_settings')->value('password'));$this->assertSame('smtp-secret-123',SmtpSetting::first()->password);
        $this->get(route('admin.smtp'))->assertOk()->assertDontSee('smtp-secret-123');$payload['password']='';$this->put(route('admin.smtp.save'),$payload)->assertRedirect();$this->assertSame('smtp-secret-123',SmtpSetting::first()->password);
        $payload['port']=999999;$payload['password']='must-not-be-flashed';$this->put(route('admin.smtp.save'),$payload)->assertSessionHasErrors('port');$this->assertArrayNotHasKey('password',session()->getOldInput());
    }
    public function test_admin_can_answer_own_request_and_response_visible_only_to_its_store(): void {
        $store=$this->store();$account=$this->account($store);$offer=$this->offer($store);$correction=StoreCorrection::create(['store_id'=>$store->id,'store_account_id'=>$account->id,'offer_id'=>$offer->id,'field'=>'format','message'=>'Please correct format']);
        $this->actingAs($account,'store')->put(route('admin.corrections.review',$correction),['status'=>'resolved','response'=>'Should not work'])->assertRedirect(route('login'));
        $admin=User::factory()->create();$this->actingAs($admin,'web')->get(route('admin.corrections'))->assertOk()->assertSee('Please correct format');
        $this->put(route('admin.corrections.review',$correction),['status'=>'resolved','response'=>'Format reviewed and corrected'])->assertRedirect();$this->assertSame($admin->id,$correction->fresh()->reviewed_by);
        $this->put(route('admin.corrections.review',$correction),['status'=>'rejected','response'=>'Overwrite answer'])->assertStatus(409);
        $this->actingAs($account,'store')->get(route('portal.corrections'))->assertOk()->assertSee('Format reviewed and corrected');
        $other=$this->account($this->store('other.example'));$this->actingAs($other,'store')->get(route('portal.corrections'))->assertOk()->assertDontSee('Please correct format');
    }
    public function test_reset_token_is_required_and_does_not_touch_admin_password(): void {
        $store=$this->store();$account=$this->account($store);$admin=User::factory()->create(['email'=>$account->email]);$oldAdmin=$admin->password;
        $token=Password::broker('store_accounts')->createToken($account);
        $this->post(route('portal.password.update'),['email'=>$account->email,'token'=>'invalid','password'=>'NewPassword4567','password_confirmation'=>'NewPassword4567'])->assertSessionHasErrors('email');
        $this->post(route('portal.password.update'),['email'=>$account->email,'token'=>$token,'password'=>'NewPassword4567','password_confirmation'=>'NewPassword4567'])->assertRedirect(route('portal.login'));
        $this->assertTrue(Hash::check('NewPassword4567',$account->fresh()->password));$this->assertSame($oldAdmin,$admin->fresh()->password);
    }
    public function test_verification_message_uses_portal_route_and_smtp_requires_tls(): void {
        $this->smtp();$account=$this->account($this->store(),false);app(\App\Services\PortalMail::class)->configure();
        $config=config('mail.mailers.store_portal');$this->assertTrue($config['require_tls']);$this->assertSame('smtp',$config['transport']);
        $message=(new StorePortalNotification)->toMail($account);$this->assertStringContainsString('/tiendas/verificar/'.$account->id.'/',$message->actionUrl);$this->assertStringContainsString('signature=',$message->actionUrl);
        $message=(new StorePortalNotification('sample-token'))->toMail($account);$this->assertStringContainsString('/tiendas/recuperar/sample-token',$message->actionUrl);
    }
    public function test_smtp_failure_does_not_verify_the_account_or_expose_transport_details(): void {
        $account=$this->account($this->store(),false);$this->actingAs($account,'store');
        $this->mock(\App\Services\PortalMail::class,function($mock){$mock->shouldReceive('verify')->andThrow(new \RuntimeException('smtp-secret-should-not-appear'));});
        $this->post(route('portal.resend'))->assertRedirect(route('portal.verification'))->assertSessionHasErrors('email');
        $this->get(route('portal.verification'))->assertOk()->assertDontSee('smtp-secret-should-not-appear');$this->assertNull($account->fresh()->email_verified_at);
    }
    public function test_requested_policy_sections_are_removed_and_portal_explanation_is_present(): void {
        $this->get(route('policies.show','transparencia'))->assertOk()->assertDontSee('Actualización e independencia')->assertDontSee('Las bajas de stock son indicios');
        $this->get(route('policies.show','tiendas'))->assertOk()->assertDontSee('Los nombres, logos, portadas y descripciones')->assertDontSee('Alcance de una recomendación')->assertDontSee('Información útil para una corrección')->assertSee('Correcciones desde el portal de tiendas');
    }
}
