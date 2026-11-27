<?php
namespace App\Services;

class ProductDescription {
    public function fromHtml(string $html): string {
        $html=preg_replace('~<(script|style)\b[^>]*>.*?</\1>~isu','',$html);
        $html=preg_replace('~</(?:td|th)\s*>~iu',"\t",$html);
        $html=preg_replace('~<br\b[^>]*>|</(?:p|div|h[1-6]|tr|li|ul|ol|section|blockquote)\s*>~iu',"\n",$html);
        return $this->fromText(html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8'));
    }

    public function fromText(string $text): string {
        $text=str_replace(["\u{00A0}","\u{200B}","\u{FEFF}"],[' ','',''],$text);
        $lines=[];
        foreach (preg_split('/\R/u',$text) as $line) {
            $line=trim(preg_replace('/[^\S\r\n]+/u',' ',$line));
            if ($line==='') continue;
            if ($lines && preg_match('/^(?:track\s*list(?:ing)?|lista(?:do)? de (?:temas|canciones)|lado\s+[a-z]|disco\s+\d+)\s*[:.]?$/iu',$line)) $lines[]='';
            $lines[]=$line;
        }
        return implode("\n",$lines);
    }
}
