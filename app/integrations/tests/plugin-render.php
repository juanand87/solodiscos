<?php
// Fixture rendering and UI contract checks; no WordPress installation or customer data required.
ob_start();require __DIR__.'/plugin-smoke.php';ob_end_clean();
define('MINUTE_IN_SECONDS',60);
function current_user_can(...$args){return true;}
function admin_url($path){return 'https://shop.example/wp-admin/'.$path;}
function add_query_arg($args,$url){return $url.'&'.http_build_query($args);}
function esc_html($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function esc_attr($value){return esc_html($value);}
function esc_url($value){return esc_html($value);}
function wp_kses_post($value){return $value;}
function number_format_i18n($number,$decimals=0){return number_format((float)$number,$decimals,',','.');}
function wc_price($number,$args){return '<span class="woocommerce-Price-amount">$'.number_format_i18n($number,$args['currency']==='CLP'?0:2).'</span>';}
function get_woocommerce_currency(){return 'CLP';}
function wc_get_order_status_name($status){return ['completed'=>'Completado','refunded'=>'Reembolsado'][$status]??$status;}
function wp_date($format,$timestamp,$timezone){return (new DateTimeImmutable('@'.$timestamp))->setTimezone($timezone)->format($format);}
function wp_nonce_field(...$args){echo '<input type="hidden" name="_wpnonce" value="fixture">';}
$configured=true;$show_sales=true;
$summary=['store'=>'Tienda de discos','from'=>'2026-10-29','to'=>'2026-11-27','timezone'=>'America/Santiago','clicks'=>250,'orders'=>10,'conversion_rate'=>4,'amounts'=>[['currency'=>'CLP','net'=>350000]]];
$row=['external_id'=>'123','ordered_at'=>'2026-11-27 17:30:00','status'=>'completed','currency'=>'CLP','total'=>'40000','refunded'=>'5000','net'=>'35000'];
$orders=['data'=>[$row],'current_page'=>1,'last_page'=>2,'total'=>22];
$options['solodiscos_token']=str_repeat('a',64);
ob_start();include dirname(__DIR__).'/solodiscos-woocommerce/admin-page.php';$html=ob_get_clean();
foreach(['Clics en productos','Ventas','Conversión','Monto vendido','Ventas desde solodiscos.cl','Abrir pedido','sd_page=2'] as $label)verify(strpos($html,$label)!==false,'Missing UI: '.$label);
verify(strpos($html,'Poca disponibilidad')===false && strpos($html,'Oportunidades de reposición')===false,'Old sections still visible');
verify(strpos($html,'page=wc-orders&amp;action=edit&amp;id=123')!==false,'HPOS editor link missing');
$edit_url='https://shop.example/wp-admin/post.php?post=123&action=edit';
ob_start();include dirname(__DIR__).'/solodiscos-woocommerce/admin-page.php';$classic=ob_get_clean();
verify(strpos($classic,'post.php?post=123&amp;action=edit')!==false,'Classic editor link missing');
$orders['data']=[];ob_start();include dirname(__DIR__).'/solodiscos-woocommerce/admin-page.php';$empty=ob_get_clean();
verify(strpos($empty,'Todavía no hay ventas atribuidas')!==false,'Missing empty state');
$summary['conversion_rate']=null;$summary['clicks']=0;ob_start();include dirname(__DIR__).'/solodiscos-woocommerce/admin-page.php';$zero=ob_get_clean();
verify(strpos($zero,'Sin clics para calcular')!==false,'Zero-click conversion misleading');
$summary=false;$configured=false;ob_start();include dirname(__DIR__).'/solodiscos-woocommerce/admin-page.php';$disconnected=ob_get_clean();
verify(strpos($disconnected,'Sin conexión')!==false && strpos($disconnected,'<strong>—</strong>')!==false,'Missing disconnected state');
$out=dirname(__DIR__,3).'/.local/woo-plugin-preview';
if(in_array('--render',$argv,true)){
    if(!is_dir($out))mkdir($out,0777,true);
    copy(dirname(__DIR__).'/solodiscos-woocommerce/admin.css',$out.'/admin.css');
    foreach(['index'=>$html,'empty'=>$empty,'disconnected'=>$disconnected] as $name=>$content)file_put_contents($out.'/'.$name.'.html','<!doctype html><html lang="es"><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="admin.css"><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f0f0f1;margin:30px}a{color:#2271b1}button,input{font:inherit}p{line-height:1.6}summary{display:list-item}</style>'.$content.'</html>');
}
echo "Plugin UI checks passed: four metrics, empty/disconnected states, zero clicks, pagination, HPOS/classic order links.\n";
