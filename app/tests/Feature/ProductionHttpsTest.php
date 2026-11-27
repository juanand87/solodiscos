<?php
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionHttpsTest extends TestCase {
    use RefreshDatabase;

    public function test_production_redirects_http_login_to_the_configured_https_origin(): void {
        $this->app['env']='production';config(['app.url'=>'https://www.solodiscos.cl','session.secure'=>true]);
        $this->get('http://www.solodiscos.cl/login?from=admin')->assertStatus(307)->assertRedirect('https://www.solodiscos.cl/login?from=admin');
        $this->post('http://www.solodiscos.cl/login',['email'=>'test@example.com'])->assertStatus(307)->assertRedirect('https://www.solodiscos.cl/login');
        $this->assertDatabaseCount('users',0);
    }
    public function test_secure_login_is_rendered_without_a_redirect_loop(): void {
        $this->app['env']='production';config(['app.url'=>'https://www.solodiscos.cl','session.secure'=>true]);
        $this->get('https://www.solodiscos.cl/login')->assertOk()->assertSee('name="_token"',false)->assertSee('action="https://www.solodiscos.cl/login"',false);
    }
    public function test_local_http_and_production_without_https_configuration_are_left_accessible(): void {
        $this->app['env']='local';config(['app.url'=>'https://www.solodiscos.cl']);
        $this->get('http://127.0.0.1:8000/login')->assertOk();
        $this->app['env']='production';config(['app.url'=>'http://www.solodiscos.cl']);
        $this->get('http://www.solodiscos.cl/login')->assertOk();
    }
}
