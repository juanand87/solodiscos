<?php
namespace Tests\Unit;

use App\Services\{BillboardBarcodeResolver,ProductIdentifiers,StoreClient};
use PHPUnit\Framework\TestCase;

class BillboardBarcodeTest extends TestCase
{
    private function resolver(): BillboardBarcodeResolver { return new BillboardBarcodeResolver(new StoreClient,new ProductIdentifiers); }
    private function data(array $extra=[]): array {
        return ['count'=>1,'releases'=>[array_replace(['id'=>'c632ec32-79cf-3da9-ba4d-45de6eac5821','status'=>'Official','title'=>'Everything All the Time','barcode'=>'098787069013',
            'artist-credit'=>[['name'=>'Band of Horses']],'media'=>[['format'=>'12" Vinyl']]],$extra)]];
    }
    public function test_detail_reads_only_product_schema_for_the_requested_url_and_valid_codes(): void {
        $url='https://www.billboard.cl/product/album';
        $html='<script type="application/ld+json">'.json_encode(['@type'=>'Product','url'=>$url,'sku'=>'098787069013']).'</script>';
        $this->assertSame('098787069013',$this->resolver()->detailCode($html,$url));
        $this->assertNull($this->resolver()->detailCode($html,$url.'-other'));
        $this->assertNull($this->resolver()->detailCode(str_replace('098787069013','098787069014',$html),$url));
    }
    public function test_valid_code_alone_never_overrides_conflicting_title_artist_or_format(): void {
        $r=$this->resolver();$code='098787069013';
        $match=$r->select($this->data(),$code,'VINILO BAND OF HORSES Everything All the Time','vinyl');
        $this->assertSame('Band of Horses',$match['artist']);
        $this->assertNull($r->select($this->data(),$code,'VINILO OTHER ARTIST Everything All the Time','vinyl'));
        $this->assertNull($r->select($this->data(),$code,'VINILO BAND OF HORSES Other Album','vinyl'));
        $this->assertNull($r->select($this->data(),$code,'CD BAND OF HORSES Everything All the Time','cd'));
        $this->assertNull($r->select($this->data(['barcode'=>'602547834584']),$code,'VINILO BAND OF HORSES Everything All the Time','vinyl'));
    }
    public function test_incomplete_and_ambiguous_results_are_never_selected_by_score(): void {
        $d=$this->data();$d['count']=2;
        $this->assertNull($this->resolver()->select($d,'098787069013','VINILO BAND OF HORSES Everything All the Time','vinyl'));
        $d['releases'][]=$d['releases'][0];$d['releases'][1]['artist-credit']=[['name'=>'Horses of Band']];
        $this->assertNull($this->resolver()->select($d,'098787069013','VINILO BAND,OF HORSES Everything All the Time','vinyl'));
    }
    public function test_surname_order_and_self_titled_album_must_agree_with_verified_metadata(): void {
        $d=$this->data(['title'=>'Love and Hate','artist-credit'=>[['name'=>'Michael Kiwanuka']]]);
        $this->assertSame('Michael Kiwanuka',$this->resolver()->select($d,'098787069013','VINILO KIWANUKA,MICHAEL LOVE AND HATE 2LP','vinyl')['artist']);
        $d=$this->data(['title'=>'Example Band','artist-credit'=>[['name'=>'Example Band']]]);
        $this->assertNotNull($this->resolver()->select($d,'098787069013','VINILO EXAMPLE BAND LP','vinyl'));
    }
}
