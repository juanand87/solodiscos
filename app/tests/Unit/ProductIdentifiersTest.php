<?php
namespace Tests\Unit;

use App\Services\ProductIdentifiers;
use PHPUnit\Framework\TestCase;

class ProductIdentifiersTest extends TestCase
{
    public function test_upc_and_ean_with_leading_zero_are_equivalent_only_when_checksum_is_valid(): void
    {
        $codes=new ProductIdentifiers;
        $this->assertSame('00696998021211',$codes->gtin('0696998021211'));
        $this->assertSame($codes->gtin('0696998021211'),$codes->gtin('696998021211'));
        foreach (['0696998021212','SKU-00123','000000000000','12345'] as $invalid) $this->assertNull($codes->gtin($invalid));
    }

    public function test_catalog_codes_and_local_skus_keep_their_original_values(): void
    {
        $items=(new ProductIdentifiers)->extract(['sku'=>'LOCAL-00012'],"<p>N° CATALOGO 696998021211</p><p>COLUMBIA RECORDS</p>");
        $this->assertSame('LOCAL-00012',$items[0]['value']);
        $this->assertNull($items[0]['gtin']);
        $this->assertSame('00696998021211',$items[1]['gtin']);
    }
}
