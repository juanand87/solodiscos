<?php
namespace App\Services;
use App\Models\{CoverMatch,Offer};
use Illuminate\Support\Str;

class CoverCandidates {
    public const MIN_TITLE_SIMILARITY=0.30;
    public function __construct(private ProductNormalizer $normalizer,private PerceptualHash $hash) {}
    public function artistKey(string $artist): string { return $this->normalizer->key($artist,'__artist__'); }
    public function titleKey(string $title): string { return Str::slug($this->normalizer->albumTitle($title)); }
    public function numericConflict(string $a,string $b): bool {
        preg_match_all('/\b\d+\b/',$this->titleKey($a),$left);preg_match_all('/\b\d+\b/',$this->titleKey($b),$right);
        return $left[0] && $right[0] && $left[0]!==$right[0];
    }
    public function similarity(string $a,string $b): float {
        $a=$this->titleKey($a);$b=$this->titleKey($b);if($a==='' || $b==='')return 0;
        return 1-levenshtein(substr($a,0,255),substr($b,0,255))/max(strlen(substr($a,0,255)),strlen(substr($b,0,255)));
    }
    public function compatible(Offer $a,Offer $b): bool {
        return $a->id!==$b->id && $a->album_id!==$b->album_id && $a->album && $b->album && !$a->excluded_reason && !$b->excluded_reason
            && in_array($a->format,['cd','vinyl']) && $a->format===$b->format
            && $this->artistKey($a->album->artist)===$this->artistKey($b->album->artist)
            && !$this->numericConflict($a->album->title,$b->album->title);
    }
    public function suggest(Offer $a,Offer $b,$left,$right): ?CoverMatch {
        if (!$this->compatible($a,$b) || $left->status!=='ready' || $right->status!=='ready') return null;
        $similarity=$this->similarity($a->album->title,$b->album->title);
        if ($similarity<self::MIN_TITLE_SIMILARITY) return null;
        $distance=$this->hash->distance($left->phash,$right->phash);if($distance>10)return null;
        $signature=hash('sha256',implode('|',[$a->id,$b->id,$a->album_id,$b->album_id,$left->content_hash,$right->content_hash,PerceptualHash::VERSION]));
        return CoverMatch::firstOrCreate(['signature'=>$signature],['left_offer_id'=>$a->id,'right_offer_id'=>$b->id,'left_album_id'=>$a->album_id,'right_album_id'=>$b->album_id,
            'left_cover_id'=>$left->id,'right_cover_id'=>$right->id,'distance'=>$distance,'title_similarity'=>$similarity,'format'=>$a->format]);
    }
}
