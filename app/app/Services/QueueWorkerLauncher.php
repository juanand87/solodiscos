<?php
namespace App\Services;

use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Symfony\Component\Process\{PhpExecutableFinder,Process};

class QueueWorkerLauncher {
    public function start(): string {
        $directory=storage_path('framework');
        if(!is_writable($directory))throw new RuntimeException('El servidor debe permitir escritura en storage/framework para iniciar el trabajador.');
        $launchLock=fopen($directory.'/queue-worker-launch.lock','c');
        if($launchLock===false)throw new RuntimeException('No se pudo preparar el inicio del trabajador.');
        if(!flock($launchLock,LOCK_EX|LOCK_NB)){fclose($launchLock);return 'starting';}
        try {
            if($this->workerRunning($directory))return 'running';
            if(Queue::size()===0)return 'empty';
            if(!function_exists('proc_open'))throw new RuntimeException('El hosting bloquea el inicio de procesos desde PHP. Usa el trabajador desde Terminal o configura las tareas de cron.');
            $php=$this->phpBinary();$token=bin2hex(random_bytes(16));
            $this->launchProcess($php,$token);
            $deadline=microtime(true)+3;
            do {
                $path=$directory.'/queue-worker-start.json';
                $state=is_file($path)?json_decode((string)file_get_contents($path),true):null;
                if(($state['token']??null)===$token)return 'started';
                usleep(50000);
            } while(microtime(true)<$deadline);
            if($this->workerRunning($directory))return 'running';
            throw new RuntimeException('No se pudo confirmar el inicio del trabajador. Revisa storage/logs/queue-button.log; si el hosting bloquea procesos, usa Terminal o cron.');
        } finally {flock($launchLock,LOCK_UN);fclose($launchLock);}
    }

    private function workerRunning(string $directory): bool {
        $workerLock=fopen($directory.'/queue-worker.lock','c');
        if($workerLock===false)throw new RuntimeException('No se pudo comprobar el estado del trabajador.');
        $free=flock($workerLock,LOCK_EX|LOCK_NB);
        if($free)flock($workerLock,LOCK_UN);
        fclose($workerLock);return !$free;
    }

    protected function phpBinary(): string {
        $configured=config('scraping.cli_php');
        $php=$configured ?: (new PhpExecutableFinder)->find(false);
        if(!$php || !is_file($php) || !is_executable($php))throw new RuntimeException('No se encontró PHP de línea de comandos. Configura las tareas de cron con la ruta de PHP indicada por el hosting.');
        return $php;
    }

    protected function launchProcess(string $php,string $token): void {
        $log=storage_path('logs/queue-button.log');$artisan=base_path('artisan');
        if(PHP_OS_FAMILY==='Windows'){
            $quote=fn($value)=>"'".str_replace("'","''",$value)."'";
            $arguments='@('.implode(',',array_map($quote,[$artisan,'queue:drain','--started-token='.$token])).')';
            $script='Start-Process -FilePath '.$quote($php).' -ArgumentList '.$arguments.' -WorkingDirectory '.$quote(base_path())
                .' -WindowStyle Hidden -RedirectStandardOutput '.$quote($log).' -RedirectStandardError '.$quote($log.'.error');
            $process=new Process(['powershell.exe','-NoProfile','-NonInteractive','-WindowStyle','Hidden','-Command',$script],base_path());
        } else {
            $command='nohup '.escapeshellarg($php).' '.escapeshellarg($artisan).' queue:drain --started-token='.escapeshellarg($token)
                .' >> '.escapeshellarg($log).' 2>&1 < /dev/null &';
            $process=new Process(['/bin/sh','-c',$command],base_path());
        }
        $process->setTimeout(5);
        try {$process->run();}catch(\Throwable $e){throw new RuntimeException('El servidor no permitió iniciar el trabajador en segundo plano. Usa Terminal o configura cron.',0,$e);}
        if(!$process->isSuccessful())throw new RuntimeException('El servidor rechazó el inicio del trabajador. Usa Terminal o configura cron.');
    }
}
