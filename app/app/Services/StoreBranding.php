<?php
namespace App\Services;
use App\Models\Store;
use DOMDocument;
use DOMXPath;
use GuzzleHttp\Psr7\{Uri,UriResolver};
class StoreBranding {
    public function __construct(private StoreClient $client) {}
    public function discover(Store $store): ?string {
        $store->update(['logo_checked_at'=>now()]);
        $logo=$this->extract($this->client->html($store->url),$store->url);
        if ($logo) $store->update(['logo_url'=>$logo]);
        return $logo;
    }
    public function extract(string $html,string $base): ?string {
        $dom=new DOMDocument();
        $previous=libxml_use_internal_errors(true);
        try { $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html,LIBXML_NONET); }
        finally { libxml_clear_errors();libxml_use_internal_errors($previous); }
        $xpath=new DOMXPath($dom); $candidates=[];
        foreach ($xpath->query('//img') as $image) {
            $hint=strtolower($image->getAttribute('class').' '.$image->getAttribute('src').' '.$image->getAttribute('alt'));
            if (!str_contains($hint,'logo')) continue;
            $score=10+(str_contains($hint,'default-logo')?5:0)+(str_contains($hint,'custom-logo')?5:0);
            if (preg_match('/white|blanco|light-version/i',$hint)) $score-=8;
            $candidates[]=['url'=>$image->getAttribute('src')?:$image->getAttribute('data-src'),'score'=>$score];
        }
        foreach ($xpath->query('//link[@href]') as $link) {
            if (str_contains(strtolower($link->getAttribute('rel')),'icon')) $candidates[]=['url'=>$link->getAttribute('href'),'score'=>1];
        }
        usort($candidates,fn($a,$b)=>$b['score']<=>$a['score']);
        foreach ($candidates as $candidate) {
            if (!$candidate['url']) continue;
            try { $url=(string)UriResolver::resolve(new Uri(rtrim($base,'/').'/'),new Uri(html_entity_decode($candidate['url'],ENT_QUOTES|ENT_HTML5))); }
            catch (\Throwable) { continue; }
            if (in_array(parse_url($url,PHP_URL_SCHEME),['http','https'])) return $url;
        }
        return null;
    }
}
