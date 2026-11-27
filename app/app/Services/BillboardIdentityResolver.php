<?php
namespace App\Services;

use App\Models\Album;
use Illuminate\Support\Str;

class BillboardIdentityResolver
{
    private ?array $artists=null;
    public function __construct(private ProductNormalizer $normalizer,private string $excludedStore='https://www.billboard.cl') {}

    private function text(string $value): string
    {
        $value=preg_replace('/\p{Cf}/u','',html_entity_decode($value,ENT_QUOTES|ENT_HTML5,'UTF-8'));
        return Str::slug(preg_replace('/\s*([\/&+])\s*/u','$1',str_replace(',',' ',$value)));
    }

    private function index(): array
    {
        if ($this->artists!==null) return $this->artists;
        $this->artists=[];
        // Never learn names from this store's own inferred identities.
        $references=fn($q)=>$q->whereNull('excluded_reason')->whereIn('format',['cd','vinyl'])
            ->whereHas('store',fn($s)=>$s->where('url','!=',$this->excludedStore));
        $albums=Album::whereHas('offers',$references)->with(['offers'=>fn($q)=>$references($q)->select(['id','album_id','store_id'])])->orderBy('id')->get();
        $groups=[];
        foreach ($albums as $album) {
            $artist=$this->artistName($album->artist);
            if (preg_match('/^(?:vinilo|vinyl|cd|lp|various|varios|ost|soundtrack)\b/iu',$artist)) continue;
            if (str_contains($artist,':') || preg_match('/\b(?:funko|tornamesa|pack|boxset|complete works|edici[oó]n|edition)\b/iu',$artist)) continue;
            $id=preg_replace('/^(?:the|los|las)-/','',$this->text($artist));
            if ($id==='') continue;
            foreach ($album->offers as $offer) {
                $groups[$id]['stores'][$offer->store_id]=true;
                $groups[$id]['names'][$artist][$offer->store_id]=true;
            }
            $groups[$id]['albums'][$this->albumKey($album->title)]=$album->title;
        }
        foreach ($groups as $id=>$group) {
            // Prefer the spelling backed by the most independent sources.
            uksort($group['names'],fn($a,$b)=>count($group['names'][$b])<=>count($group['names'][$a]) ?: strcmp($a,$b));
            $artist=array_key_first($group['names']);
            $aliases=[$id=>false,$this->text($artist)=>false];
            // A comma explicitly marks the surname-first form in the input.
            $words=preg_split('/\s+/u',$artist);
            if (!str_contains($artist,',') && count($words)>=2 && count($words)<=4) {
                for ($split=1;$split<count($words);$split++) {
                    $alias=$this->text(implode(' ',array_merge(array_slice($words,$split),array_slice($words,0,$split))));
                    if (!array_key_exists($alias,$aliases)) $aliases[$alias]=true;
                }
            }
            foreach ($aliases as $alias=>$needsComma) {
                $this->artists[$alias][$id]=['artist'=>$artist,'comma'=>$needsComma,'albums'=>$group['albums'],
                    'stores'=>count($group['stores']),'album_count'=>count($group['albums'])];
            }
        }
        return $this->artists;
    }

    private function artistName(string $artist): string
    {
        $artist=trim($artist);
        if (preg_match('/^(.+?)\s*(?:,\s*|\s+)(THE|LOS|LAS)$/iu',$artist,$m)) return ucfirst(strtolower($m[2])).' '.trim($m[1]);
        if (preg_match('/^([^,]+),\s*([^,]+)$/u',$artist,$m)
            && !preg_match('/\b(?:THE|AND)\b|&/iu',$m[2])
            && count(preg_split('/\s+/',trim($m[2])))===1) return trim($m[2]).' '.trim($m[1]);
        return $artist;
    }

    private function albumKey(string $title): string
    {
        $title=$this->normalizer->albumTitle($title);
        $title=preg_replace_callback('/\(([^()]*)\)/u',function($m) {
            return preg_match('/\b(?:vinyl|vinilo|e?corecord|[1-9]\d*\s*(?:gram|gr|g)|anniversary|limited edition|colored|steven wilson remix|blue note classic|verve by request|ecm series)\b/iu',$m[1])?' ':$m[0];
        },$title);
        $title=$this->normalizer->albumTitle($title);
        if (preg_match('/^G\.?\s*H\.?$/iu',$title)) $title='Greatest Hits';
        return $this->text(str_replace('&',' and ',$title));
    }

    public function resolve(string $title,?array $verified=null): ?array
    {
        if ($verified && ($verified['title_hash']??'')===hash('sha256',$title) && !empty($verified['artist']) && !empty($verified['album'])) return $verified;
        $name=preg_replace('/\p{Cf}/u','',html_entity_decode($title,ENT_QUOTES|ENT_HTML5,'UTF-8'));
        $name=str_replace(['\\t','\\n','\\r'],[' ',' ',' '],$name);
        $name=trim(preg_replace('/^(?:VINILOS?|VNILO|VIN\s+ILO|VINYL|CDS?|LP)\s+/iu','',$name));
        if (preg_match('/^(?:VARIOUS ARTISTS|VARIOS ARTISTAS)\s+(.+)$/iu',$name,$various)) {
            $album=$this->normalizer->albumTitle($various[1]);
            if ($album!=='') return ['artist'=>'Varios Artistas','album'=>$album,'evidence'=>'Compilación con marcador explícito de varios artistas'];
        }
        preg_match_all('/\s+|(?<=[\p{L}\p{N}])[-–—](?=[\p{L}\p{N}])/u',$name,$boundaries,PREG_OFFSET_CAPTURE);
        $index=$this->index(); $candidates=[]; $exact=[];
        foreach ($boundaries[0] as [$separator,$end]) {
            $prefix=trim(substr($name,0,$end));
            if (preg_match('/[,;:]$/u',$prefix)) continue;
            $n=count(preg_split('/\s+/u',$prefix));
            if ($n>12 || !preg_match('/[\p{L}\p{N}]/u',preg_split('/\s+/u',$prefix)[$n-1])) continue;
            $album=$this->normalizer->albumTitle(trim(preg_replace('/^[\s\/–—-]+/u','',substr($name,$end))));
            if ($album==='' || preg_match('/^(?:\d*\s*(?:LP|CD)|VINILO|VINYL)$/iu',$album)
                || preg_match('/^(?:AND\b|&|FEAT\b|FT\b|WITH\b|VS\b)/iu',$album)) continue;
            foreach ($index[$this->text($prefix)]??[] as $id=>$entry) {
                if ($entry['comma'] && !str_contains($prefix,',')) continue;
                $candidate=['artist'=>$entry['artist'],'album'=>$album,'evidence'=>'Artista conocido y límite de palabras inequívoco'];
                $albumKey=$this->albumKey($album);
                if (isset($entry['albums'][$albumKey])) {
                    $candidate['album']=$entry['albums'][$albumKey];
                    $candidate['evidence']='Artista y álbum completos coinciden con otra tienda';
                    $exact[$id.'|'.$albumKey]=$candidate;
                }
                $generic=preg_match('/^(?:love|air|can|yes|down|live|heart|america|band|ghost|europe|boston|chicago|kansas|rainbow|bread|kiss)$/',$id);
                if ($generic && !isset($entry['albums'][$albumKey])) continue;
                $supported=!$generic && (($entry['stores']>=2 && $entry['album_count']>=2) || (strlen($id)>=5 && $entry['album_count']>=3));
                $candidates[$id.'|'.$albumKey]=$candidate+['words'=>$n,'inverted'=>$entry['comma'],'supported'=>$supported,
                    'prefix'=>$this->text($prefix),'stores'=>$entry['stores'],'album_count'=>$entry['album_count']];
            }
        }
        if (count($exact)===1) return array_values($exact)[0];
        if (count($exact)>1) return null;
        if (count($candidates)>1) {
            $ordered=array_values($candidates);
            usort($ordered,fn($a,$b)=>strlen($b['prefix'])<=>strlen($a['prefix']));
            $best=$ordered[0];$specific=$best['stores']>=2 && $best['album_count']>=3;
            foreach (array_slice($ordered,1) as $other) {
                if (!str_starts_with($best['prefix'],$other['prefix'].'-')) $specific=false;
            }
            if (!$specific) return null;
            $best['evidence']='Nombre completo de artista más específico, respaldado por varias tiendas y álbumes';
            return array_intersect_key($best,array_flip(['artist','album','evidence']));
        }
        if (count($candidates)===1) {
            $candidate=array_values($candidates)[0];
            // Short, one-word artist names require the known album as well.
            if ($candidate['words']>=2 || $candidate['inverted'] || $candidate['supported']) {
                if ($candidate['words']===1 && !$candidate['inverted']) $candidate['evidence']='Artista de una palabra respaldado por varias referencias del catálogo';
                return array_intersect_key($candidate,array_flip(['artist','album','evidence']));
            }
        }
        // An explicit delimiter is usable even for an artist not in the catalog.
        if (preg_match('/^(.+?)\s*(?:[-–—]|\/)\s+(.+)$/u',$name,$parts)) {
            $artist=trim($parts[1]);$album=$this->normalizer->albumTitle($parts[2]);
            if ($artist!=='' && $album!=='' && !preg_match('/^(?:\d*\s*(?:LP|CD)|VINILO|VINYL)$/iu',$album))
                return ['artist'=>$artist,'album'=>$album,'evidence'=>'Separador explícito de artista y álbum en Billboard'];
        }
        if (preg_match('/^([^,\s]+),\s*([\p{L}.]+)[–—-](.+)$/u',$name,$parts)) {
            $album=$this->normalizer->albumTitle($parts[3]);
            if ($album!=='') return ['artist'=>$parts[2].' '.$parts[1],'album'=>$album,
                'evidence'=>'Apellido,nombre y guion explícitos separan artista y álbum'];
        }
        return null;
    }
}
