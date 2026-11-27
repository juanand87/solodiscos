<?php
namespace App\Services;

use App\Models\{Album,Offer};
use Illuminate\Support\Str;

class NeedleIdentityResolver {
    private ?array $references=null;
    public function __construct(private ProductNormalizer $normalizer) {}

    private function text(string $value): string {
        return Str::slug(preg_replace('/\s*([\/&+])\s*/u','$1',html_entity_decode($value,ENT_QUOTES|ENT_HTML5,'UTF-8')));
    }

    private function index(): array {
        if($this->references!==null)return $this->references;
        $this->references=[];$groups=[];
        Offer::whereNotNull('album_id')->whereNull('excluded_reason')->whereIn('format',['cd','vinyl'])
            ->whereHas('store',fn($q)=>$q->whereNotIn('url',['https://needle.cl','https://www.needle.cl']))
            ->with('album')->select(['id','store_id','album_id','format'])->orderBy('id')->chunkById(1000,function($offers)use(&$groups){
                foreach($offers as $offer){
                    $a=$offer->album;if(!$a)continue;
                    $key=$this->normalizer->key($a->artist,$a->title).'|'.$offer->format;
                    $groups[$key][$a->id]['stores'][$offer->store_id]=true;
                    $groups[$key][$a->id]['album']=$a;
                }
            });
        foreach($groups as $key=>$albums){
            uasort($albums,fn($a,$b)=>count($b['stores'])<=>count($a['stores']) ?: $a['album']->id<=>$b['album']->id);
            $a=reset($albums)['album'];$this->references[$key]=['artist'=>$a->artist,'album'=>$a->title,'needle_album_id'=>$a->id];
        }
        return $this->references;
    }

    public function enrich(string $title,array $source,string $description=''): array {
        foreach(['artist','album','needle_album_id','identity_pending','identity_evidence'] as $field)unset($source[$field]);
        $source['identity_pending']=true;$source['identity_evidence']='Needle: artista o álbum sin evidencia suficiente; requiere revisión.';
        $type=$this->text((string)($source['product_type']??''));
        if(preg_match('/reproductor|lector|player/',$type)
            || ($type==='pack' && preg_match('/\b(?:tornamesa|parlantes?|amplificador|preamplificador|aud[ií]fonos?|reproductor|lector)\b/iu',$title))){
            $source['catalog_exclusion']='Equipo de reproducción: fuera del catálogo de CDs y vinilos.';
            return $source;
        }
        if($type==='gift-card'){$source['catalog_exclusion']='Gift card: fuera del catálogo de CDs y vinilos.';return $source;}
        $classification=$this->normalizer->parse($title,'','unknown',$source,$description);
        if($classification['excluded_reason'] || !in_array($classification['format'],['cd','vinyl']))return $source;
        $format=$classification['format'];$vendor=trim(html_entity_decode((string)($source['vendor']??''),ENT_QUOTES|ENT_HTML5,'UTF-8'));
        $album=html_entity_decode($title,ENT_QUOTES|ENT_HTML5,'UTF-8');$artist=null;
        // Needle's public Shopify vendor identifies the musical artist, not the record label.
        if($vendor!=='' && !in_array($this->text($vendor),['needle','varios','varios-artistas','various','various-artists','unknown','sin-artista'])){
            $artist=$vendor;$parts=preg_split('/\s+[-–—]\s+/u',$album);
            for($i=1;$i<count($parts);$i++){
                if($this->text(implode(' - ',array_slice($parts,$i)))===$this->text($artist)){$album=implode(' - ',array_slice($parts,0,$i));break;}
            }
            if(count($parts)>1 && $this->text($parts[0])===$this->text($artist))$album=implode(' - ',array_slice($parts,1));
        }else{
            // Legacy rows without vendor only qualify when a complete reversed title matches another store.
            $matches=[];$parts=preg_split('/\s+[-–—]\s+/u',$album);$index=$this->index();
            for($i=1;$i<count($parts);$i++){
                $candidateAlbum=$this->normalizer->albumTitle(implode(' - ',array_slice($parts,0,$i)));
                $candidateArtist=implode(' - ',array_slice($parts,$i));$key=$this->normalizer->key($candidateArtist,$candidateAlbum).'|'.$format;
                if(isset($index[$key]))$matches[$key]=$index[$key];
            }
            if(count($matches)!==1)return $source;
            $match=reset($matches);return array_merge($source,$match,['identity_pending'=>false,'identity_evidence'=>'Needle: título completo Álbum – Artista coincide con otra tienda en el mismo formato.']);
        }
        $album=$this->normalizer->albumTitle($album);
        if($album==='' || in_array($this->text($album),['cd','vinilo','vinyl','lp']))return $source;
        $match=$this->index()[$this->normalizer->key($artist,$album).'|'.$format]??null;
        return array_merge($source,$match??['artist'=>$artist,'album'=>$album],['identity_pending'=>false,
            'identity_evidence'=>$match?'Needle: artista del catálogo público y álbum completo coinciden con otra tienda en el mismo formato.':'Needle: artista del campo vendor y título del álbum del catálogo público.']);
    }

    public function albumId(array $parsed,array $source): ?int {
        if(!$parsed['artist'] || $parsed['excluded_reason'])return null;
        if(isset($source['needle_album_id']))return (int)$source['needle_album_id'];
        return Album::firstOrCreate(['match_key'=>$this->normalizer->key($parsed['artist'],$parsed['album'])],
            ['artist'=>mb_substr($parsed['artist'],0,255),'title'=>mb_substr($parsed['album'],0,255)])->id;
    }
}
