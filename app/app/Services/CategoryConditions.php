<?php
namespace App\Services;

use App\Models\Store;
use Illuminate\Support\Str;

class CategoryConditions {
    public function rules(?string $text): array {
        return array_values(array_unique(array_filter(array_map(
            fn($value) => Str::slug(html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            preg_split('/\R/u', $text ?? '') ?: []
        ))));
    }

    public function identify(Store $store, array $source): string {
        $categories=$source['categories']??[];
        if(!is_array($categories))$categories=[$categories];
        $names=[];
        foreach($categories as $category){
            if(is_string($category))$names[]=$category;
            elseif(is_array($category))foreach(['name','slug'] as $field)if(is_string($category[$field]??null))$names[]=$category[$field];
        }
        foreach($source['category_slugs']??[] as $slug)if(is_string($slug))$names[]=$slug;
        // Bsale exposes the collection used to retrieve the product.
        if(is_string($source['collection']??null))$names[]=$source['collection'];
        $keys=$this->rules(implode("\n",$names));
        $new=(bool)array_intersect($keys,$this->rules($store->new_condition_categories));
        $used=(bool)array_intersect($keys,$this->rules($store->used_condition_categories));
        if(!$keys || ($new && $used))return 'unknown';
        if($new || $used)return $new?'new':'used';
        return in_array($store->category_condition_fallback,['new','used'],true)?$store->category_condition_fallback:'unknown';
    }
}
