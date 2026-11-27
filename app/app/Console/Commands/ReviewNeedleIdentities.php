<?php
namespace App\Console\Commands;

use App\Models\{Store,Offer};
use App\Services\{NeedleIdentityResolver,ProductNormalizer,PriceHistory,StoreRankings};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReviewNeedleIdentities extends Command {
    protected $signature='needle:review {--catalog= : Archivo JSON del catálogo público de Needle} {--apply : Aplicar la revisión; sin esta opción solo muestra la propuesta}';
    protected $description='Revisa artista y álbum de Needle sin IA, conserva correcciones manuales y agrupa coincidencias exactas por formato';

    public function handle(NeedleIdentityResolver $resolver,ProductNormalizer $normalizer,StoreRankings $rankings): int {
        $path=$this->option('catalog');
        if(!$path || !is_file($path)){$this->error('Indica --catalog con una captura completa de products.json (complete=true, products=[...]).');return self::FAILURE;}
        $catalog=json_decode(file_get_contents($path),true);
        if(!($catalog['complete']??false) || !is_array($catalog['products']??null)){$this->error('Catálogo inválido o incompleto.');return self::FAILURE;}
        $variants=[];foreach($catalog['products'] as $product)foreach($product['variants']??[] as $variant)$variants[(string)$variant['id']]=$product;
        $apply=(bool)$this->option('apply');$store=Store::whereIn('url',['https://needle.cl','https://www.needle.cl'])->firstOrFail();
        $beforeRank=$this->ranking($rankings,$store->id);$beforeShared=$this->shared($store->id);
        $report=DB::transaction(function()use($store,$variants,$apply,$resolver,$normalizer){
            $store=Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $offers=$store->offers()->orderBy('id')->lockForUpdate()->get();
            $r=['applied'=>$apply,'at'=>now()->toIso8601String(),'offers'=>$offers->count(),'manual_preserved'=>0,'live_metadata'=>0,'identified'=>0,'matched_other_store'=>0,'pending'=>0,'excluded'=>0,'changed'=>0,'changes'=>[],'pending_examples'=>[]];
            if($apply){
                $backup=base_path('../.local/needle-identity-backup-'.now()->format('Ymd-His').'.json');
                file_put_contents($backup,json_encode(['store'=>$store->getAttributes(),'offers'=>$offers->map->getAttributes()->all()],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));$r['backup']=$backup;
            }
            foreach($offers as $offer){
                if($offer->reviewed){$r['manual_preserved']++;continue;}
                $source=$offer->source_metadata??[];
                if($product=$variants[$offer->external_id]??null){$source['vendor']=$product['vendor']??'';$r['live_metadata']++;}
                $source=$resolver->enrich($offer->title,$source,$offer->description??'');
                $parsed=$normalizer->parse($offer->title,'','unknown',$source,$offer->description??'');
                if($parsed['excluded_reason'])$r['excluded']++;
                elseif($parsed['artist']){$r['identified']++;if(isset($source['needle_album_id']))$r['matched_other_store']++;}
                else{$r['pending']++;$r['pending_examples'][]=['id'=>$offer->id,'title'=>$offer->title,'format'=>$parsed['format'],'reason'=>$source['identity_evidence']];}
                $target=$source['needle_album_id']??null;
                $newIdentity=$parsed['artist']&&!$parsed['excluded_reason']?$normalizer->key($parsed['artist'],$parsed['album']):null;
                $oldIdentity=$offer->album?$normalizer->key($offer->album->artist,$offer->album->title):null;
                $changed=$oldIdentity!==$newIdentity || ($target && $target!==$offer->album_id) || $offer->format!==$parsed['format'] || $offer->excluded_reason!==$parsed['excluded_reason'];
                if($changed){$r['changed']++;$r['changes'][]=['id'=>$offer->id,'title'=>$offer->title,'from_album_id'=>$offer->album_id,'reference_album_id'=>$target,'artist'=>$parsed['artist'],'album'=>$parsed['album'],'format'=>$parsed['format'],'evidence'=>$source['identity_evidence']];}
                if(!$apply)continue;
                $before=$offer->getAttributes();$albumId=$resolver->albumId($parsed,$source);
                $identityChanged=$offer->album_id!==$albumId || $offer->format!==$parsed['format'];
                $offer->update(['source_metadata'=>$source,'album_id'=>$albumId,'format'=>$parsed['format'],'excluded_reason'=>$parsed['excluded_reason'],'format_evidence'=>$parsed['format_evidence']]);
                if($identityChanged)DB::table('offer_price_tracking')->where('offer_id',$offer->id)->update(['status'=>'closed','closed_at'=>now(),'close_reason'=>'identity_changed']);
                $offer->refresh();
                foreach(['title','description','url','price','currency','availability','condition','missing_since','checked_at','reviewed'] as $field)
                    if(($before[$field]??null)!==$offer->getRawOriginal($field))throw new \RuntimeException('Se modificó un campo ajeno: '.$field.' en '.$offer->id);
            }
            return $r;
        });
        if($apply)PriceHistory::invalidate();
        $report['ranking_before']=$beforeRank;$report['ranking_after']=$this->ranking($rankings,$store->id);
        $report['shared_before']=$beforeShared;$report['shared_after']=$this->shared($store->id);
        $output=base_path('../.local/needle-identity-'.($apply?'review':'preview').'.json');file_put_contents($output,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $this->line(json_encode(array_diff_key($report,array_flip(['changes','pending_examples'])),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
    private function ranking(StoreRankings $service,int $store): array {
        $r=$service->calculate(['currency'=>'CLP']);$result=[];
        foreach($r['cheap'] as $condition=>$rows)foreach($rows as $row)if($row['store']->id===$store)$result[$condition]=array_intersect_key($row,array_flip(['sample','eligible','index','competitor_count']));
        return $result;
    }
    private function shared(int $store): int {
        return DB::table('offers as o')->join('offers as p',fn($q)=>$q->on('p.album_id','=','o.album_id')->on('p.format','=','o.format')->where('p.store_id','!=',$store))
            ->join('stores as s','s.id','=','p.store_id')->where('s.active',true)->where('o.store_id',$store)->whereNull('o.excluded_reason')->whereNull('p.excluded_reason')->whereIn('o.format',['cd','vinyl'])
            ->selectRaw('COUNT(DISTINCT o.album_id,o.format) n')->value('n');
    }
}
