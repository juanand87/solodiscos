<?php
namespace App\Http\Controllers;
use App\Models\{Offer,Store};
use App\Services\InitialPriceDiscounts;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DiscountController extends Controller {
    public function index(Request $r,InitialPriceDiscounts $discounts){
        $filters=$r->validate(['store_id'=>'nullable|integer|exists:stores,id','format'=>['nullable',Rule::in(['cd','vinyl'])],'condition'=>['nullable',Rule::in(['new','used','unknown'])],'q'=>'nullable|string|max:150','sort'=>['nullable',Rule::in(['percent','percent_asc','recent','price'])]]);
        $query=$discounts->query()->whereColumn('o.price','<','initial.price');
        foreach(['store_id','format','condition'] as $field)if(!empty($filters[$field]))$query->where('o.'.$field,$filters[$field]);
        if(!empty($filters['q']))$query->where(fn($q)=>$q->where('a.artist','like','%'.$filters['q'].'%')->orWhere('a.title','like','%'.$filters['q'].'%')->orWhere('o.title','like','%'.$filters['q'].'%'));
        match($filters['sort']??'percent'){'percent_asc'=>$query->orderByRaw('100.0*(initial.price-o.price)/initial.price ASC'),'recent'=>$query->orderByDesc('o.checked_at'),'price'=>$query->orderBy('o.price'),default=>$query->orderByRaw('100.0*(initial.price-o.price)/initial.price DESC')};
        $products=$query->orderBy('o.id')->paginate(12)->withQueryString();
        return view('discounts',compact('products','filters')+['stores'=>Store::where('active',true)->orderBy('name')->get(['id','name']),'charts'=>$discounts->charts($products->items())]);
    }
    public function history(Request $r,Offer $offer,InitialPriceDiscounts $discounts){
        abort_unless($offer->isPublished() && $offer->currency==='CLP',404);
        $baseline=$discounts->query()->where('o.id',$offer->id)->first();
        $chart=$discounts->charts([$offer])[$offer->id];
        return view('price-history',compact('offer','baseline','chart')+['observations'=>$discounts->observations($offer->id)->where('created_at','<=',$offer->checked_at)->paginate(50)->withQueryString()]);
    }
}
