<?php
namespace App\Services;
use App\Models\Offer;
use DOMDocument;
use DOMXPath;

class StockAvailabilityVerifier {
    public function __construct(private StoreClient $client) {}
    public function verify(Offer $offer): ?string {
        $store=$offer->store;
        if($store->platform==='woocommerce'){
            $path=config('scraping.woocommerce_endpoints',[])[parse_url($store->url,PHP_URL_HOST)]??'/wp-json/wc/store/v1/products';
            $p=$this->client->get($store->url,$path.'/'.rawurlencode($offer->external_id),[],false);
            if((string)($p['id']??'')!==$offer->external_id || !is_bool($p['is_in_stock']??null))return null;
            if($p['is_on_backorder']??false)return 'backorder';
            if(!$p['is_in_stock'])return 'out_of_stock';
            return ($p['is_purchasable']??true)?'in_stock':null;
        }
        $path=parse_url($offer->url,PHP_URL_PATH);
        if(!$path || !str_starts_with($path,'/'))return null;
        if($store->platform==='shopify'){
            if(!str_starts_with($path,'/products/'))return null;
            $p=$this->client->get($store->url,rtrim($path,'/').'.js',[],false);
            foreach($p['variants']??[] as $v)if((string)($v['id']??'')===$offer->external_id && is_bool($v['available']??null))return $v['available']?'in_stock':'out_of_stock';
            return null;
        }
        return $this->fromProductSchema($this->client->html($store->url,$path,[],false),$offer->url);
    }
    public function fromProductSchema(string $html,string $url): ?string {
        $dom=new DOMDocument;$old=libxml_use_internal_errors(true);
        try{$dom->loadHTML('<?xml encoding="utf-8">'.$html,LIBXML_NONET);}finally{libxml_clear_errors();libxml_use_internal_errors($old);}
        $xpath=new DOMXPath($dom);$products=[];
        foreach($xpath->query('//script[@type="application/ld+json"]') as $script){
            $data=json_decode($script->textContent,true);if(!is_array($data))continue;
            $nodes=array_is_list($data)?$data:[$data];foreach($data['@graph']??[] as $node)$nodes[]=$node;
            foreach($nodes as $node){$types=(array)($node['@type']??[]);if(in_array('Product',$types,true))$products[]=$node;}
        }
        $path=fn($value)=>rtrim(rawurldecode(parse_url((string)$value,PHP_URL_PATH)??''),'/');$target=$path($url);$states=[];
        foreach($products as $product){
            $offers=$product['offers']??[];if(!is_array($offers))continue;$offers=array_is_list($offers)?$offers:[$offers];
            foreach($offers as $offer){
                $own=$offer['url']??($product['url']??null);if(!is_string($own) || $path($own)!==$target || !is_string($offer['availability']??null))continue;
                $host=parse_url($own,PHP_URL_HOST);$targetHost=preg_replace('/^www\./i','',parse_url($url,PHP_URL_HOST)??'');
                if($host && strcasecmp(preg_replace('/^www\./i','',$host),$targetHost)!==0)continue;
                $state=match(basename($offer['availability'])){'InStock'=>'in_stock','OutOfStock','SoldOut'=>'out_of_stock','BackOrder'=>'backorder','PreOrder','PreSale'=>'preorder',default=>null};
                if($state)$states[$state]=true;
            }
        }
        return count($states)===1?array_key_first($states):null;
    }
}
