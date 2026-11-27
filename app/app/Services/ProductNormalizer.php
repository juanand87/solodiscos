<?php
namespace App\Services;
use Illuminate\Support\Str;
class ProductNormalizer {
    public function parse(string $title, string $metadata = '', string $default = 'unknown',array $source=[],string $description=''): array {
        $classification=(new ProductClassifier)->classify($title,$metadata,$description,$source);
        $conditionText=$this->conditionContext($metadata);
        $used = preg_match('/\b(usados?|usadas?|used|segunda mano|preowned)\b/', $conditionText);
        $new = preg_match('/\b(nuevos?|nuevas?|new|sellados?|sealed)\b/', $conditionText);
        if (!$used && !$new) {
            $titleCondition=$this->conditionContext($title);
            $used=preg_match('/\b(usados?|usadas?|segunda mano|preowned)\b|[\[(]used[\])]/',$titleCondition);
            $new=preg_match('/\b(nuevos?|nuevas?|sellados?)\b|[\[(](?:new|sealed)[\])]/',$titleCondition);
        }
        $conditionDescription=$this->conditionContext(app(ProductDescription::class)->fromHtml($description));
        $used=$used || preg_match('/(?:^|\n)\s*(?:(?:estado|condicion)(?:\s+del\s+(?:disco|producto|vinilo|cd))?\s*:\s*|(?:vinilo|disco|cd|producto|cassette)\s+)(?:usad[oa]|used|segunda mano)\b/m',$conditionDescription);
        $new=$new || preg_match('/(?:^|\n)\s*(?:estado|condicion)(?:\s+del\s+(?:disco|producto|vinilo|cd))?\s*:\s*(?:nuev[oa]|new|sellad[oa]|sealed)\b|\bnuev[oa]\s+y\s+sellad[oa]\b/m',$conditionDescription);
        $title=preg_replace('/\p{Cf}/u','',html_entity_decode($title,ENT_QUOTES|ENT_HTML5,'UTF-8'));
        $title=preg_replace('/^\s*\((?:PREVENTA|PRE[ -]?ORDER)\)\s*/iu','',$title);
        $parts = preg_split('/\s+[-–—]\s+/u', $title, 2);
        $artist = count($parts)===2 ? trim($parts[0]) : null;
        $album = count($parts)===2 ? trim($parts[1]) : $title;
        if (!empty($source['artist']) && !empty($source['album'])) { $artist=$source['artist'];$album=$source['album']; }
        if ($source['identity_pending']??false) $artist=null;
        $album = $this->albumTitle($album);
        return array_merge($classification,['artist'=>$artist,'album'=>$album ?: $title,'condition_evidence'=>$used&&$new?'conflicting':($used?'used':($new?'new':null)),
            'condition'=>$used && $new ? 'unknown' : ($used ? 'used' : ($new ? 'new' : $default))]);
    }
    private function conditionContext(string $text): string {
        $text=preg_replace_callback('/\\\\u([0-9a-f]{4})/i',fn($m)=>json_decode('"\\u'.$m[1].'"')??$m[0],$text);
        $text=Str::lower(Str::ascii($text));
        return preg_replace('/\b(?:new\s+(?:wave|age|order|york|romantic|jack\s+swing)|nuev[ao]\s+(?:ola|cancion(?:\s+chilena)?|trova|troba|era|tango|york)|canto\s+nuevo|the\s+used)\b/',' ',$text);
    }
    public function albumTitle(string $title): string {
        $title=html_entity_decode($title,ENT_QUOTES|ENT_HTML5,'UTF-8');
        $title=preg_replace('/\s+(?:[1-9]\d*\s*)?CD\s+(?:jewel\s*case|digi(?:pack|pak))\s*$/iu','',$title);
        $title=preg_replace('/\s*\|\s*(?:VINILOS?|VINYL|CDS?|LP)\s*$/iu','',$title);
        $title=preg_replace('/\s*\((?:limited edition|special edition|segunda mano|2da mano|incluye postal)(?:\b[^)]*)\)\s*/iu',' ',$title);
        $title=preg_replace('/\s*[\[(](?:cds?|vinilos?|vinyl|[1-9]\d*\s*cds?|[1-9]?\s*lp|edici[oó]n|edition|remaster(?:ed|izado)?|180\s*(?:g|gr)|digipack|gatefold|color(?:ed)?|usad[oa]|nuev[oa]|used|new|sealed)(?:\b[^\])]*?)[\])]\s*/iu',' ',$title);
        $title=preg_replace('/\s*\((?:picture disc|(?:white|red|blue|black|clear|green|yellow|pink|purple|orange|silver|gold|trans)[^)]*\b(?:vinyl|marble|splatter|swirls?))\)\s*/iu',' ',$title);
        $title=preg_replace('/\s+(?:[-–—]\s*)?(?:[1-9]?\s*cds?|vinilos?|vinyl|[1-9]?\s*lp|180\s*(?:g|gr))$/iu','',$title);
        return trim(preg_replace('/\s+/u',' ',$title));
    }
    private function identityText(string $value): string {
        $value=preg_replace('/\p{Cf}/u','',html_entity_decode($value,ENT_QUOTES|ENT_HTML5,'UTF-8'));
        $value=preg_replace('/\s*([\/&+])\s*/u','$1',$value);
        return Str::slug(preg_replace('/\s+/u',' ',trim($value)));
    }
    public function key(string $artist,string $album): string { return hash('sha256',$this->identityText($artist).'|'.$this->identityText($this->albumTitle($album))); }
}
