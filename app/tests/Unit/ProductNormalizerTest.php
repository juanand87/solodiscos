<?php
namespace Tests\Unit;

use App\Services\ProductNormalizer;
use PHPUnit\Framework\TestCase;

class ProductNormalizerTest extends TestCase
{
    public function test_cd_packaging_is_not_part_of_album_identity(): void
    {
        $n=new ProductNormalizer;
        $this->assertSame('30',$n->albumTitle('30 CD Jewel Case'));
        $this->assertSame($n->key('Adele','30'),$n->key('ADELE','30 CD Jewel Case'));
        $this->assertSame('Album',$n->albumTitle('Album CD Digipak'));
        $this->assertSame('Jewel Case',$n->albumTitle('Jewel Case'));
        $this->assertNotSame($n->key('Adele','25'),$n->key('Adele','30 CD Jewel Case'));
    }
    public function test_explicit_edition_condition_and_packaging_notes_do_not_split_the_album(): void
    {
        $n=new ProductNormalizer;
        $this->assertSame('WISH YOU WERE HERE',$n->albumTitle('WISH YOU WERE HERE (VINILO SIMPLE) (2DA MANO | UK 1975) (INCLUYE POSTAL ORIGINAL)'));
        $this->assertSame('THREE CHEERS FOR SWEET REVENGE',$n->albumTitle('THREE CHEERS FOR SWEET REVENGE (VINILO DOBLE) (LIMITED EDITION) (RED & BLACK MARBLE)'));
        $this->assertSame('THE SCORE',$n->albumTitle('THE SCORE (VINILO DOBLE) (LIMITED EDITION | CLEAR W/ SMOKY WHITE SWIRLS)'));
        $this->assertSame('MTV Unplugged (Live in New York)',$n->albumTitle('MTV Unplugged (Live in New York)'));
        $this->assertSame('used',$n->parse('Artist - Album','Vinilo usado')['condition']);
        $this->assertSame('new',$n->parse('Artist - Album','Nuevos & Reediciones')['condition']);
    }
    public function test_spacing_around_artist_punctuation_matches_without_erasing_word_boundaries(): void
    {
        $n=new ProductNormalizer;
        $this->assertSame($n->key('AC/DC','BLOW UP YOUR VIDEO'),$n->key('AC / DC','Blow  Up Your Video VINILO'));
        $this->assertSame($n->key('Earth, Wind & Fire','Album'),$n->key('Earth, Wind&Fire','Album'));
        $this->assertNotSame($n->key('A BC','Album'),$n->key('AB C','Album'));
        $this->assertNotSame($n->key('AC/DC','Live'),$n->key('AC/DC','Live at River Plate'));
    }
    public function test_invisible_direction_marks_do_not_prevent_artist_album_matching(): void
    {
        $normalizer = new ProductNormalizer;
        foreach (["Supertramp \u{200E}- Crime Of The Century LP", "Supertramp &#8206;– Crime Of The Century LP"] as $title) {
            $parsed = $normalizer->parse($title);
            $this->assertSame('Supertramp', $parsed['artist']);
            $this->assertSame('Crime Of The Century', $parsed['album']);
            $this->assertSame('vinyl', $parsed['format']);
        }
    }
}
