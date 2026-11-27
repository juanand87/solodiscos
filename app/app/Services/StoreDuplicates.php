<?php
namespace App\Services;
use App\Models\Store;

class StoreDuplicates {
    private function key(string $url): string {
        $parts=parse_url($url);
        if(!$parts || empty($parts['host']))return '';
        $host=preg_replace('/^www\./i','',strtolower($parts['host']));
        $path=rtrim($parts['path']??'','/');
        $path=preg_replace('~/(?:tienda|shop)$~i','',$path);
        $port=$parts['port']??null;
        return $host.($port && !in_array($port,[80,443])?':'.$port:'').$path;
    }
    public function find(string $url,?int $except=null): ?Store {
        $key=$this->key($url);if($key==='')return null;
        foreach(Store::orderByDesc('active')->orderBy('id')->get(['id','name','url','active']) as $store){
            if($store->id!==$except && $this->key($store->url)===$key)return $store;
        }
        return null;
    }
}
