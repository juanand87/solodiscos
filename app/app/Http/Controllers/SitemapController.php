<?php
namespace App\Http\Controllers;
use App\Models\Offer;
use App\Services\SeoMetadata;
use Illuminate\Support\Facades\{Cache,DB};

class SitemapController extends Controller {
    private const SIZE=1000;
    private function publications(){return Offer::available()->select('album_id','format')->groupBy('album_id','format');}
    private function xml(array $urls,bool $index=false){
        $root=$index?'sitemapindex':'urlset';$tag=$index?'sitemap':'url';
        $body='<?xml version="1.0" encoding="UTF-8"?>'.'<'.$root.' xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach($urls as $url)$body.='<'.$tag.'><loc>'.htmlspecialchars($url,ENT_XML1|ENT_QUOTES,'UTF-8').'</loc></'.$tag.'>';
        return response($body.'</'.$root.'>',200,['Content-Type'=>'application/xml; charset=UTF-8','Cache-Control'=>'public, max-age=300']);
    }
    public function index(){
        $count=Cache::remember('seo:sitemap:count',300,fn()=>DB::query()->fromSub($this->publications()->toBase(),'published')->count());
        $urls=[SeoMetadata::url('/sitemap-paginas.xml')];for($page=1;$page<=ceil($count/self::SIZE);$page++)$urls[]=SeoMetadata::url('/sitemap-discos-'.$page.'.xml');
        $artistCount=count(app(\App\Services\CatalogDiscovery::class)->artists());
        for($page=1;$page<=ceil($artistCount/self::SIZE);$page++)$urls[]=SeoMetadata::url('/sitemap-artistas-'.$page.'.xml');
        if(app(\App\Services\CatalogDiscovery::class)->stores()->isNotEmpty())$urls[]=SeoMetadata::url('/sitemap-tiendas.xml');
        return $this->xml($urls,true);
    }
    public function pages(){
        $urls=[SeoMetadata::url(),SeoMetadata::url('/rankings')];
        if(app(\App\Services\CatalogDiscovery::class)->artists())$urls[]=SeoMetadata::url('/artistas');
        if(app(\App\Services\CatalogDiscovery::class)->stores()->isNotEmpty())$urls[]=SeoMetadata::url('/tiendas-de-discos');
        foreach(['vinyl','cd'] as $format)if(Offer::available()->where('format',$format)->exists())$urls[]=SeoMetadata::url('/',['format'=>$format]);
        if(app(\App\Services\InitialPriceDiscounts::class)->query()->whereColumn('o.price','<','initial.price')->exists())$urls[]=SeoMetadata::url('/descuentos');
        foreach(array_keys(config('policies.pages')??[]) as $slug)$urls[]=SeoMetadata::url('/politicas/'.$slug);
        return $this->xml($urls);
    }
    public function albums(int $page){
        abort_if($page<1||$page>1000,404);
        $rows=$this->publications()->orderBy('album_id')->orderBy('format')->offset(($page-1)*self::SIZE)->limit(self::SIZE)->toBase()->get();
        abort_if($rows->isEmpty(),404);
        return $this->xml($rows->map(fn($row)=>SeoMetadata::url('/album/'.$row->album_id.'/'.$row->format))->all());
    }
    public function artists(int $page){
        abort_if($page<1||$page>1000,404);
        $rows=array_slice(array_values(app(\App\Services\CatalogDiscovery::class)->artists()),($page-1)*self::SIZE,self::SIZE);
        abort_if(!$rows,404);return $this->xml(array_map(fn($a)=>SeoMetadata::url($a['path']),$rows));
    }
    public function stores(){return $this->xml(app(\App\Services\CatalogDiscovery::class)->stores()->map(fn($s)=>SeoMetadata::url(app(\App\Services\CatalogDiscovery::class)->storePath($s)))->all());}
}
