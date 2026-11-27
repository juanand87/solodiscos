<?php
namespace App\Services;

class ProductIdentifiers
{
    public function gtin(string $value): ?string
    {
        $digits=preg_replace('/[\s-]+/u','',trim($value));
        if (!preg_match('/^(?:\d{8}|\d{12}|\d{13}|\d{14})$/D',$digits) || !trim($digits,'0')) return null;
        $sum=0; $weight=3;
        for ($i=strlen($digits)-2;$i>=0;$i--) { $sum+=(int)$digits[$i]*$weight; $weight=$weight===3?1:3; }
        if ((10-$sum%10)%10!==(int)substr($digits,-1)) return null;
        return str_pad($digits,14,'0',STR_PAD_LEFT);
    }

    public function extract(array $source,string $description): array
    {
        $result=[];
        foreach (['sku','barcode','catalog_number'] as $field) {
            if (is_scalar($source[$field]??null) && trim((string)$source[$field])!=='') {
                $raw=trim((string)$source[$field]);
                $result[]=['type'=>$field,'value'=>$raw,'gtin'=>$this->gtin($raw)];
            }
        }
        $text=(new ProductDescription)->fromHtml($description);
        preg_match_all('/(?:\bSKU|\bEAN|\bUPC|\bGTIN|\bN[°º.]?\s*CAT[AÁ]LOGO|\bCAT[AÁ]LOGO)\s*[:#-]?\s*([A-Z0-9][A-Z0-9.\/-]{3,40})/iu',$text,$matches,PREG_SET_ORDER);
        foreach ($matches as $match) $result[]=['type'=>'description','value'=>$match[1],'gtin'=>$this->gtin($match[1])];
        return array_values(array_unique($result,SORT_REGULAR));
    }
}
