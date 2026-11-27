<?php
namespace App\Services;
use DOMDocument;
use DOMXPath;
use GuzzleHttp\Psr7\{Uri,UriResolver};
use RuntimeException;

class BsaleCatalog {
    public const COLLECTIONS=['vinilos','cds'];
    public function __construct(private StoreClient $client) {}
    public function collections(string $base): array {
        return config('scraping.bsale_collections',[])[parse_url($base,PHP_URL_HOST)]??self::COLLECTIONS;
    }
    public function page(string $base,int $collection,int $page): array {
        $slug=$this->collections($base)[$collection]??throw new RuntimeException('Colección Bsale no reconocida.');
        return $this->parse($this->client->html($base,'/collection/'.$slug,['limit'=>100,'page'=>$page,'order'=>'id','way'=>'DESC']),$base,$slug,$page);
    }
    public function parse(string $html,string $base,string $collection,int $page=1): array {
        $dom=new DOMDocument(); $previous=libxml_use_internal_errors(true);
        try { $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html,LIBXML_NONET); }
        finally { libxml_clear_errors();libxml_use_internal_errors($previous); }
        $xpath=new DOMXPath($dom); $node=$xpath->query('//script[@id="coll_data_base" and @type="application/json"]')->item(0);
        $data=$node?json_decode($node->textContent,true):null;
        if (!$node) {
            // Older Bsale themes wrap a JSON object in a push call. Decode only
            // the JSON literal; never evaluate JavaScript from supplier pages.
            foreach ($xpath->query('//script[not(@src)]') as $script) {
                if (preg_match('/^\s*window\.INIT\.collections\.push\(\s*(\{.*\})\s*\);?\s*$/s',$script->textContent,$match)) {
                    $data=json_decode($match[1],true);
                    break;
                }
            }
        }
        if (!is_array($data) || !isset($data['items']) || !is_array($data['items']) || !array_is_list($data['items'])) throw new RuntimeException('No se encontró el catálogo estructurado Bsale. Requiere adaptar el conector; no se retiraron productos.');
        $next=$xpath->query('//link[@rel="next"]')->item(0);$hasNext=false;
        if ($next) {
            $url=(string)UriResolver::resolve(new Uri($base),new Uri($next->getAttribute('href')));parse_str(parse_url($url,PHP_URL_QUERY)??'',$query);
            if (strtolower(parse_url($url,PHP_URL_HOST)??'')!==strtolower(parse_url($base,PHP_URL_HOST)??'') || (int)($query['page']??0)!==$page+1 || parse_url($url,PHP_URL_PATH)!=='/collection/'.$collection) throw new RuntimeException('Paginación Bsale inesperada; se conservó el catálogo anterior.');
            $hasNext=true;
        }
        $products=[];$skipped=0;
        foreach($data['items'] as $item) {
            if (empty($item['productId']) || empty($item['variantId']) || trim($item['title']??'')==='' || empty($item['link']) || rtrim($item['link'],'/')==='/product') { $skipped++; continue; }
            $title=html_entity_decode($item['title'],ENT_QUOTES|ENT_HTML5,'UTF-8');
            $type='';if(preg_match('/^(VINILOS?|VINYL|CDS?|LP|LIBROS?|CASSETTE|DVD)\b/iu',$title,$match)) $type=$match[1];
            $name=preg_replace('/^(?:VINILOS?|VINYL|CDS?|LP)\s+/iu','',$title);
            $parts=explode('/',str_ireplace('AC/DC','AC∕DC',$name),2);
            $artist=count($parts)===2?trim(str_replace('∕','/',$parts[0])):null;
            $album=count($parts)===2?trim($parts[1]):null;
            $stock='unknown';
            if (array_key_exists('totalStock',$item) && is_numeric($item['totalStock']) && (int)($item['stockControl']??0)===1) {
                $stock=$item['totalStock']>0?'in_stock':(!empty($item['allowNegativeStock'])?'backorder':'out_of_stock');
            }
            $description=$item['description']??'';
            foreach($item['descriptions']??[] as $detail) if (!empty($detail['html'])) $description.="\n".$detail['html'];
            $price=$item['finalPrice']??$item['final_price']??null;
            $url=(string)UriResolver::resolve(new Uri(rtrim($base,'/').'/'),new Uri($item['link']));
            if (parse_url($url,PHP_URL_HOST)!==parse_url($base,PHP_URL_HOST)) throw new RuntimeException('El catálogo Bsale entregó un producto de otro dominio.');
            $source=['product_type'=>$type,'collection'=>$collection,'product_id'=>$item['productId'],'variant_id'=>$item['variantId'],
                'total_stock'=>$item['totalStock']??null,'stock_control'=>$item['stockControl']??null,'allow_negative_stock'=>$item['allowNegativeStock']??null];
            $source['sku']=$item['sku']??$item['code']??null;
            $source['barcode']=$item['barcode']??$item['barCode']??null;
            if($artist && $album) {$source['artist']=$artist;$source['album']=$album;}
            $products[]=['id'=>(string)$item['variantId'],'title'=>$title,'description'=>$description,'price'=>is_numeric($price)?$price:null,'url'=>$url,
                'image'=>$item['defaultImage']??null,'availability'=>$stock,'metadata'=>($item['notice']??''),'source'=>$source];
        }
        return ['products'=>$products,'has_next'=>$hasNext,'collection'=>$collection,'skipped'=>$skipped];
    }
}
