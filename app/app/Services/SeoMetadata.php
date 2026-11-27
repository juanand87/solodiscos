<?php
namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SeoMetadata {
    public static function url(string $path='/',array $query=[]): string {
        return rtrim(config('app.url'),'/').'/'.ltrim($path,'/').($query?'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986):'');
    }
    public function build(Request $r,array $data=[]): array {
        $title=null;$description='Encuentra CDs y vinilos y compara precios entre tiendas de Chile. Elige dónde comprar tu próximo disco con SoloDiscos.';
        $canonical=null;$index=false;$image=self::url('/images/vinyl-editorial.jpg');$graph=[];
        $route=$r->route()?->getName();$page=max(1,(int)$r->query('page',1));$query=[];
        if($route==='catalog'){
            $format=in_array($r->query('format'),['cd','vinyl'])?$r->query('format'):null;
            $title=match($format){'cd'=>'CDs en Chile: compara precios y tiendas | SoloDiscos','vinyl'=>'Vinilos en Chile: compara precios y tiendas | SoloDiscos',default=>'SoloDiscos | Compara precios de CDs y vinilos en Chile'};
            if($format)$query['format']=$format;if($page>1)$query['page']=$page;
            $description=$format==='cd'?'Compara precios de CDs nuevos y usados en tiendas de Chile. Explora álbumes y alternativas de compra en SoloDiscos.':($format==='vinyl'?'Encuentra vinilos nuevos y usados en tiendas de Chile. Compara precios del mismo álbum y revisa las alternativas de cada vendedor.':$description);
            $index=!$r->filled('q')&&!$r->filled('condition')&&!$r->filled('sort')&&(int)$r->query('per_page',25)===25;
            if($r->filled('format')&&!$format)$index=false;
            if(isset($data['publications'])&&$data['publications']->isEmpty()&&($page>1||$format))$index=false;
            if($index)$canonical=self::url('/',$query);
            if($page>1)$title='Página '.$page.' · '.$title;
        }elseif($route==='album' && ($album=$data['album']??null)){
            $format=$data['format']==='cd'?'CD':'Vinilo';$artist=preg_replace('/^\[(?:vinilo|cd)\]\s*/iu','',$album->artist);$name=trim($artist.' — '.$album->title);
            $suffix=' · '.$format.' | SoloDiscos';$title=Str::limit($name,72-mb_strlen($suffix),'…').$suffix;
            $stores=$album->offers->unique('store_id')->count();$clp=$album->offers->where('currency','CLP');
            $description='Compara '.Str::limit($name,70,'…').' en '.$format.' en '.$stores.' '.($stores===1?'tienda':'tiendas').' de Chile.'.($clp->isNotEmpty()?' Desde $'.number_format($clp->min('price'),0,',','.').' CLP.':'').' Revisa condición, edición y precio en cada tienda.';
            $index=!$r->filled('condition')&&!$r->filled('currency')&&!$r->boolean('comparison');
            if($index)$canonical=self::url('/album/'.$album->id.'/'.$data['format']);
            $cover=$album->offers->firstWhere('image','!=',null)?->image;if($cover)$image=$cover;
            if($index)$graph[]=['@type'=>'MusicAlbum','@id'=>$canonical.'#album','name'=>$album->title,'description'=>$description,'url'=>$canonical,'image'=>$image];
            if($index)$graph[]=['@type'=>'BreadcrumbList','itemListElement'=>[['@type'=>'ListItem','position'=>1,'name'=>'SoloDiscos','item'=>self::url()],['@type'=>'ListItem','position'=>2,'name'=>$name.' · '.$format,'item'=>$canonical]]];
        }elseif($route==='discounts'){
            $title='Descuentos en CDs y vinilos en Chile | SoloDiscos';$description='Descubre discos cuyo precio ha bajado desde su primer registro en SoloDiscos. Compara descuentos por tienda y consulta el historial de precios.';
            $index=!$r->filled('q')&&!$r->filled('store_id')&&!$r->filled('format')&&!$r->filled('condition')&&(!$r->filled('sort')||$r->query('sort')==='percent');
            if($page>1){$query['page']=$page;$title='Página '.$page.' · '.$title;}
            if(isset($data['products'])&&$data['products']->isEmpty())$index=false;
            if($index)$canonical=self::url('/descuentos',$query);
        }elseif($route==='rankings'){
            $title='Top 10 tiendas de discos en Chile: compara precios | SoloDiscos';$description='Explora tiendas recomendadas de CDs y vinilos en Chile según sus precios en discos comparables. Consulta los productos y la metodología del ranking.';
            $index=!$r->filled('format')&&!$r->filled('condition')&&(!$r->filled('currency')||$r->query('currency')==='CLP');if($index)$canonical=self::url('/rankings');
        }elseif($route==='policies.show'){
            $title=($data['policy']['title']??'Información').' | SoloDiscos';$description=$data['policy']['intro']??$description;$index=true;$canonical=self::url('/politicas/'.$r->route('policy'));
        }
        $public=$r->routeIs('catalog','album','discounts','rankings','policies.show','discounts.history','rankings.*');
        if($index){
            $graph[]=['@type'=>'Organization','@id'=>self::url().'#organization','name'=>'SoloDiscos','url'=>self::url()];
            $graph[]=['@type'=>'WebSite','@id'=>self::url().'#website','name'=>'SoloDiscos','url'=>self::url(),'inLanguage'=>'es-CL','publisher'=>['@id'=>self::url().'#organization']];
        }
        return ['title'=>$title,'description'=>Str::limit(preg_replace('/\s+/u',' ',strip_tags($description)),165,'…'),'canonical'=>$canonical,'robots'=>$index?'index, follow, max-image-preview:large':($public?'noindex, follow':'noindex, nofollow'),'image'=>$image,'graph'=>$graph];
    }
}
