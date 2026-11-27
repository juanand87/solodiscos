<?php
namespace App\Services;

class AlToqueReview
{
    private PuntoMusicalReview $references;
    public function __construct(private ProductNormalizer $normalizer,private ProductIdentifiers $codes)
    {
        $this->references=new PuntoMusicalReview($normalizer,$codes,'https://altoquerecords.cl');
    }
    public function enrich(string $title,array $source,string $description): array
    {
        if (preg_match('/^(?:GIFT CARD\s*[-–—]\s*ALTOQUE|SLIPMAT\b|LOGO DIGITAL\b)|\(CALENDARIO\s+\d{4}\)/iu',$title)) {
            $source['catalog_exclusion']='Artículo no musical: gift card, calendario, slipmat o recurso digital.';
            return $source;
        }
        // Keep the supplier SKU intact; derive a barcode only for this store's
        // exact N+digits convention, with a valid standard length and checksum.
        if (preg_match('/^N(\d+)$/D',(string)($source['sku']??''),$match) && ($gtin=$this->codes->gtin($match[1]))) {
            $derived=['type'=>'store_prefixed_sku','value'=>$match[1],'gtin'=>$gtin,'original'=>$source['sku']];
            $source['identifiers']=array_values(array_unique(array_merge($source['identifiers']??[],[$derived]),SORT_REGULAR));
        }
        $plain=(new ProductDescription)->fromHtml($description);
        if (!preg_match('/\b(?:COMBO|PACK PROMO)\b/iu',$title)) {
            if (preg_match('/(?:^|\n)(?:Formato|Format)\s*:\s*(.+?)(?=\n(?:Pa[ií]s|Country|Publicado|Released|G[eé]nero|Genre|Estilo|Style|Sello|Label)\s*:|$)/isu',$plain,$field)) {
                if (preg_match('/^(?:\d+\s*x\s*)?(Vinilos?|Vinyl|LP|CD)\b/iu',trim($field[1]),$format)) {
                    $source['declared_primary_format']=preg_match('/^CD$/i',$format[1])?'cd':'vinyl';
                    $source['declared_format_evidence']='Campo Formato/Format de la descripción: '.mb_substr(trim($field[1]),0,160);
                }
            }
            if (empty($source['declared_primary_format']) && preg_match('/\([^)]*(?:\bVINIL[OY]?\b|\b(?:7|10|12)\s*["″”¨]|\b(?:33|45)\s*RPM\b)[^)]*\)/iu',$title)) {
                $source['declared_primary_format']='vinyl';
                $source['declared_format_evidence']='Formato de vinilo, diámetro o velocidad explícitos en el título de Al Toque';
            }
        }
        if (preg_match('/^(.+?)\s+\.\s+(.+)$/u',$title,$parts)) {
            $source['artist']=trim($parts[1]);$source['album']=$this->normalizer->albumTitle($parts[2]);
            $source['identity_evidence']='Separador explícito con punto rodeado de espacios';
        }
        return $this->references->enrich($title,$source,$description);
    }
}
