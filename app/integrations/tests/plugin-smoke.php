<?php
// Run in a separate PHP process; these doubles verify the transport contract without WordPress.
define('ABSPATH', __DIR__);
$options=['solodiscos_token'=>str_repeat('a',64)];$queue=[];$requests=[];$failure=false;
$meta=['_wc_order_attribution_source_type'=>'utm','_wc_order_attribution_utm_source'=>'solodiscos.cl'];
function add_action(...$args) {}
function register_deactivation_hook(...$args) {}
function get_option($name,$default=false) {return $GLOBALS['options'][$name]??$default;}
function update_option($name,$value,...$args) {$GLOBALS['options'][$name]=$value;}
function delete_option($name) {unset($GLOBALS['options'][$name]);}
function apply_filters($name,$value){return $value;}
function wp_json_encode($value) {return json_encode($value);}
class WP_Error {public function get_error_message(){return 'Connection failed';}}
function is_wp_error($value) {return $value instanceof WP_Error;}
function wp_remote_request($url,$args) {$GLOBALS['requests'][]=[$url,$args];return $GLOBALS['failure']?new WP_Error():['code'=>200,'body'=>'{"received":true}'];}
function wp_remote_retrieve_response_code($response) {return $response['code'];}
function wp_remote_retrieve_body($response) {return $response['body'];}
function as_enqueue_async_action(...$args) {$GLOBALS['queue'][]=$args;}
function as_schedule_single_action(...$args) {$GLOBALS['queue'][]=$args;}
class WC_Order {
    public function get_meta($name){return $GLOBALS['meta'][$name]??'';}
    public function get_order_number(){return 'SD-123';}
    public function get_edit_order_url(){return $GLOBALS['edit_url']??'https://shop.example/wp-admin/admin.php?page=wc-orders&action=edit&id=123';}
    public function get_id(){return 123;}
    public function get_type(){return 'shop_order';}
    public function update_meta_data($name,$value){$GLOBALS['meta'][$name]=$value;}
    public function save_meta_data(){}
    public function get_items($type='line_item'){return [new class {public function get_quantity(){return 3;}public function get_total(){return 20000;}public function get_total_tax(){return 3800;}}];}
    public function get_refunds(){return !empty($GLOBALS['manual_refund'])?[]:[new class {public function get_items($types){return [new class {public function get_total(){return -8403.36;}public function get_total_tax(){return -1596.64;}public function get_type(){return $GLOBALS['refund_type']??'line_item';}}];}}];}
    public function get_date_created(){return new DateTime('2026-11-20T12:00:00Z');}
    public function get_date_modified(){return new DateTime('2026-11-21T13:00:00Z');}
    public function get_date_paid(){return new DateTime('2026-11-20T12:00:00Z');}
    public function is_paid(){return true;}
    public function get_status(){return 'completed';}
    public function get_currency(){return 'CLP';}
    public function get_total(){return '30000.00';}
    public function get_total_refunded(){return $GLOBALS['refund_amount']??'10000.00';}
    public function get_item_count_refunded(){return -1;}
}
function wc_get_order($id){return new WC_Order();}
function wc_get_order_statuses(){return ['wc-completed'=>'Completed'];}
function wc_get_orders($args){return (object)['orders'=>[new WC_Order()],'max_num_pages'=>2];}
require dirname(__DIR__).'/solodiscos-woocommerce/solodiscos-woocommerce.php';
function verify($condition,$message){if(!$condition)throw new RuntimeException($message);}
SoloDiscos_WooCommerce::send(123);
[$url,$args]=$requests[0];$body=json_decode($args['body'],true);
verify($url==='https://www.solodiscos.cl/api/v1/woocommerce/orders','Wrong endpoint');
verify($args['redirection']===0 && $args['headers']['Authorization']==='Bearer '.str_repeat('a',64),'Unsafe transport');
verify($body['units']===3 && $body['refunded_units']===1 && $body['refunded']==='10000','Refund totals');
verify(array_keys($body)===['external_id','status','currency','ordered_at','source_updated_at','paid','total','refunded','units','refunded_units','attributed_to_solodiscos','products_total','products_refunded','commission_base'],'Unexpected data in payload');
verify($body['products_total']==='23800.00' && $body['products_refunded']==='10000.00' && $body['commission_base']==='13800.00','Commission products base must exclude shipping and include product tax');
verify($body['attributed_to_solodiscos']===true,'Native UTM attribution missing');
$meta['_wc_order_attribution_utm_source']='solodiscos.cl.evil.example';
verify(!SoloDiscos_WooCommerce::attributed(new WC_Order()),'Lookalike domain attributed');
$meta['_wc_order_attribution_utm_source']='google';
verify(!SoloDiscos_WooCommerce::attributed(new WC_Order()),'Other campaign attributed');
$meta=[];verify(!SoloDiscos_WooCommerce::attributed(new WC_Order()),'Unknown historical order attributed');
$meta=['_wc_order_attribution_source_type'=>'referral','_wc_order_attribution_utm_source'=>'www.solodiscos.cl'];
verify(SoloDiscos_WooCommerce::attributed(new WC_Order()),'Native referral attribution missing');
verify($body['ordered_at']==='2026-11-20T12:00:00Z','UTC timestamp');
$meta=['_wc_order_attribution_source_type'=>'utm','_wc_order_attribution_utm_source'=>'google'];$before=count($requests);SoloDiscos_WooCommerce::send(123);verify(count($requests)===$before,'Unrelated order was transmitted');
$meta['_solodiscos_exported']='yes';SoloDiscos_WooCommerce::send(123);$last=end($requests);$withdrawal=json_decode($last[1]['body'],true);verify(strpos($last[0],'/orders/withdraw')!==false && array_keys($withdrawal)===['external_id','source_updated_at'],'Lost attribution must send only a minimal withdrawal');
$meta=['_wc_order_attribution_source_type'=>'utm','_wc_order_attribution_utm_source'=>'solodiscos.cl'];
$manual_refund=true;SoloDiscos_WooCommerce::send(123);$last=end($requests);verify(json_decode($last[1]['body'],true)['commission_base']===null,'Unallocated refund must not invent a commission base');
$manual_refund=false;$refund_type='shipping';SoloDiscos_WooCommerce::send(123);$last=end($requests);verify(json_decode($last[1]['body'],true)['commission_base']==='23800.00','Shipping-only refund must not reduce product base');
$refund_type='line_item';$manual_refund=true;$refund_amount='30000.00';SoloDiscos_WooCommerce::send(123);$last=end($requests);verify(json_decode($last[1]['body'],true)['commission_base']==='0.00','Full refund must eliminate the product base');
$manual_refund=false;$refund_amount='10000.00';
$failure=true;SoloDiscos_WooCommerce::send(123,0);
verify($queue[0][1]==='solodiscos_send_order' && $queue[0][2]===[123,1],'Retry not scheduled');
$failure=false;$queue=[];SoloDiscos_WooCommerce::deleted(123);
verify($queue[0][0]==='solodiscos_deleted_order' && $queue[0][1][0]['status']==='trash','Deletion must survive source removal');
$queue=[];SoloDiscos_WooCommerce::backfill();
verify(count($queue)===2 && $queue[1][1]==='solodiscos_backfill' && $queue[1][2]===[2,0],'History pagination');
delete_option('solodiscos_token');$count=count($requests);SoloDiscos_WooCommerce::send(123);
verify(count($requests)===$count,'Disconnected plugin sent data');
echo "Plugin smoke checks passed: native attribution, lookalike/unknown sources, payload, privacy, UTC, refunds, transport, retry, deletion, history, disconnect.\n";
