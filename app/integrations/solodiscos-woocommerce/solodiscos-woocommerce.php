<?php
/**
 * Plugin Name: SoloDiscos para WooCommerce
 * Description: Sincroniza ventas sin datos personales y consulta estadísticas de SoloDiscos.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.2
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
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
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
            delete_option('solodiscos_token'); delete_transient('solodiscos_summary');
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
            delete_transient('solodiscos_summary');
            $result = self::request('summary');
            update_option('solodiscos_notice', is_wp_error($result) ? $result->get_error_message() : 'Conexión comprobada.');
            if (!is_wp_error($result)) {
                set_transient('solodiscos_summary', $result, 5 * MINUTE_IN_SECONDS);
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
            'units' => $units, 'refunded_units' => min($units, abs((int)$order->get_item_count_refunded()))];
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
        echo '<div class="wrap"><h1>SoloDiscos</h1><p>Comparte ventas totales y devoluciones sin nombres, correos, direcciones ni medios de pago. Incluye todos los productos de WooCommerce.</p>';
        echo '<p>'.esc_html(get_option('solodiscos_notice', '')).'</p><p>Último envío: '.esc_html(get_option('solodiscos_last_sent', 'Sin envíos')).'</p>';
        echo '<p>'.esc_html(get_option('solodiscos_history', '')).'</p><p>'.esc_html(get_option('solodiscos_last_error', '')).'</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="solodiscos_settings">';
        wp_nonce_field('solodiscos_settings');
        echo '<p><label>Clave de integración <input type="password" name="token" value="" autocomplete="new-password" size="65" placeholder="'.esc_attr(get_option('solodiscos_token') ? 'Clave guardada. Deja vacío para conservarla.' : 'Genera tu clave en el portal de tiendas.').'"></label></p>';
        echo '<button class="button button-primary" name="sd_action" value="save">Guardar y comprobar conexión</button> <button class="button" name="sd_action" value="history">Importar todo el historial</button> <button class="button" name="sd_action" value="disconnect">Desconectar</button></form>';
        $summary = get_transient('solodiscos_summary');
        if (!$summary && get_option('solodiscos_token')) {
            $summary = self::request('summary');
            if (!is_wp_error($summary)) { set_transient('solodiscos_summary', $summary, 5 * MINUTE_IN_SECONDS); }
        }
        if (is_array($summary)) {
            echo '<h2>'.esc_html($summary['store']).' · últimos 30 días</h2><p>Datos consultados cada cinco minutos. Ventas por fecha de creación (UTC); incluyen impuestos y envío. No son ventas atribuidas a SoloDiscos.</p>';
            echo '<p>Catálogo: '.esc_html($summary['catalog']['total']).' publicaciones · '.esc_html($summary['catalog']['published']).' publicadas · '.esc_html($summary['catalog']['out_of_stock']).' sin stock.</p>';
            echo '<p>Identificación pendiente: '.esc_html($summary['catalog']['pending']).' · Excluidas: '.esc_html($summary['catalog']['excluded']).' · Solicitudes pendientes: '.esc_html($summary['pending_corrections']).' · Última extracción: '.esc_html($summary['catalog']['last_success_at'] ?? 'Sin registro').'</p>';
            echo '<p>Interés en SoloDiscos: '.esc_html($summary['engagement']['views']).' vistas · '.esc_html($summary['engagement']['clicks']).' clics hacia tu tienda.</p>';
            foreach ($summary['sales'] as $row) {
                echo '<p>'.esc_html($row['currency']).': '.esc_html($row['orders']).' pedidos · Total: '.esc_html($row['gross']).' · Neto: '.esc_html($row['net']).' · Devoluciones: '.esc_html($row['refunded']).' · Unidades netas: '.esc_html($row['units']).' · Ticket neto: '.esc_html($row['average']).'</p>';
            }
            foreach (['topViews' => 'Discos con más interés', 'topClicks' => 'Publicaciones con más clics', 'scarce' => 'Poca disponibilidad', 'replenish' => 'Oportunidades de reposición'] as $key => $label) {
                echo '<h3>'.esc_html($label).'</h3><ul>';
                foreach ($summary['engagement'][$key] as $item) {
                    $detail = ($item['artist'] ?? '').' · '.$item['title'].' · '.($item['format'] ?? '');
                    if (isset($item['total'])) { $detail .= ' · '.($key === 'topClicks' ? 'Clics: ' : 'Vistas: ').$item['total']; }
                    if (isset($item['views'])) { $detail .= ' · Vistas: '.$item['views']; }
                    if (isset($item['stores'])) { $detail .= ' · Tiendas: '.$item['stores']; }
                    if (isset($item['minimum_price'])) { $detail .= ' · Precio mínimo CLP: '.$item['minimum_price']; }
                    echo '<li>'.esc_html($detail).'</li>';
                }
                echo '</ul>';
            }
        }
        echo '<h2>Tu portal</h2><p>';
        foreach (['' => 'Resumen', 'catalogo' => 'Mi catálogo', 'precios' => 'Comparación de precios', 'estadisticas' => 'Estadísticas completas', 'solicitudes' => 'Solicitudes', 'integracion' => 'Integración y ventas'] as $path => $label) {
            echo '<a class="button" href="'.esc_url('https://www.solodiscos.cl/tiendas/'.$path).'" target="_blank" rel="noopener noreferrer">'.esc_html($label).'</a> ';
        }
        echo '</p><p>Para sincronizar se requiere que WordPress ejecute las acciones programadas. Al desconectar no se borran los datos ya recibidos en SoloDiscos.</p></div>';
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
