<?php
namespace App\Services;

use App\Models\{Album,Offer,Store};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache,DB};
use Illuminate\Support\Str;

class CatalogDiscovery {
    private ?array $artistIndex=null;
    public function artistKey(string $name): string {return substr(app(ProductNormalizer::class)->key($name,'__artist__'),0,16);}
    public function artistPath(string $name): string {return '/artistas/'.$this->artistKey($name).'/'.(Str::slug($name)?:'artista');}
    public function storePath(Store|\stdClass $store): string {return '/tiendas-de-discos/'.$store->id.'/'.(Str::slug($store->name)?:'tienda');}
    public function artists(): array {
        if($this->artistIndex!==null)return $this->artistIndex;
        $packed=Cache::remember('seo:discovery:artists:v2',300,function(){
            $published=Offer::available()->select('album_id','format')->groupBy('album_id','format');
            $rows=DB::query()->fromSub($published->toBase(),'published')->join('albums','albums.id','=','published.album_id')
                ->select('albums.artist')->selectRaw('COUNT(*) AS publications')->groupBy('albums.artist')->orderBy('albums.artist')->get();
            $artists=[];
            foreach($rows as $row){
                $name=trim($row->artist);if($name==='')continue;$key=$this->artistKey($name);
                if(!isset($artists[$key]))$artists[$key]=['name'=>$name,'key'=>$key,'path'=>$this->artistPath($name),'aliases'=>[],'count'=>0];
                $artists[$key]['aliases'][]=$row->artist;$artists[$key]['count']+=(int)$row->publications;
            }
            // Keep the shared database-cache value below small hosting packet limits.
            return base64_encode(gzencode(json_encode($artists,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR),6));
        });
        return $this->artistIndex=json_decode(gzdecode(base64_decode($packed,true)),true,512,JSON_THROW_ON_ERROR);
    }
    public function stores(){return Store::where('active',true)->whereHas('offers',fn($q)=>$q->available())->orderBy('name')->get(['id','name','url','logo_url']);}
    public function related(Album $album,string $format){
        $profile=$this->artists()[$this->artistKey($album->artist)]??null;
        $rows=Offer::available()->where('album_id','!=',$album->id)->where('format',$format)
            ->whereHas('album',fn($q)=>$q->whereIn('artist',$profile['aliases']??[$album->artist]))
            ->select('album_id','format')->groupBy('album_id','format')->orderBy('album_id')->limit(4)->get();
        $rows->load(['album.offers'=>fn($q)=>$q->available()->where('format',$format)->with('store')]);
        app(InitialPriceDiscounts::class)->attach($rows->flatMap(fn($p)=>$p->album->offers)->unique('id')->values());
        return $rows;
    }
    public function publications(Request $r,?array $artists=null,?int $store=null,int $size=25){
        $filter=function($q)use($r,$artists,$store){
            $q->available();if($store)$q->where('store_id',$store);
            if($artists)$q->whereHas('album',fn($a)=>$a->whereIn('artist',$artists));
            if($r->filled('format'))$q->where('format',$r->input('format'));
            if($r->filled('condition'))$q->where('condition',$r->input('condition'));
        };
        $query=Offer::select('album_id','format');$filter($query);
        if($r->filled('q'))$query->whereHas('album',fn($q)=>$q->where(fn($q)=>$q->where('title','like','%'.$r->input('q').'%')->orWhere('artist','like','%'.$r->input('q').'%')));
        $page=$query->groupBy('album_id','format')->orderBy(Album::select('title')->whereColumn('albums.id','offers.album_id')->limit(1))->orderBy('album_id')->orderBy('format')->paginate($size)->withQueryString();
        $page->load(['album.offers'=>function($q)use($filter){$filter($q);$q->with('store');}]);
        app(InitialPriceDiscounts::class)->attach($page->getCollection()->flatMap(fn($p)=>$p->album->offers)->unique('id')->values());
        return $page;
    }
}
