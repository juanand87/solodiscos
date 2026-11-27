<?php
/**
 * Plugin Name: SoloDiscos para WooCommerce
 * Description: Sincroniza ventas sin datos personales y consulta estadísticas de SoloDiscos.
 * Version: 1.1.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.5
 * Author: SoloDiscos
 * License: GPL-2.0-or-later
 */
defined('ABSPATH') || exit;

add_action('before_woocommerce_init', function () {
    if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

final class SoloDiscos_WooCommerce {
    const API = 'https://www.solodiscos.cl/api/v1/woocommerce/';
    const GROUP = 'solodiscos';
    const VERSION = '1.1.0';
    const CACHE = 'solodiscos_dashboard_v2';
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'upgrade']);
        add_action('admin_enqueue_scripts', function ($hook) {
            if ($hook === 'woocommerce_page_solodiscos') { wp_enqueue_style('solodiscos-admin', plugins_url('admin.css', __FILE__), [], self::VERSION); }
        });
        add_action('admin_post_solodiscos_settings', [__CLASS__, 'settings']);
        add_action('woocommerce_after_order_object_save', [__CLASS__, 'changed']);
        add_action('woocommerce_order_refunded', function ($id) { self::enqueue($id); });
        add_action('woocommerce_order_status_changed', function ($id) { self::enqueue($id); });
        add_action('woocommerce_before_delete_order', [__CLASS__, 'deleted']);
        add_action('woocommerce_before_trash_order', [__CLASS__, 'deleted']);
        add_action('solodiscos_send_order', [__CLASS__, 'send'], 10, 2);
        add_action('solodiscos_deleted_order', [__CLASS__, 'sendDeleted'], 10, 2);
        add_action('solodiscos_backfill', [__CLASS__, 'backfill'], 10, 2);
    }
    public static function menu() {
        add_submenu_page('woocommerce', 'SoloDiscos', 'SoloDiscos', 'manage_woocommerce', 'solodiscos', [__CLASS__, 'page']);
    }
    private static function request($path, $data = null) {
        $args = ['timeout' => 20, 'redirection' => 0, 'headers' => ['Authorization' => 'Bearer '.get_option('solodiscos_token', ''), 'Accept' => 'application/json']];
        if ($data !== null) {
            $args['method'] = 'POST';
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($data);
        }
        $response = wp_remote_request(self::API.$path, $args);
        if (is_wp_error($response)) { return $response; }
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) { return new WP_Error('solodiscos_http', 'SoloDiscos respondió HTTP '.(int)$code.'. Revisa la clave y la conexión.'); }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body)) { return new WP_Error('solodiscos_json', 'La respuesta de SoloDiscos no es válida.'); }
        return $body;
    }
    public static function settings() {
        if (!current_user_can('manage_woocommerce')) { wp_die('Sin acceso.'); }
        check_admin_referer('solodiscos_settings');
        $action = sanitize_key($_POST['sd_action'] ?? 'save');
        if ($action === 'disconnect') {
            delete_option('solodiscos_token'); delete_transient(self::CACHE);
            as_unschedule_all_actions('solodiscos_send_order', null, self::GROUP);
            as_unschedule_all_actions('solodiscos_backfill', null, self::GROUP);
            as_unschedule_all_actions('solodiscos_deleted_order', null, self::GROUP);
            update_option('solodiscos_notice', 'Desconectado en WordPress. Puedes revocar la clave también en el portal.');
        } else {
            $token = trim(wp_unslash($_POST['token'] ?? ''));
            if ($token !== '') {
                if (!preg_match('/^[a-f0-9]{64}$/D', $token)) { wp_die('La clave debe contener 64 caracteres hexadecimales.'); }
                update_option('solodiscos_token', $token, false);
            }
            delete_transient(self::CACHE);
            $result = self::request('dashboard');
            update_option('solodiscos_notice', is_wp_error($result) ? $result->get_error_message() : 'Conexión comprobada.');
            if (!is_wp_error($result)) {
                set_transient(self::CACHE, $result, 5 * MINUTE_IN_SECONDS);
                if ($action === 'history' || $token !== '') {
                    if (!as_has_scheduled_action('solodiscos_backfill', null, self::GROUP)) {
                        as_enqueue_async_action('solodiscos_backfill', [1, 0], self::GROUP);
                    }
                    update_option('solodiscos_notice', 'Conectado. Historial en proceso; revisa WooCommerce → Estado → Acciones programadas.');
                }
            }
        }
        wp_safe_redirect(admin_url('admin.php?page=solodiscos')); exit;
    }
    public static function changed($order) {
        if ($order instanceof WC_Order && $order->get_type() === 'shop_order') { self::enqueue($order->get_id()); }
    }
    public static function upgrade() {
        if (get_option('solodiscos_version') === self::VERSION || !current_user_can('manage_woocommerce')) { return; }
        delete_transient('solodiscos_summary'); delete_transient(self::CACHE);
        if (get_option('solodiscos_token') && !as_has_scheduled_action('solodiscos_backfill', null, self::GROUP)) {
            as_enqueue_async_action('solodiscos_backfill', [1, 0], self::GROUP);
        }
        update_option('solodiscos_version', self::VERSION, false);
    }
    public static function attributed($order) {
        $prefix = '_'.trim((string)apply_filters('wc_order_attribution_tracking_field_prefix', 'wc_order_attribution_'), '_').'_';
        $type = (string)$order->get_meta($prefix.'source_type');
        $source = strtolower(trim((string)$order->get_meta($prefix.'utm_source')));
        return in_array($type, ['utm', 'referral'], true) && in_array($source, ['solodiscos.cl', 'www.solodiscos.cl'], true);
    }
    public static function enqueue($id) {
        if (get_option('solodiscos_token') && function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('solodiscos_send_order', [(int)$id, 0], self::GROUP, true);
        }
    }
    private static function payload($order) {
        $units = 0;
        foreach ($order->get_items() as $item) { $units += max(0, (int)$item->get_quantity()); }
        $created = $order->get_date_created();
        $modified = $order->get_date_modified();
        return ['external_id' => (string)$order->get_id(), 'status' => $order->get_status(), 'currency' => $order->get_currency(),
            'ordered_at' => gmdate('Y-m-d\TH:i:s\Z', $created ? $created->getTimestamp() : time()),
            'source_updated_at' => gmdate('Y-m-d\TH:i:s\Z', $modified ? $modified->getTimestamp() : time()),
            'paid' => (bool)($order->get_date_paid() || $order->is_paid()), 'total' => (string)$order->get_total(),
            'refunded' => (string)min((float)$order->get_total(), (float)$order->get_total_refunded()),
            'units' => $units, 'refunded_units' => min($units, abs((int)$order->get_item_count_refunded())),
            'attributed_to_solodiscos' => self::attributed($order)];
    }
    public static function send($id, $attempt = 0) {
        if (!get_option('solodiscos_token')) { return; }
        $order = wc_get_order($id);
        if (!$order || $order->get_type() !== 'shop_order') { return; }
        $result = self::request('orders', self::payload($order));
        if (is_wp_error($result)) {
            update_option('solodiscos_last_error', $result->get_error_message(), false);
            if ($attempt < 8) { as_schedule_single_action(time() + min(21600, 60 * pow(2, $attempt)), 'solodiscos_send_order', [(int)$id, (int)$attempt + 1], self::GROUP); }
            else { throw new RuntimeException('SoloDiscos: envío agotó reintentos. Reimporta el historial después de resolver la conexión.'); }
            return;
        }
        update_option('solodiscos_last_sent', gmdate('Y-m-d H:i:s').' UTC', false);
        delete_option('solodiscos_last_error');
    }
    public static function deleted($id) {
        if (!get_option('solodiscos_token')) { return; }
        $order = wc_get_order($id);
        if (!$order || $order->get_type() !== 'shop_order') { return; }
        // A queued tombstone survives permanent deletion of the source order.
        $data = self::payload($order); $data['status'] = 'trash'; $data['source_updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        as_enqueue_async_action('solodiscos_deleted_order', [$data, 0], self::GROUP);
    }
    public static function sendDeleted($data, $attempt = 0) {
        if (!get_option('solodiscos_token')) { return; }
        $result = self::request('orders', $data);
        if (is_wp_error($result)) {
            update_option('solodiscos_last_error', $result->get_error_message(), false);
            if ($attempt < 8) { as_schedule_single_action(time() + min(21600, 60 * pow(2, $attempt)), 'solodiscos_deleted_order', [$data, (int)$attempt + 1], self::GROUP); }
            else { throw new RuntimeException('SoloDiscos: eliminación pendiente tras agotar reintentos. Reejecuta esta acción al recuperar la conexión.'); }
        }
    }
    public static function backfill($page = 1, $attempt = 0) {
        if (!get_option('solodiscos_token')) { return; }
        $result = wc_get_orders(['type' => 'shop_order', 'status' => array_keys(wc_get_order_statuses()), 'limit' => 50, 'page' => (int)$page, 'orderby' => 'ID', 'order' => 'ASC', 'paginate' => true]);
        foreach ($result->orders as $order) { self::enqueue($order->get_id()); }
        if ((int)$page < (int)$result->max_num_pages) { as_schedule_single_action(time() + 30, 'solodiscos_backfill', [(int)$page + 1, 0], self::GROUP); }
        else { update_option('solodiscos_history', 'Historial encolado el '.gmdate('Y-m-d H:i:s').' UTC. Los envíos pueden continuar pendientes.', false); }
    }
    public static function page() {
        if (!current_user_can('manage_woocommerce')) { return; }
        $configured = (bool)get_option('solodiscos_token');
        $summary = $configured ? get_transient(self::CACHE) : false;
        if (!$summary && $configured) {
            $summary = self::request('dashboard');
            if (!is_wp_error($summary)) { set_transient(self::CACHE, $summary, 5 * MINUTE_IN_SECONDS); }
        }
        $show_sales = isset($_GET['sd_view']) && sanitize_key(wp_unslash($_GET['sd_view'])) === 'sales';
        $page = isset($_GET['sd_page']) ? max(1, min(1000000, absint($_GET['sd_page']))) : 1;
        $orders = null;
        if ($show_sales && is_array($summary)) {
            $orders = self::request('attributed-orders?'.http_build_query(['page' => $page, 'from' => $summary['from'], 'to' => $summary['to']]));
        }
        include __DIR__.'/admin-page.php';
    }
}
add_action('plugins_loaded', function () { if (class_exists('WooCommerce')) { SoloDiscos_WooCommerce::init(); } });
register_deactivation_hook(__FILE__, function () {
    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('solodiscos_send_order', null, 'solodiscos');
        as_unschedule_all_actions('solodiscos_backfill', null, 'solodiscos');
        as_unschedule_all_actions('solodiscos_deleted_order', null, 'solodiscos');
    }
});
