<?php
namespace App\Services;
use App\Models\{Offer,CoverMatch,CoverFingerprint};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CoverReview {
    public function __construct(private CoverCandidates $candidates){}
    private function invalid(string $message): never {throw ValidationException::withMessages(['cover'=>$message]);}
    private function signature(Offer $offer): string {return hash('sha256',json_encode([$offer->album_id,$offer->format,$offer->condition,$offer->reviewed,$offer->updated_at?->format('Y-m-d H:i:s')]));}
    public function accept(CoverMatch $match,string $target,int $user): int {
        return DB::transaction(function()use($match,$target,$user){
            $match=CoverMatch::lockForUpdate()->findOrFail($match->id);
            if($match->status!=='pending')$this->invalid('La propuesta ya fue revisada.');
            $ids=[$match->left_offer_id,$match->right_offer_id];sort($ids);
            $locked=Offer::whereKey($ids)->orderBy('id')->lockForUpdate()->with('album')->get()->keyBy('id');
            $a=$locked[$match->left_offer_id];$b=$locked[$match->right_offer_id];
            $left=CoverFingerprint::findOrFail($match->left_cover_id);$right=CoverFingerprint::findOrFail($match->right_cover_id);
            $currentSignature=hash('sha256',implode('|',[$a->id,$b->id,$a->album_id,$b->album_id,$left->content_hash,$right->content_hash,PerceptualHash::VERSION]));
            if($a->album_id!==$match->left_album_id || $b->album_id!==$match->right_album_id || $a->image!==$left->url || $b->image!==$right->url
                || $a->format!==$match->format || $left->status!=='ready' || $right->status!=='ready' || $currentSignature!==$match->signature
                || !$this->candidates->compatible($a,$b))$this->invalid('Los datos cambiaron. Descarta esta propuesta y vuelve a comparar.');
            if(!in_array($target,['left','right']))$this->invalid('Elige el nombre de álbum que se conservará.');
            $from=$target==='left'?$b:$a;$to=$target==='left'?$a:$b;
            if($from->reviewed)$this->invalid('La oferta de origen tiene una clasificación manual protegida. Revísala individualmente o conserva su álbum.');
            $offers=Offer::where('album_id',$from->album_id)->where('format',$match->format)->whereNull('excluded_reason')->where('reviewed',false)->orderBy('id')->lockForUpdate()->get();
            $changes=[];
            foreach($offers as $offer){
                $old=$offer->album_id;$offer->update(['album_id'=>$to->album_id,'reviewed'=>true]);
                $changes[]=['offer_id'=>$offer->id,'previous_album_id'=>$old,'previous_reviewed'=>false,'target_album_id'=>$to->album_id,'after_signature'=>$this->signature($offer)];
            }
            if(!$changes)$this->invalid('No quedan ofertas automáticas para agrupar.');
            $match->update(['status'=>'accepted','reviewed_by'=>$user,'reviewed_at'=>now(),'changes'=>$changes]);
            return count($changes);
        });
    }
    public function reject(CoverMatch $match,int $user): void {
        CoverMatch::whereKey($match->id)->where('status','pending')->update(['status'=>'rejected','reviewed_by'=>$user,'reviewed_at'=>now()]);
    }
    public function undo(CoverMatch $match,int $user): int {
        return DB::transaction(function()use($match,$user){
            $match=CoverMatch::lockForUpdate()->findOrFail($match->id);
            if($match->status!=='accepted')$this->invalid('Solo se pueden deshacer agrupaciones aprobadas.');
            $changes=$match->changes??[];$ids=array_column($changes,'offer_id');sort($ids);
            $offers=Offer::whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach($changes as $change)if(!isset($offers[$change['offer_id']]) || $this->signature($offers[$change['offer_id']])!==$change['after_signature'])
                $this->invalid('Una oferta cambió después de agruparla. Revísala individualmente; no se sobrescribieron los cambios posteriores.');
            foreach($changes as $change)$offers[$change['offer_id']]->update(['album_id'=>$change['previous_album_id'],'reviewed'=>$change['previous_reviewed']]);
            $match->update(['status'=>'reverted','reviewed_by'=>$user,'reviewed_at'=>now()]);return count($changes);
        });
    }
}
