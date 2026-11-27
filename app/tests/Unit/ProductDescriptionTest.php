<?php
namespace Tests\Unit;
use App\Services\ProductDescription;
use PHPUnit\Framework\TestCase;

class ProductDescriptionTest extends TestCase {
    public function test_html_keeps_one_line_per_detail_and_track(): void {
        $html="<p>N° CATALOGO 722537996327<br />\nFUSION SPA / DAAM<br />\nMADE IN CHILE<br />\nVINILO<br />\n2021</p>\n<p>TRACKLIST</p>\n<p>A1 La Voz De Los ’80<br />\nA2 Brigada De Negro</p>";
        $this->assertSame("N° CATALOGO 722537996327\nFUSION SPA / DAAM\nMADE IN CHILE\nVINILO\n2021\n\nTRACKLIST\nA1 La Voz De Los ’80\nA2 Brigada De Negro",(new ProductDescription)->fromHtml($html));
    }
    public function test_existing_descriptions_are_compacted_idempotently(): void {
        $formatter=new ProductDescription;
        $clean=$formatter->fromText("\r\n VINILO \r\n\r\n\r\n2021\r\n\r\nTRACKLIST\r\n\r\nA1 La Voz\u{00A0}De Los ’80\r\n\r\nB1 ¿Quien Mato A Marilyn?\r\n");
        $this->assertSame("VINILO\n2021\n\nTRACKLIST\nA1 La Voz De Los ’80\nB1 ¿Quien Mato A Marilyn?",$clean);
        $this->assertSame($clean,$formatter->fromText($clean));
    }
    public function test_table_cells_stay_separated_without_executable_content(): void {
        $this->assertSame("A1 La Voz & El Eco 3:20\nA2 Sexo 4:00",(new ProductDescription)->fromHtml('<script>alert(1)</script><table><tr><td>A1</td><td>La Voz &amp; El Eco</td><td>3:20</td></tr><tr><td>A2</td><td>Sexo</td><td>4:00</td></tr></table>'));
    }
}
