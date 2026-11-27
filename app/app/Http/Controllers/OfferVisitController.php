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
        return redirect()->away($offer->url)->header('Cache-Control','no-store, private')->header('Referrer-Policy','no-referrer');
    }
}
