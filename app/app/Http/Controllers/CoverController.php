<?php
namespace App\Http\Controllers;
use App\Models\{CoverMatch,CoverScan,CoverFingerprint};
use App\Services\{CoverReview,CoverScanDispatcher};
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

class CoverController extends Controller {
    public function index(Request $r) {
        $data=$r->validate(['status'=>['nullable',Rule::in(['pending','accepted','rejected','reverted','obsolete'])],'artist'=>'nullable|string|max:100']);
        CoverMatch::retireStalePending();
        $query=CoverMatch::with(['leftOffer.album','leftOffer.store','rightOffer.album','rightOffer.store','leftCover','rightCover'])->where('status',$data['status']??'pending');
        if(!empty($data['artist']))$query->whereHas('leftOffer.album',fn($q)=>$q->where('artist','like','%'.$data['artist'].'%'));
        return view('cover-review',['matches'=>$query->orderBy('distance')->latest('id')->paginate(12)->withQueryString(),
            'scan'=>CoverScan::latest('id')->first(),'pending'=>CoverMatch::where('status','pending')->count()]);
    }
    public function scan(Request $r,CoverScanDispatcher $dispatcher) {
        $data=$r->validate(['artist'=>'nullable|string|max:100','limit'=>'required|integer|min:1|max:500']);
        $created=$dispatcher->start($data['artist']??null,(int)$data['limit']);
        return redirect()->route('covers.index',['artist'=>$data['artist']??null])->with('status',$created?'Recorrido en cola. Los lotes continuarán automáticamente hasta terminar, sin IA.':'Ya hay una comparación pendiente o en curso.');
    }
    public function progress() {
        return response()->json(['scan'=>CoverScan::latest('id')->first(),'pending'=>CoverMatch::where('status','pending')->count()])->header('Cache-Control','no-store, private');
    }
    public function thumbnail(CoverFingerprint $cover) {
        abort_unless($cover->status==='ready' && is_file($cover->thumbnailPath()),404);
        return response()->file($cover->thumbnailPath(),['Content-Type'=>'image/jpeg','X-Content-Type-Options'=>'nosniff','Cache-Control'=>'private, max-age=86400']);
    }
    public function accept(Request $r,CoverMatch $match,CoverReview $review) {
        $data=$r->validate(['target'=>['required',Rule::in(['left','right'])],'confirm'=>'accepted']);
        $count=$review->accept($match,$data['target'],$r->user()->id);
        CoverMatch::retireStalePending();
        return back()->with('status',"Agrupación aprobada: $count ofertas reclasificadas. Las correcciones manuales anteriores se conservaron.");
    }
    public function acceptPage(Request $r,CoverReview $review) {
        $data=$r->validate(['page_token'=>'required|string','targets'=>'required|array|min:1|max:12',
            'targets.*'=>['required',Rule::in(['left','right'])],'confirm'=>'accepted']);
        try {$page=json_decode(Crypt::decryptString($data['page_token']),true,512,JSON_THROW_ON_ERROR);}
        catch(\Throwable $e){throw ValidationException::withMessages(['cover'=>'La página no es válida. Actualízala antes de aprobar.']);}
        $allowed=$page['ids']??[];
        if(count($allowed)>12 || array_diff(array_keys($data['targets']),$allowed))
            throw ValidationException::withMessages(['cover'=>'Solo puedes aprobar las propuestas mostradas en esta página.']);
        $approved=0;$skipped=0;$offers=0;
        foreach($data['targets'] as $id=>$target){
            $match=CoverMatch::find($id);
            if(!$match || $match->status!=='pending'){$skipped++;continue;}
            try {$offers+=$review->accept($match,$target,$r->user()->id);$approved++;}
            catch(ValidationException $e){$skipped++;}
        }
        CoverMatch::retireStalePending();
        return redirect()->route('covers.index',['status'=>'pending','artist'=>$page['artist']??null])->with('status',
            "$approved propuestas aprobadas; $offers ofertas agrupadas. $skipped propuestas omitidas por cambios de datos, revisiones anteriores o clasificación manual protegida. Se muestran las siguientes pendientes.");
    }
    public function reject(Request $r,CoverMatch $match,CoverReview $review) {
        $review->reject($match,$r->user()->id);return back()->with('status','Propuesta descartada. No cambió ninguna publicación.');
    }
    public function undo(Request $r,CoverMatch $match,CoverReview $review) {
        $count=$review->undo($match,$r->user()->id);return back()->with('status',"Agrupación deshecha: $count ofertas restauradas.");
    }
}
