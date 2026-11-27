<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\QueueWorkerLauncher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class QueueWorkerButtonTest extends TestCase {
    use RefreshDatabase;
    public function test_button_requires_authentication_and_reports_a_confirmed_start(): void {
        $this->post(route('admin.queue.start'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('admin'))->assertOk()->assertSee('Procesar pendientes')->assertSee('data-worker-launch',false);
        $launcher=\Mockery::mock(QueueWorkerLauncher::class);$launcher->shouldReceive('start')->once()->andReturn('started');$this->app->instance(QueueWorkerLauncher::class,$launcher);
        $this->post(route('admin.queue.start'))->assertRedirect(route('admin'))->assertSessionHas('status','Trabajador iniciado. Los trabajos pendientes se procesarán y verás su avance aquí.');
    }
    public function test_hosting_rejection_is_shown_without_reporting_success(): void {
        $this->actingAs(User::factory()->create());
        $launcher=\Mockery::mock(QueueWorkerLauncher::class);$launcher->shouldReceive('start')->once()->andThrow(new RuntimeException('El hosting bloquea procesos. Usa cron.'));$this->app->instance(QueueWorkerLauncher::class,$launcher);
        $this->post(route('admin.queue.start'))->assertRedirect(route('admin'))->assertSessionHasErrors('queue')->assertSessionMissing('status');
    }
    public function test_an_existing_worker_is_not_launched_again(): void {
        $lock=fopen(storage_path('framework/queue-worker.lock'),'c');$this->assertTrue(flock($lock,LOCK_EX|LOCK_NB));
        try {$this->assertSame('running',app(QueueWorkerLauncher::class)->start());}
        finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    public function test_empty_queue_does_not_launch_a_process(): void {
        Queue::shouldReceive('size')->once()->andReturn(0);
        $launcher=\Mockery::mock(QueueWorkerLauncher::class)->makePartial()->shouldAllowMockingProtectedMethods();$launcher->shouldNotReceive('launchProcess');
        $this->assertSame('empty',$launcher->start());
    }
    public function test_launcher_requires_confirmation_from_the_started_worker(): void {
        Queue::shouldReceive('size')->once()->andReturn(1);
        $launcher=\Mockery::mock(QueueWorkerLauncher::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $launcher->shouldReceive('phpBinary')->once()->andReturn(PHP_BINARY);
        $launcher->shouldReceive('launchProcess')->once()->andReturnUsing(function($php,$token){
            $this->assertSame(PHP_BINARY,$php);$this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/',$token);
            file_put_contents(storage_path('framework/queue-worker-start.json'),json_encode(['token'=>$token,'started_at'=>date(DATE_ATOM)]));
        });
        $this->assertSame('started',$launcher->start());
    }
    public function test_invalid_php_path_fails_before_launching_a_process(): void {
        Queue::shouldReceive('size')->once()->andReturn(1);config(['scraping.cli_php'=>storage_path('missing-php-binary')]);
        $this->expectException(RuntimeException::class);$this->expectExceptionMessage('No se encontró PHP');app(QueueWorkerLauncher::class)->start();
    }
    public function test_authenticated_requests_are_throttled(): void {
        $this->actingAs(User::factory()->create());
        $launcher=\Mockery::mock(QueueWorkerLauncher::class);$launcher->shouldReceive('start')->times(3)->andReturn('empty');$this->app->instance(QueueWorkerLauncher::class,$launcher);
        for($i=0;$i<3;$i++)$this->post(route('admin.queue.start'))->assertRedirect(route('admin'));
        $this->post(route('admin.queue.start'))->assertStatus(429);
    }
}
