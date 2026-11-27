<?php
namespace Tests\Unit;
use App\Services\ProductClassifier;
use PHPUnit\Framework\TestCase;
class ProductClassifierTest extends TestCase {
    public function test_multi_digit_cd_counts_and_lp_artist_are_not_misclassified(): void {
        $c=new ProductClassifier;
        $this->assertSame('cd',$c->classify('Artist – Album (56CD)')['format']);
        $this->assertSame('cd',$c->classify('LP – LOVE LINES – CD','','',['prefer_title_format'=>true])['format']);
        $this->assertSame('vinyl',$c->classify('Artist – Album (LP+CD) – VINILO','','',['prefer_title_format'=>true])['format']);
        $this->assertSame('unknown',$c->classify('Artist – Album (LP+CD)')['format']);
    }
    public function test_video_formats_are_excluded_but_cd_with_bonus_dvd_is_kept(): void {
        $c=new ProductClassifier;
        foreach(['Artist – Album – DVD','Artist – Album – BLU RAY','Artist – Album (3DVD)','DVD Player DS-DV299','HOME THEATER 3.1'] as $title) $this->assertNotNull($c->classify($title)['excluded_reason']);
        $result=$c->classify('Artist – Album (2CD+DVD)');$this->assertNull($result['excluded_reason']);$this->assertSame('cd',$result['format']);
    }
    public function test_book_type_overrides_incidental_cd_tag(): void {
        $result=(new ProductClassifier)->classify('33 1/3 Parallel Lines - Kembrew McLeod','CD Rock','',['product_type'=>'Libro','tags'=>['CD','Rock']]);
        $this->assertSame('unknown',$result['format']);$this->assertStringContainsString('Libro',$result['excluded_reason']);
    }
    public function test_book_bibliography_and_existing_series_description_are_excluded(): void {
        $c=new ProductClassifier;
        foreach(['Formato: Tapa blanda (Paperback). Páginas: 168. ISBN: 9781501302374','En este volumen de la colección 33 1/3, Kembrew McLeod examina la historia.'] as $description) {
            $result=$c->classify('33 1/3 Parallel Lines - Kembrew McLeod','CD',$description);
            $this->assertNotNull($result['excluded_reason']);$this->assertSame('unknown',$result['format']);
        }
    }
    public function test_real_records_with_book_titles_booklets_or_33_rpm_are_preserved(): void {
        $c=new ProductClassifier;
        foreach(['The Book Of Souls','Book of Dreams','Bitches Brew (2LP/180G/33 1/3RPM Audiophile Supervinyl)'] as $title) {
            $result=$c->classify($title,'','Includes a booklet',['product_type'=>'Vinilo']);
            $this->assertNull($result['excluded_reason']);$this->assertSame('vinyl',$result['format']);
        }
        $this->assertSame('cd',$c->classify('Album','', 'Incluye libro de letras y booklet.',['product_type'=>'CD'])['format']);
    }
    public function test_accessories_never_become_records_because_of_vinyl_tags(): void {
        $result=(new ProductClassifier)->classify('Escobilla para vinilos','vinilo','',['product_type'=>'Accesorio','tags'=>['vinilos']]);
        $this->assertNotNull($result['excluded_reason']);$this->assertSame('unknown',$result['format']);
    }
    public function test_variant_format_and_authoritative_type_take_precedence_over_marketing_tags(): void {
        $c=new ProductClassifier;
        $this->assertSame('cd',$c->classify('Artist - Album','Vinilo CD','',['product_type'=>'CD','tags'=>['vinilos']])['format']);
        $this->assertSame('cd',$c->classify('Artist - Album CD','Vinilo','',['product_type'=>'Vinilo','variant_title'=>'CD'])['format']);
        $this->assertSame('unknown',$c->classify('Unknown album','','A classic released on CD and vinyl.')['format']);
    }
}
