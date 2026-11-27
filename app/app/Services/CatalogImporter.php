<?php
namespace App\Services;
use App\Models\{Store,Offer,Album,ScrapeRun};
use Illuminate\Support\Facades\DB;
use RuntimeException;
class CatalogImporter {
    private int $invalidPrices=0;
    private array $knownExternalIds=[];
    private int $ignoredUnavailable=0;
    public function __construct(private StoreClient $client, private ProductNormalizer $normalizer, private BillboardIdentityResolver $billboard,private PuntoMusicalReview $punto,private AlToqueReview $altoque,private NeedleIdentityResolver $needle) {}
    public function run(ScrapeRun $run): void {
        $this->invalidPrices=0;
        $store=$run->store;
        $this->knownExternalIds=array_fill_keys($store->offers()->pluck('external_id')->all(),true);$this->ignoredUnavailable=0;
        if (!$store->active) { $run->update(['status'=>'cancelled','message'=>'Tienda pausada.','finished_at'=>now()]); return; }
        $run->update(['status'=>'running','started_at'=>now(),'heartbeat_at'=>now()]); $store->update(['last_attempt_at'=>now()]);
        $seen=[]; $skipped=0;$invalidBsale=0; $started=$store->cycle_started_at??now()->startOfSecond();
        $store->update(['cycle_started_at'=>$started]);
        if (!$store->logo_url && !$store->logo_checked_at) {
            try { app(StoreBranding::class)->discover($store); } catch (\Throwable) { /* Branding never prevents a catalogue update. */ }
        }
        try {
            $shopifyCollections=$store->platform==='shopify'?(config('scraping.shopify_collections',[])[parse_url($store->url,PHP_URL_HOST)]??[]):[];
            $reported=0;
            for ($page=$store->next_page??1;$page<=200;$page++) {
                $run->update(['current_page'=>$page,'heartbeat_at'=>now()]);
                $batch=null;
                if ($store->platform==='bsale') {
                    $run->update(['message'=>'Colección '.app(BsaleCatalog::class)->collections($store->url)[$store->bsale_collection??0].' · consultando página '.$page.'.']);
                    $batch=app(BsaleCatalog::class)->page($store->url,(int)($store->bsale_collection??0),$page);$products=$batch['products'];$invalidBsale+=$batch['skipped'];
                } else {
                    $path=$store->platform==='shopify'?'/products.json':'/wp-json/wc/store/v1/products';
                    if ($store->platform==='woocommerce') $path=config('scraping.woocommerce_endpoints',[])[parse_url($store->url,PHP_URL_HOST)]??$path;
                    if ($shopifyCollections) {
                        $slug=$shopifyCollections[$store->shopify_collection??0]??throw new RuntimeException('Colección Shopify no reconocida.');
                        $path='/collections/'.$slug.'/products.json';
                        $run->update(['message'=>'Colección '.$slug.' · consultando página '.$page.'.']);
                    }
                    $data=$this->client->get($store->url,$path,
                        $store->platform==='shopify'?['limit'=>100,'page'=>$page]:['per_page'=>100,'page'=>$page,'stock_status'=>['instock','outofstock','onbackorder']]);
                    $products=$store->platform==='shopify'?($data['products']??null):$data;
                }
                if (!is_array($products) || !array_is_list($products)) throw new RuntimeException('Formato de catálogo inesperado.');
                if (!$products && $store->platform!=='bsale' && !$shopifyCollections) break;
                foreach ($products as $product) {
                    if ($store->platform==='shopify') {
                        foreach ($product['variants']??[] as $variant) {
                            $id=(string)($variant['id']??'');
                            if (!$id) throw new RuntimeException('Variante sin identificador.');
                            $variantStock=array_key_exists('available',$variant)?($variant['available']?'in_stock':'out_of_stock'):'unknown';
                            if(!$this->shouldImport($id,$variantStock))continue;
                            $title=($product['title']??'').' '.(($variant['title']??'')==='Default Title'?'':($variant['title']??''));
                            $metadata=($product['product_type']??'').' '.(is_array($product['tags']??null)?implode(' ',$product['tags']):($product['tags']??''));
                            $seen[]=$this->save($store,$id,trim($title),$metadata,$product['body_html']??'',rtrim($store->url,'/').'/products/'.($product['handle']??'').'?variant='.$id,
                                $product['images'][0]['src']??null,$variant['price']??null,array_key_exists('available',$variant)?($variant['available']?'in_stock':'out_of_stock'):'unknown',$store->currency,
                            ['product_type'=>$product['product_type']??'','vendor'=>$product['vendor']??'','reported_stock_quantity'=>(!array_key_exists('inventory_management',$variant) || $variant['inventory_management'])?($variant['inventory_quantity']??null):null,'tags'=>is_array($product['tags']??null)?$product['tags']:explode(',',$product['tags']??''),'sku'=>$variant['sku']??null,'barcode'=>$variant['barcode']??null,'variant_title'=>($variant['title']??'')==='Default Title'?'':($variant['title']??'')]);
                        }
                    } elseif ($store->platform==='bsale') {
                        if(!$this->shouldImport((string)$product['id'],$product['availability']))continue;
                        $seen[]=$this->save($store,$product['id'],$product['title'],$product['metadata'],$product['description'],$product['url'],$product['image'],$product['price'],$product['availability'],$store->currency,$product['source']);
                    } else {
                        if (($product['type']??'')==='variable') { $skipped++; continue; }
                        $prices=$product['prices']??[]; $raw=$prices['price']??null;
                        $price=is_numeric($raw)?((float)$raw / (10**(int)($prices['currency_minor_unit']??2))):null;
                        $metadata=implode(' ',array_column($product['categories']??[],'name')).' '.strip_tags($product['short_description']??'');
                        $attributeHints=[];
                        foreach ($product['attributes']??[] as $attribute) {
                            $hint=($attribute['name']??'').': '.implode(' ',array_column($attribute['terms']??[],'name'));
                            $metadata.=' '.$hint; $attributeHints[]=$hint;
                        }
                        $stock=array_key_exists('is_in_stock',$product)?($product['is_in_stock']?'in_stock':'out_of_stock'):'unknown';
                        if ($product['is_on_backorder']??false) $stock='backorder';
                        $purchaseText=($product['stock_availability']['text']??'').' '.($product['add_to_cart']['text']??'').' '.($product['add_to_cart']['single_text']??'');
                        if (preg_match('/avisarme|av[ií]same|notificar|notify me|agotad[oa]|sin (?:stock|existencias)|out of stock/iu',$purchaseText)) $stock='out_of_stock';
                        elseif (array_key_exists('is_purchasable',$product) && !$product['is_purchasable'] && $stock==='in_stock') $stock='unknown';
                        if(!$this->shouldImport((string)$product['id'],$stock))continue;
                        $seen[]=$this->save($store,(string)$product['id'],$product['name']??'',$metadata,($product['short_description']??'')."\n".($product['description']??''),$product['permalink']??$store->url,$product['images'][0]['src']??null,$price,$stock,$prices['currency_code']??$store->currency,
                            ['categories'=>array_column($product['categories']??[],'name'),'category_slugs'=>array_column($product['categories']??[],'slug'),'tags'=>array_column($product['tags']??[],'name'),'attributes'=>$attributeHints,'sku'=>$product['sku']??null,'barcode'=>$product['global_unique_id']??null,'reported_price'=>$raw,'reported_stock_quantity'=>app(StockQuantities::class)->wooReported($product)]);
                    }
                    if (count($seen)>=$reported+10) { $reported=count($seen); $run->update(['imported'=>$reported,'heartbeat_at'=>now()]); }
                }
                $run->update(['imported'=>count($seen),'heartbeat_at'=>now()]);
                if ($shopifyCollections && count($products)<100) {
                    if ((int)$store->shopify_collection+1<count($shopifyCollections)) {
                        $store->update(['shopify_collection'=>(int)$store->shopify_collection+1,'next_page'=>1]);$page=0;sleep(2);continue;
                    }
                    break;
                }
                if ($store->platform==='bsale' && !$batch['has_next']) {
                    if ((int)$store->bsale_collection+1<count(app(BsaleCatalog::class)->collections($store->url))) {
                        $store->update(['bsale_collection'=>(int)$store->bsale_collection+1,'next_page'=>1]);$page=0;sleep(2);continue;
                    }
                    break;
                }
                if ($store->platform!=='bsale' && count($products)<100) break;
                if ($page===200) throw new RuntimeException('Límite de 20.000 publicaciones alcanzado; catálogo incompleto.');
                $store->update(['next_page'=>$page+1]);
                sleep(2);
            }
            $previous=$store->offers()->count();
            $total=$store->offers()->where('checked_at','>=',$started)->count();
            if ($previous>10 && $total<$previous*0.6) throw new RuntimeException('Caída anormal del catálogo: se conservaron las ofertas ausentes para revisión.');
            $store->offers()->where('checked_at','<',$started)->whereNull('missing_since')->update(['missing_since'=>$started]);
            $run->update(['message'=>'Verificando el stock de publicaciones ausentes.','heartbeat_at'=>now()]);
            $missingStock=app(MissingStockReview::class)->review($store,20,fn($checked)=>$run->update(['heartbeat_at'=>now(),'message'=>'Verificando el stock de publicaciones ausentes: '.$checked.' revisadas.']));
            PriceHistory::invalidate();
            $message='Catálogo actualizado. Las ofertas sin stock o ausentes del recorrido completo se conservan despublicadas.';
            if($missingStock['changed'])$message.=' Se confirmó un cambio de stock en '.$missingStock['changed'].' publicaciones ausentes.';
            if ($skipped) $message.=" Se omitieron $skipped productos variables de WooCommerce: requieren conector de variantes.";
            if ($invalidBsale) $message.=" Se omitieron $invalidBsale registros Bsale sin identificador, título o enlace válido.";
            if ($this->invalidPrices) $message.=" Se dejaron {$this->invalidPrices} publicaciones sin precio válido y despublicadas para revisión.";
            if ($this->ignoredUnavailable) $message.=' Se omitieron '.$this->ignoredUnavailable.' productos sin stock confirmado que nunca se habían registrado en SoloDiscos.';
            DB::transaction(function()use($store,$run,$started,$skipped,$invalidBsale,$seen,$message){
                if(!$skipped && !$invalidBsale)app(StockQuantities::class)->missingSales($store,$started);
                $run->update(['status'=>($skipped || $invalidBsale || $this->invalidPrices)?'warning':'success','imported'=>count($seen),'message'=>$message,'finished_at'=>now()]);
                $store->update(['last_success_at'=>now(),'next_page'=>1,'cycle_started_at'=>null,'bsale_collection'=>0,'shopify_collection'=>0]);
            });
        } catch (\Throwable $e) {
            $run->update(['status'=>'failed','imported'=>count($seen),'message'=>mb_substr($e->getMessage(),0,2000),'finished_at'=>now()]);
        }
    }
    private function save(Store $store,string $external,string $title,string $metadata,string $description,string $url,?string $image,mixed $price,string $stock,string $currency,array $source=[]): int {
        // All price/history columns are DECIMAL(14,2). Bad source values must never abort the whole catalogue.
        $validPrice=is_numeric($price) && is_finite((float)$price) && (float)$price>=0 && round((float)$price,2)<=999999999999.99;
        if(!$validPrice && $price!==null && $price!==''){
            $this->invalidPrices++;
            $source['price_issue']=['reported'=>is_scalar($source['reported_price']??$price)?mb_substr((string)($source['reported_price']??$price),0,200):get_debug_type($price),
                'reason'=>'Precio inválido o fuera del rango admitido. Se conserva sin precio y no se publica.'];
        }
        $price=$validPrice?round((float)$price,2):null;
        $title=html_entity_decode($title,ENT_QUOTES|ENT_HTML5,'UTF-8');
        $source['identifiers']=app(ProductIdentifiers::class)->extract($source,$description);
        $isNeedle=in_array(parse_url($store->url,PHP_URL_HOST),['needle.cl','www.needle.cl']);
        if($isNeedle)$source=$this->needle->enrich($title,$source,$description);
        if (parse_url($store->url,PHP_URL_HOST)==='puntomusical.cl') $source=$this->punto->enrich($title,$source,$description);
        if (parse_url($store->url,PHP_URL_HOST)==='altoquerecords.cl') $source=$this->altoque->enrich($title,$source,$description);
        if (parse_url($store->url,PHP_URL_HOST)==='www.billboard.cl') {
            unset($source['artist'],$source['album']);
            $previous=Offer::where('store_id',$store->id)->where('external_id',$external)->first()?->source_metadata??[];
            $verified=$previous['verified_identity']??null;
            $identity=$this->billboard->resolve($title,$verified);
            if ($verified && ($verified['title_hash']??'')===hash('sha256',$title)) {
                $source['verified_identity']=$verified;
                $source['barcode']=$previous['barcode']??null;
                $source['identifiers']=app(ProductIdentifiers::class)->extract($source,$description);
            }
            $source['identity_pending']=$identity===null;
            $source['identity_evidence']=$identity['evidence']??'Sin separación inequívoca de artista y álbum';
            if ($identity) { $source['artist']=$identity['artist'];$source['album']=$identity['album']; }
        }
        if (!$title || !$external) throw new RuntimeException('Producto sin título o identificador.');
        $parsed=$this->normalizer->parse($title,$metadata,'unknown',$source,$description);
        if (preg_match('/\b(preventa|pre[ -]?order)\b/iu',$title.' '.$metadata)) $stock='preorder';
        return DB::transaction(function() use($store,$external,$title,$description,$url,$image,$price,$stock,$currency,$parsed,$source,$isNeedle) {
            $conditionStore=Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $conditionMode=$conditionStore->default_condition;
            $offer=Offer::firstOrNew(['store_id'=>$store->id,'external_id'=>$external]);
            $previous=$offer->exists?app(StockQuantities::class)->previous($offer):null;
            $quantity=app(StockQuantities::class)->reading($stock,$source);
            $plain=app(ProductDescription::class)->fromHtml($description);
            $values=['title'=>$title,'description'=>$plain,'url'=>$this->safeUrl($url)??$store->url,'image'=>$this->safeUrl($image),
                'price'=>is_numeric($price)&&$price>=0?$price:null,'currency'=>strtoupper($currency),'availability'=>$stock,'checked_at'=>now(),'missing_since'=>null,
                'source_metadata'=>$source,'excluded_reason'=>$parsed['excluded_reason'],'format_evidence'=>$parsed['format_evidence']];
            if (!$offer->reviewed) {
                $values['format']=$parsed['format']; $values['condition']=$parsed['condition'];
                $values['album_id']=$isNeedle?$this->needle->albumId($parsed,$source):($parsed['artist'] && !$parsed['excluded_reason']?Album::firstOrCreate(['match_key'=>$this->normalizer->key($parsed['artist'],$parsed['album'])],['artist'=>mb_substr($parsed['artist'],0,255),'title'=>mb_substr($parsed['album'],0,255)])->id:null);
            }
            if ($parsed['excluded_reason']) $values['format']='unknown';
            if(in_array($conditionMode,['new','used']))$values['condition']=$conditionMode;
            elseif($conditionMode==='category' && !$offer->reviewed)$values['condition']=app(CategoryConditions::class)->identify($conditionStore,$source);
            $values+=$quantity+['stock_quantity_checked_at'=>now()];
            $offer->fill($values); $changed=$offer->isDirty(['price','availability','currency','stock_quantity','stock_quantity_source']); $offer->save();
            $observation=['offer_id'=>$offer->id,'price'=>$offer->price,'availability'=>$stock,'currency'=>$offer->currency,
                'created_at'=>now(),'previous_checked_at'=>$previous['created_at']??null]+$quantity;
            $observationId=$changed?DB::table('offer_observations')->insertGetId($observation):null;
            app(OfferEvents::class)->record($offer,$previous,$observation,$observationId,!$store->last_success_at);
            app(PriceHistory::class)->record($offer);
            return $offer->id;
        });
    }
    private function shouldImport(string $external,string $stock): bool {
        if($stock==='in_stock' || isset($this->knownExternalIds[$external])){$this->knownExternalIds[$external]=true;return true;}
        $this->ignoredUnavailable++;return false;
    }
    private function safeUrl(?string $url): ?string { return $url && in_array(parse_url($url,PHP_URL_SCHEME),['http','https'])?$url:null; }
}
