<?php
namespace App\Services;

use App\Models\Offer;
use Illuminate\Support\Str;

class PuntoMusicalReview
{
    private BillboardIdentityResolver $identities;
    private ?array $codes=null;
    public function __construct(private ProductNormalizer $normalizer,private ProductIdentifiers $identifiers,private string $excludedStore='https://puntomusical.cl')
    {
        $this->identities=new BillboardIdentityResolver($normalizer,$excludedStore);
    }
    private function codeIndex(): array
    {
        if ($this->codes!==null) return $this->codes;
        $this->codes=[];
        Offer::whereNull('excluded_reason')->whereNotNull('album_id')->whereIn('format',['cd','vinyl'])
            ->whereHas('store',fn($q)=>$q->where('url','!=',$this->excludedStore))
            ->select(['id','album_id','format','source_metadata'])->with('album')->chunkById(500,function($offers) {
                foreach ($offers as $offer) {
                    foreach ($offer->source_metadata['identifiers']??[] as $code) {
                        $gtin=$this->identifiers->gtin((string)($code['value']??''));if (!$gtin) continue;
                        $key=$this->normalizer->key($offer->album->artist,$offer->album->title).'|'.$offer->format;
                        $this->codes[$gtin][$key]=['artist'=>$offer->album->artist,'album'=>$offer->album->title,'format'=>$offer->format,'offer_id'=>$offer->id];
                    }
                }
            });
        return $this->codes;
    }
    public function enrich(string $title,array $source,string $description): array
    {
        $source['prefer_title_format']=true;
        $parsed=$this->normalizer->parse($title,'','unknown',$source,$description);
        if ($parsed['excluded_reason']) return $source;
        if (!$parsed['artist']) {
            $compoundComposer=preg_match('/^[^\s]+-[^\s,]+,/u',$title);
            $identity=$compoundComposer?null:$this->identities->resolve($title);
            $soundtrack=preg_match('/soundtrack|motion picture|\bO\.S\.T\b/iu',$title.' '.implode(' ',$source['tags']??[]));
            if ($identity && $soundtrack && $identity['evidence']!=='Artista y álbum completos coinciden con otra tienda') $identity=null;
            if ($identity && preg_match('/^(?:O\.?S\.?T\.?|I\.?S\.?T\.?)$/iu',trim($identity['album']))) $identity=null;
            if (!$identity && preg_match('/^(.+?)(?:\s+[-–—]\s*|[-–—]\s+)(.+)$/u',$title,$parts)) {
                $album=$this->normalizer->albumTitle($parts[2]);
                if ($album!=='') $identity=['artist'=>trim($parts[1]),'album'=>$album,'evidence'=>'Separador explícito con espacio en un solo lado'];
            }
            if ($identity) {
                $source['artist']=$identity['artist'];$source['album']=$identity['album'];$source['identity_evidence']=$identity['evidence'];
                $parsed=$this->normalizer->parse($title,'','unknown',$source,$description);
            }
        }
        if (!$parsed['artist'] || $parsed['format']==='unknown') {
            $candidates=[];$index=$this->codeIndex();
            foreach ($source['identifiers']??[] as $code) {
                $gtin=$this->identifiers->gtin((string)($code['value']??''));
                foreach ($index[$gtin??'']??[] as $key=>$candidate) $candidates[$key]=$candidate;
            }
            if (count($candidates)===1) {
                $match=array_values($candidates)[0];
                $agrees=!$parsed['artist'] || $this->normalizer->key($parsed['artist'],$parsed['album'])===$this->normalizer->key($match['artist'],$match['album']);
                $knownFormat=$parsed['format'];
                $titleText=Str::slug(html_entity_decode($title,ENT_QUOTES|ENT_HTML5,'UTF-8'));
                $albumText=Str::slug($this->normalizer->albumTitle($match['album']));$artistText=Str::slug($match['artist']);
                $nameAgrees=($albumText!=='' && str_contains($titleText,$albumText)) || ($artistText!=='' && str_contains($titleText,$artistText));
                if ($agrees && $nameAgrees && ($knownFormat==='unknown' || $knownFormat===$match['format'])) {
                    $source['artist']=$match['artist'];$source['album']=$match['album'];
                    $source['reference_format']=$match['format'];$source['reference_offer_id']=$match['offer_id'];
                    $source['identity_evidence']='GTIN válido y único en otra tienda, con nombre compatible';
                }
            }
        }
        return $source;
    }
}
