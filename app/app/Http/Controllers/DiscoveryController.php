<?php
namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\CatalogDiscovery;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class DiscoveryController extends Controller {
    private function filters(Request $r): void {$r->validate(['page'=>'nullable|integer|min:1|max:100000','format'=>'nullable|in:cd,vinyl','condition'=>'nullable|in:new,used,unknown','q'=>'nullable|string|max:150']);}
    public function artists(Request $r,CatalogDiscovery $discovery){
        $this->filters($r);$all=collect($discovery->artists())->values();
        if($r->filled('q'))$all=$all->filter(fn($a)=>mb_stripos($a['name'],$r->input('q'))!==false)->values();
        $page=(int)$r->input('page',1);
        $artists=new LengthAwarePaginator($all->slice(($page-1)*60,60)->values(),$all->count(),60,$page,['path'=>$r->url(),'query'=>$r->query()]);
        abort_if($page>1&&$artists->isEmpty(),404);
        return view('discovery.artists',compact('artists'));
    }
    public function artist(Request $r,string $key,CatalogDiscovery $discovery,?string $slug=null){
        $this->filters($r);$artist=$discovery->artists()[$key]??null;abort_unless($artist,404);
        if($r->getPathInfo()!==$artist['path'])return redirect($artist['path'].($r->getQueryString()?'?'.$r->getQueryString():''),301);
        $publications=$discovery->publications($r,$artist['aliases']);
        abort_if((int)$r->input('page',1)>1&&$publications->isEmpty(),404);
        $medium=match($r->input('format')){'cd'=>'CD','vinyl'=>'vinilo',default=>'CD y vinilo'};
        return view('discovery.catalog',['artistProfile'=>$artist,'publications'=>$publications,'hubTitle'=>$artist['name'].' en '.$medium,'hubPath'=>$artist['path'],'hubType'=>'artist']);
    }
    public function stores(Request $r,CatalogDiscovery $discovery){$this->filters($r);abort_if((int)$r->input('page',1)>1,404);return view('discovery.stores',['publicStores'=>$discovery->stores()]);}
    public function store(Request $r,Store $store,CatalogDiscovery $discovery,?string $slug=null){
        $this->filters($r);abort_unless($store->active&&$store->offers()->available()->exists(),404);$path=$discovery->storePath($store);
        if($r->getPathInfo()!==$path)return redirect($path.($r->getQueryString()?'?'.$r->getQueryString():''),301);
        $publications=$discovery->publications($r,null,$store->id);
        abort_if((int)$r->input('page',1)>1&&$publications->isEmpty(),404);
        $medium=match($r->input('format')){'cd'=>'CDs','vinyl'=>'vinilos',default=>'CDs y vinilos'};
        return view('discovery.catalog',['publicStore'=>$store,'publications'=>$publications,'hubTitle'=>$store->name.': '.$medium,'hubPath'=>$path,'hubType'=>'store']);
    }
}
