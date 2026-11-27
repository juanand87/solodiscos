<?php
return ['transport'=>env('SCRAPER_TRANSPORT','http'),
    'bsale_collections'=>['www.billboard.cl'=>['vinilos','cd']],
    'shopify_collections'=>['latiendanacional.cl'=>['vinilos','cds']],
    'woocommerce_endpoints'=>['puntomusical.cl'=>'/wp-json/wc/store/products'],
];
