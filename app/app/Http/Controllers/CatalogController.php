<?php
namespace App\Http\Controllers;
use App\Models\{Album,Offer,Store};
use Illuminate\Http\Request;
class CatalogController extends Controller {
    public function index(Request $r) {
        $settings=$r->validate(['per_page'=>'nullable|integer|in:25,50,100,250']);
        $perPage=(int)($settings['per_page']??25);
        $filter=function($q) use($r) {
            $q->available();
            if (in_array($r->format,['cd','vinyl'])) $q->where('format',$r->format);
            if (in_array($r->condition,['new','used','unknown'])) $q->where('condition',$r->condition);
        };
        $query=Offer::select('album_id','format'); $filter($query);
        if ($r->filled('q')) $query->whereHas('album',fn($q)=>$q->where(fn($q)=>$q->where('title','like','%'.$r->string('q').'%')->orWhere('artist','like','%'.$r->string('q').'%')));
        $query->groupBy('album_id','format');
        if ($r->sort==='stores') $query->orderByRaw('COUNT(DISTINCT store_id) DESC');
        elseif ($r->sort==='recent') $query->orderByRaw('MAX(updated_at) DESC');
        $publications=$query->orderBy(Album::select('artist')->whereColumn('albums.id','offers.album_id')->limit(1))->orderBy('album_id')->orderBy('format')->paginate($perPage)->withQueryString();
        $publications->load(['album.offers'=>function($q)use($filter){$q->with('store');$filter($q);}]);
        app(\App\Services\InitialPriceDiscounts::class)->attach($publications->getCollection()->flatMap(fn($publication)=>$publication->album->offers)->unique('id')->values());
        $partnerStores=Store::where('active',true)->whereHas('offers',fn($q)=>$q->available())->orderBy('name')->get(['id','name','logo_url']);
        $bannerCovers=Offer::available()->where('format','cd')->whereNotNull('image')->orderByDesc('checked_at')->limit(4)->get(['image','title']);
        return view('catalog',compact('publications','partnerStores','bannerCovers','perPage'));
    }
    public function show(Request $r,Album $album,?string $format=null) {
        $filters=$r->validate(['condition'=>'nullable|in:new,used,unknown','currency'=>'nullable|in:CLP','comparison'=>'nullable|boolean','return_to'=>'nullable|string|max:4096']);
        $return=$filters['return_to']??'';
        $backUrl=preg_match('~^/(?:\?[^\r\n#]*)?(?:#catalogo)?$~D',$return)?$return:route('catalog').'#catalogo';
        $filter=function($q)use($filters){
            $q->available();
            if(!empty($filters['condition']))$q->where('condition',$filters['condition']);
            if(!empty($filters['currency']))$q->where('currency',$filters['currency']);
            if(!empty($filters['comparison']))$q->where('checked_at','>=',now()->subHours(\App\Services\PriceHistory::MAX_GAP_HOURS));
        };
        $query=$album->offers();$filter($query);$formats=$query->distinct()->pluck('format');
        abort_if($formats->isEmpty(),404);
        if (!$format) return redirect()->route('album',[$album,$formats->contains('vinyl')?'vinyl':$formats->first()]+$filters);
        abort_unless($formats->contains($format),404);
        $album->load(['offers'=>function($q)use($filter,$format){$filter($q);$q->where('format',$format)->with('store')->orderBy('price');}]);
        app(\App\Services\InitialPriceDiscounts::class)->attach($album->offers);
        app(\App\Services\EngagementTracker::class)->album($r,$album,$format);
        return view('album',compact('album','format','formats','backUrl'));
    }
}
