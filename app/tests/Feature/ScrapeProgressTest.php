<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use App\Models\{User,Store,ScrapeRun};

class ScrapeProgressTest extends TestCase {
    use RefreshDatabase;
    public function test_progress_is_private_and_read_only_and_returns_current_counters(): void {
        $this->getJson('/admin/progreso')->assertUnauthorized();
        $this->actingAs(User::factory()->create());
        $store=Store::create(['name'=>'Mi tienda','url'=>'https://example.com','platform'=>'shopify']);
        $run=ScrapeRun::create(['store_id'=>$store->id,'status'=>'running','imported'=>120,'current_page'=>2,'started_at'=>now()->subSeconds(80),'heartbeat_at'=>now()]);
        Http::preventStrayRequests();
        $response=$this->getJson('/admin/progreso')->assertOk()->assertJsonPath('active_runs',1)->assertHeader('Cache-Control','no-store, private');
        $this->assertStringContainsString('Mi tienda',$response->json('html'));
        $this->assertStringContainsString('120',$response->json('html'));
        $this->assertStringContainsString('En curso',$response->json('html'));
        $this->assertStringContainsString('1 min',$response->json('html'));
        $run->update(['imported'=>200,'status'=>'success','finished_at'=>now()]);
        $response=$this->getJson('/admin/progreso')->assertOk()->assertJsonPath('active_runs',0);
        $this->assertStringContainsString('200',$response->json('html'));
        $this->assertStringContainsString('Completada',$response->json('html'));
        Http::assertNothingSent();
        $this->get('/admin')->assertOk()->assertSee('admin-progress.js')->assertSee('data-live-dashboard',false);
    }
    public function test_active_runs_remain_visible_even_after_many_completed_runs(): void {
        $this->actingAs(User::factory()->create());$store=Store::create(['name'=>'Tienda','url'=>'https://example.com','platform'=>'shopify']);
        $run=ScrapeRun::create(['store_id'=>$store->id,'status'=>'queued']);
        for($i=0;$i<16;$i++) ScrapeRun::create(['store_id'=>$store->id,'status'=>'success']);
        $response=$this->getJson('/admin/progreso')->assertOk()->assertJsonPath('active_runs',1);
        $this->assertStringContainsString('data-run-id="'.$run->id.'"',$response->json('html'));
    }
}
