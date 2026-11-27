<?php
namespace App\Services;
use App\Models\Store;

class StoreDomain {
    public function host(Store $store): ?string {
        $host=strtolower((string)parse_url($store->url,PHP_URL_HOST));
        $host=preg_replace('/^www\./','',$host);
        if(!$host || !str_contains($host,'.') || filter_var($host,FILTER_VALIDATE_IP) || !preg_match('/^[a-z0-9.-]+$/D',$host))return null;
        return $host;
    }
    public function matches(Store $store,string $email): bool {
        $domain=strtolower(substr(strrchr($email,'@')?:'',1));
        return $store->active && $this->host($store)!==null && hash_equals($this->host($store),$domain);
    }
}
