<?php
namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Str;

class BillboardBarcodeResolver
{
    public function __construct(private StoreClient $client,private ProductIdentifiers $codes) {}

    public function detailCode(string $html,string $url): ?string
    {
        $dom=new DOMDocument();$old=libxml_use_internal_errors(true);
        try { $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html,LIBXML_NONET); }
        finally { libxml_clear_errors();libxml_use_internal_errors($old); }
        foreach ((new DOMXPath($dom))->query('//script[@type="application/ld+json"]') as $script) {
            $data=json_decode($script->textContent,true);
            if (!is_array($data) || ($data['@type']??'')!=='Product' || rtrim($data['url']??'','/')!==rtrim($url,'/')) continue;
            foreach (['gtin14','gtin13','gtin12','gtin8','gtin','sku'] as $field) {
                if ($this->codes->gtin((string)($data[$field]??''))) return (string)$data[$field];
            }
        }
        return null;
    }

    private function text(string $value): string
    {
        return Str::slug(str_replace(['&',','],[' and ',' '],html_entity_decode($value,ENT_QUOTES|ENT_HTML5,'UTF-8')));
    }

    private function artistKey(string $value,bool $inverted=false): string
    {
        $tokens=explode('-',$this->text(str_replace(',',' ',$value)));
        $tokens=array_values(array_diff($tokens,['the','los','las','el','la']));if($inverted)sort($tokens);
        return implode('-',$tokens);
    }

    public function select(array $data,string $code,string $title,string $format): ?array
    {
        if (!isset($data['count'],$data['releases']) || $data['count']>count($data['releases'])) return null;
        $gtin=$this->codes->gtin($code);if (!$gtin) return null;
        $original=$this->text((new ProductNormalizer)->albumTitle(preg_replace('/^(?:VINILOS?|VINYL|CDS?|LP)\s+/iu','',$title)));
        $matches=[];
        foreach ($data['releases'] as $release) {
            if (!Str::isUuid($release['id']??'')) continue;
            if ($this->codes->gtin((string)($release['barcode']??''))!==$gtin || ($release['status']??'')!=='Official') continue;
            $media=array_column($release['media']??[],'format');
            if (!$media) continue;
            foreach ($media as $medium) {
                $valid=$format==='vinyl'?preg_match('/vinyl/iu',$medium):($format==='cd' && preg_match('/^CD$/i',$medium));
                if (!$valid) continue 2;
            }
            $artist='';
            foreach ($release['artist-credit']??[] as $credit) $artist.=($credit['name']??'').($credit['joinphrase']??'');
            $album=$release['release-group']['title']??$release['title']??'';
            $albumKey=$this->text($album);if ($artist==='' || strlen($albumKey)<2) continue;
            $position=strpos('-'.$original.'-','-'.$albumKey.'-');
            if ($position===false) continue;
            $prefix=substr($original,0,max(0,$position));
            $artistAgrees=$this->artistKey($prefix)===$this->artistKey($artist)
                || (str_contains($title,',') && $this->artistKey($prefix,true)===$this->artistKey($artist,true));
            $selfTitled=$original===$albumKey && $this->artistKey($artist)===$this->artistKey($album);
            if (!$artistAgrees && !$selfTitled) continue;
            $key=$this->artistKey($artist).'|'.$albumKey;
            $matches[$key]=['artist'=>$artist,'album'=>$album,'evidence'=>'Código GTIN, formato, artista y álbum confirmados en MusicBrainz',
                'gtin'=>$gtin,'release_id'=>$release['id'],'source_url'=>'https://musicbrainz.org/release/'.$release['id'],'title_hash'=>hash('sha256',$title)];
        }
        return count($matches)===1?array_values($matches)[0]:null;
    }

    public function lookup(string $code): array
    {
        $gtin=$this->codes->gtin($code);if (!$gtin) throw new \RuntimeException('GTIN inválido');
        $path=storage_path('app/private/musicbrainz/'.$gtin.'.json');
        if (is_file($path) && filemtime($path)>time()-7*86400) return json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        if (!is_dir(dirname($path))) mkdir(dirname($path),0775,true);
        // One sequential enrichment command; pause even on an uncached miss.
        usleep(1100000);
        $query='barcode:'.$code;
        $data=$this->client->get('https://musicbrainz.org','/ws/2/release/',['query'=>$query,'fmt'=>'json','limit'=>100],false);
        if (!isset($data['count'],$data['releases'])) throw new \RuntimeException('Respuesta MusicBrainz incompleta');
        file_put_contents($path,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX);
        return $data;
    }
}
