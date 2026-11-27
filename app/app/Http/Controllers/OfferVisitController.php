<?php
namespace App\Http\Controllers;
use App\Models\Offer;
use App\Services\{EngagementTracker,StoreDomain};
use Illuminate\Http\Request;
class OfferVisitController extends Controller {
    public function __invoke(Request $r,Offer $offer,EngagementTracker $tracker){
        abort_unless($offer->isPublished(),404);
        $parts=parse_url($offer->url);
        $host=strtolower(preg_replace('/^www\./','',$parts['host']??''));
        abort_unless(in_array($parts['scheme']??'',['http','https']) && !isset($parts['user']) && !isset($parts['pass'])
            && !preg_match('/[\r\n]/',$offer->url) && $host===app(StoreDomain::class)->host($offer->store),404);
        $tracker->click($r,$offer);
        $url=$offer->url;
        if($offer->store->platform==='woocommerce'){
            // Preserve product/variation parameters and fragments while replacing any old campaign.
            $fragment=isset($parts['fragment'])?'#'.$parts['fragment']:'';
            $base=explode('?',explode('#',$url,2)[0],2)[0];
            parse_str($parts['query']??'',$query);
            foreach(array_keys($query) as $key)if(str_starts_with(strtolower($key),'utm_'))unset($query[$key]);
            $query+=['utm_source'=>'solodiscos.cl','utm_medium'=>'referral','utm_campaign'=>'catalogo'];
            $url=$base.'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986).$fragment;
        }
        return redirect()->away($url)->header('Cache-Control','no-store, private')->header('Referrer-Policy','no-referrer');
    }
}
