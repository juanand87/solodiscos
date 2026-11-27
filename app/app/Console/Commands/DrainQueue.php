<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;

class DrainQueue extends Command {
    protected $signature='queue:drain {--seconds=50 : Duración del ciclo comprobada entre trabajos; el trabajo activo termina antes de salir}';
    protected $description='Procesa trabajos pendientes para cron, sin iniciar trabajadores simultáneos';

    public function handle(): int {
        $seconds=filter_var($this->option('seconds'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>3600]]);
        if($seconds===false){$this->error('seconds debe ser un entero entre 1 y 3600.');return self::FAILURE;}
        $directory=storage_path('framework');
        if(!is_dir($directory)||!is_writable($directory)){$this->error('storage/framework debe permitir escritura al usuario de PHP.');return self::FAILURE;}
        $lock=fopen($directory.'/queue-worker.lock','c');
        if($lock===false){$this->error('No se pudo abrir el bloqueo del trabajador.');return self::FAILURE;}
        if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);$this->line('Ya hay un trabajador procesando la cola.');return self::SUCCESS;}
        try {
            // The OS releases this lock even if the host terminates PHP unexpectedly.
            return $this->call('queue:work',['--stop-when-empty'=>true,'--max-time'=>$seconds,'--sleep'=>1,'--tries'=>1,'--timeout'=>1800,'--memory'=>128]);
        } finally {
            flock($lock,LOCK_UN);fclose($lock);
        }
    }
}
