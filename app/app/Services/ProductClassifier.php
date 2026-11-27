<?php
namespace App\Services;
use Illuminate\Support\Str;

class ProductClassifier {
    private function text(string $value): string { return Str::lower(Str::ascii(html_entity_decode(strip_tags($value),ENT_QUOTES|ENT_HTML5,'UTF-8'))); }
    private function format(string $text): string {
        $text=$this->text($text);
        $cd=preg_match('/\b(cds?|compact discs?|[1-9]\d*\s*cds?)\b/',$text);
        $vinyl=preg_match('/\b(vinilos?|vinyls?|lp|[1-9]\d*\s*lp)\b/',$text);
        return $cd && $vinyl?'ambiguous':($cd?'cd':($vinyl?'vinyl':'unknown'));
    }
    public function classify(string $title,string $metadata='',string $description='',array $source=[]): array {
        if (!empty($source['catalog_exclusion'])) return ['format'=>'unknown','excluded_reason'=>$source['catalog_exclusion'],'format_evidence'=>null];
        $type=$this->text((string)($source['product_type']??''));
        $body=$this->text($description); $name=$this->text($title);
        $nonMusic='/\b(libros?|books?|revistas?|magazines?|paperback|hardcover|ropa|poleras?|camisetas?|t[ -]?shirts?|accesorios?|tornamesas?|tocadiscos|amplificadores?|parlantes?|audifonos|agujas?|capsulas?|escobillas?|fundas?|limpiadores?)\b/';
        $reason=null;
        if (preg_match('/^(?:dvd|blu[ -]?ray|cassettes?)$/',$type)) return ['format'=>'unknown','excluded_reason'=>'Formato fuera del catálogo: solo se publican CDs y vinilos.','format_evidence'=>null];
        if ($type && preg_match($nonMusic,$type)) $reason='Producto no musical: tipo de origen «'.mb_substr($source['product_type'],0,100).'».';
        elseif (preg_match('/\b(?:formato|encuadernacion)\s*:\s*(?:tapa (?:blanda|dura)|paperback|hardcover)\b/',$body)
            || (preg_match('/\bisbn\s*:?\s*[0-9][0-9 -]{8,}/',$body) && preg_match('/\bpaginas\s*:?\s*\d+/',$body))
            || preg_match('/\ben este volumen de la coleccion\s+33\s*(?:1\s*\/\s*3|⅓)/',$body)) $reason='Libro identificado por sus datos bibliográficos o descripción editorial.';
        elseif ($this->format($type)==='unknown' && preg_match('/^(?:(?:libro|book|revista)\s*[:–-]|(?:polera|camiseta|tornamesa|tocadiscos|escobilla|funda|aguja|capsula|amplificador|parlante)\b)/',$name)) $reason='Producto no musical identificado explícitamente en el título.';
        if ($reason) return ['format'=>'unknown','excluded_reason'=>$reason,'format_evidence'=>null];

        $typeFormat=$this->format($type);
        if ($typeFormat==='unknown') {
            $parts=preg_split('/\s+[-–—]\s+/u',$title,2);
            $albumText=$this->text(count($parts)===2?$parts[1]:$title);
            if (preg_match('/^(?:dvd player|home theat(?:er|re)|equipo \d+ en \d+)\b/',$name))
                return ['format'=>'unknown','excluded_reason'=>'Equipo de reproducción: fuera del catálogo de CDs y vinilos.','format_evidence'=>null];
            $videoSuffix=preg_match('/(?:[-–—]\s*|\()(?:\d*\s*)?(?:dvd|blu[ -]?ray|br|cassette)s?\)?\s*$/iu',$albumText);
            $audio=$this->format($albumText);
            $videoCategory=false;
            foreach ($source['categories']??[] as $category) if (preg_match('/\b(?:dvd|blu[ -]?ray|cassette)s?\b/',$this->text($category))) $videoCategory=true;
            if (($videoSuffix || $videoCategory) && $audio==='unknown')
                return ['format'=>'unknown','excluded_reason'=>'Formato fuera del catálogo: DVD, Blu-ray o cassette; solo se publican CDs y vinilos.','format_evidence'=>null];
        }
        if ($typeFormat==='unknown') {
            foreach(array_merge($source['categories']??[],$source['tags']??[]) as $category) {
                if (preg_match($nonMusic,$this->text((string)$category))) return ['format'=>'unknown','excluded_reason'=>'Producto no musical: categoría o etiqueta «'.mb_substr((string)$category,0,100).'».','format_evidence'=>null];
            }
        }
        $variantFormat=$this->format((string)($source['variant_title']??''));
        if (in_array($variantFormat,['cd','vinyl'])) return ['format'=>$variantFormat,'excluded_reason'=>null,'format_evidence'=>'Formato explícito de la variante'];
        if (in_array($typeFormat,['cd','vinyl'])) return ['format'=>$typeFormat,'excluded_reason'=>null,'format_evidence'=>'Tipo de producto de la tienda: '.mb_substr($source['product_type'],0,100)];
        if ($typeFormat==='ambiguous' || $variantFormat==='ambiguous') return ['format'=>'unknown','excluded_reason'=>null,'format_evidence'=>'Formatos contradictorios: requiere revisión'];
        if (in_array($source['declared_primary_format']??'',['cd','vinyl'])) return ['format'=>$source['declared_primary_format'],'excluded_reason'=>null,
            'format_evidence'=>$source['declared_format_evidence']??'Formato principal explícito'];

        if ($source['prefer_title_format']??false) {
            if (preg_match('/(?:\s[-–—]\s*|\|\s*)(CDS?|VINILOS?|VINYL|LP)\s*$/iu',$title,$label)
                || preg_match('/\(([1-9]\d*\s*(?:CDS?|LP))\)\s*$/iu',$title,$label)) {
                $declared=$this->format($label[1]);
                if (in_array($declared,['cd','vinyl'])) return ['format'=>$declared,'excluded_reason'=>null,'format_evidence'=>'Formato principal explícito al final del título: '.$label[1]];
            }
        }

        // Do not classify by free-form prose mentioning another CD or a record.
        $structured=$source?implode(' ',array_merge($source['categories']??[],$source['tags']??[],$source['attributes']??[])):$metadata;
        $format=$this->format($title.' '.$structured);
        if ($format==='unknown' && in_array($source['reference_format']??'', ['cd','vinyl']))
            return ['format'=>$source['reference_format'],'excluded_reason'=>null,'format_evidence'=>'Formato de una coincidencia única por GTIN en otra tienda'];
        if ($format==='unknown' && preg_match('/\bformato\s*:\s*(cd|vinilo|vinyl|[1-9]?\s*lp)\b/',$body,$match)) $format=$this->format($match[1]);
        return ['format'=>$format==='ambiguous'?'unknown':$format,'excluded_reason'=>null,
            'format_evidence'=>in_array($format,['cd','vinyl'])?'Formato explícito en título, categorías o campo Formato':'Sin evidencia suficiente o formatos contradictorios'];
    }
}
