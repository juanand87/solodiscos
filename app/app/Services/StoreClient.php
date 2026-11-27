<?php
namespace App\Services;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ExecutableFinder;
class StoreClient {
    public function validateUrl(string $url): array {
        $parts=parse_url($url);
        if (!$parts || !in_array($parts['scheme']??'', ['http','https']) || isset($parts['user']) || isset($parts['pass']) || !empty($parts['query']) || !empty($parts['fragment']) || (isset($parts['port']) && !in_array($parts['port'],[80,443]))) throw new RuntimeException('Usa una URL pública http/https sin credenciales, parámetros ni puertos especiales.');
        $host=$parts['host']??'';
        try { $records=filter_var($host,FILTER_VALIDATE_IP)?[['ip'=>$host]]:dns_get_record($host,DNS_A|DNS_AAAA); }
        catch (\Throwable $e) { throw new RuntimeException('No se pudo resolver el dominio de la tienda.',0,$e); }
        if (!$records) throw new RuntimeException('No se pudo resolver el dominio de la tienda.');
        foreach ($records as $record) {
            $ip=$record['ip']??$record['ipv6']??'';
            if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) throw new RuntimeException('La tienda debe utilizar una dirección pública.');
        }
        return array_map(fn($record)=>$record['ip']??$record['ipv6'],$records);
    }
    public function get(string $base,string $path,array $query=[],bool $retry=true): array {
        $data=json_decode($this->request($base,$path,$query,'application/json',$retry),true);
        if (!is_array($data)) throw new RuntimeException('La tienda no entregó un catálogo JSON válido.');
        return $data;
    }
    public function html(string $base,string $path='/',array $query=[],bool $retry=true): string { return $this->request($base,$path,$query,'text/html',$retry); }
    public function imageBytes(string $url,int $redirects=3): string {
        $p=parse_url($url);
        if (!$p || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || empty($p['host']) || preg_match('/[\x00-\x20]/',$url)) throw new RuntimeException('URL de portada inválida.');
        $base=($p['scheme']??'').'://'.$p['host'].(isset($p['port'])?':'.$p['port']:'');
        $this->validateUrl($base);parse_str($p['query']??'',$query);
        return $this->request($base,$p['path']??'/',$query,'image/jpeg,image/png,image/webp',false,5242880,max(0,min(3,$redirects)));
    }
    private function request(string $base,string $path,array $query,string $accept,bool $retry=true,int $maxBytes=20971520,int $imageRedirects=0): string {
        $ips=$this->validateUrl($base);
        $host=parse_url($base,PHP_URL_HOST); $port=parse_url($base,PHP_URL_PORT)??(parse_url($base,PHP_URL_SCHEME)==='https'?443:80);
        $ip=$ips[0]; $address=str_contains($ip,':')?'['.$ip.']':$ip;
        if (config('scraping.transport')==='system') return $this->systemGet($base,$path,$query,"$host:$port:$address",$accept,$retry,$maxBytes,$imageRedirects);
        for ($attempt=0;$attempt<3;$attempt++) {
            $response=Http::withHeaders(['Accept'=>$accept])->withUserAgent('SoloDiscos/1.0 catalog comparison')->connectTimeout(10)->timeout(40)
                ->withOptions(['allow_redirects'=>false,'curl'=>[CURLOPT_RESOLVE=>["$host:$port:$address"]],
                    'progress'=>function($total,$downloaded)use($maxBytes) { if($downloaded>$maxBytes)throw new RuntimeException('Respuesta demasiado grande.'); }])->get(rtrim($base,'/').$path,$query);
            if (!$retry || !in_array($response->status(),[429,502,503,504]) || $attempt===2) break;
            $delay=max(5,min(60,(int)($response->header('Retry-After')?:10)*($attempt+1)));
            sleep($delay);
        }
        if ($response->redirect()) {
            if ($imageRedirects>0 && $response->header('Location')) {
                $url=(string)\GuzzleHttp\Psr7\UriResolver::resolve(new \GuzzleHttp\Psr7\Uri(rtrim($base,'/').$path),new \GuzzleHttp\Psr7\Uri($response->header('Location')));
                return $this->imageBytes($url,$imageRedirects-1);
            }
            throw new RuntimeException('La tienda redirige. Configura su dominio final (por ejemplo con www).');
        }
        $response->throw();
        if (strlen($response->body())>$maxBytes) throw new RuntimeException('Respuesta demasiado grande.');
        return $response->body();
    }
    private function systemGet(string $base,string $path,array $query,string $resolve,string $accept,bool $retry=true,int $maxBytes=20971520,int $imageRedirects=0): string {
        $binary=(new ExecutableFinder)->find(PHP_OS_FAMILY==='Windows'?'curl.exe':'curl');
        if (!$binary) throw new RuntimeException('No se encontró cURL del sistema. Instálalo o configura SCRAPER_TRANSPORT=http.');
        $url=rtrim($base,'/').$path.($query?'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986):'');
        for ($attempt=0;$attempt<3;$attempt++) {
            $process=new Process([$binary,'--silent','--show-error','--compressed','--proto','=http,https','--connect-timeout','10','--max-time','40','--max-filesize',(string)$maxBytes,
                '--resolve',$resolve,'--user-agent','SoloDiscos/1.0 catalog comparison','--header','Accept: '.$accept,'--write-out',"\nSOLODISCOS_STATUS:%{http_code}\nSOLODISCOS_REDIRECT:%{redirect_url}",$url]);
            $process->setTimeout(45)->run();
            if (!$process->isSuccessful() && $retry && $attempt<2 && in_array($process->getExitCode(),[18,28,35,52,55,56])) {
                sleep(5*($attempt+1));continue;
            }
            if (!$process->isSuccessful()) throw new RuntimeException('Error de conexión cURL: '.mb_substr($process->getErrorOutput(),0,500));
            $output=$process->getOutput(); $marker=strrpos($output,"\nSOLODISCOS_STATUS:");
            if ($marker===false) throw new RuntimeException('Respuesta HTTP incompleta.');
            $status=(int)substr($output,$marker+strlen("\nSOLODISCOS_STATUS:"));
            if (!$retry || !in_array($status,[429,502,503,504]) || $attempt===2) break;
            sleep(10*($attempt+1));
        }
        if ($status>=300 && $status<400) {
            $redirectMarker=strrpos($output,"\nSOLODISCOS_REDIRECT:");
            if ($imageRedirects>0 && $redirectMarker!==false) {
                $target=trim(substr($output,$redirectMarker+strlen("\nSOLODISCOS_REDIRECT:")));
                if($target!=='')return $this->imageBytes($target,$imageRedirects-1);
            }
            throw new RuntimeException('La tienda redirige. Configura su dominio final.');
        }
        if ($status<200 || $status>=300) throw new RuntimeException("La tienda respondió HTTP $status. Puedes reintentar después; se conservó el progreso.");
        $body=substr($output,0,$marker);if(strlen($body)>$maxBytes)throw new RuntimeException('Respuesta demasiado grande.');return $body;
    }
}
