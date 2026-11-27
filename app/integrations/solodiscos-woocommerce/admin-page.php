<?php
defined('ABSPATH') || exit;
$ready = is_array($summary);
$base_url = admin_url('admin.php?page=solodiscos');
$sales_url = add_query_arg(['sd_view' => 'sales'], $base_url).'#sd-sales';
?>
<div class="wrap sd-app">
    <header class="sd-heading">
        <div class="sd-brand"><span class="sd-record" aria-hidden="true"></span><div><span class="sd-eyebrow">SOLODISCOS · WOOCOMMERCE</span><h1>Las visitas se convierten en música.</h1><p>El aporte de SoloDiscos a tu tienda, en un solo lugar.</p></div></div>
        <span class="sd-status <?php echo $ready ? 'is-connected' : ''; ?>"><?php echo $ready ? 'Conectado' : 'Sin conexión'; ?></span>
    </header>

    <?php if (is_wp_error($summary)): ?>
        <div class="sd-alert" role="alert"><?php echo esc_html($summary->get_error_message()); ?> Abre Configuración para revisar la conexión.</div>
    <?php elseif (!$configured): ?>
        <div class="sd-alert">Conecta tu tienda con la clave disponible en el portal de SoloDiscos.</div>
    <?php endif; ?>
    <?php if (defined('WC_VERSION') && version_compare(WC_VERSION, '8.5', '<')): ?>
        <div class="sd-alert">Actualiza WooCommerce a la versión 8.5 o superior para registrar la atribución de pedidos.</div>
    <?php elseif (get_option('woocommerce_feature_order_attribution_enabled', 'yes') !== 'yes'): ?>
        <div class="sd-alert">Activa la atribución de pedidos en WooCommerce para identificar nuevas ventas desde SoloDiscos. <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=advanced&section=features')); ?>">Abrir ajustes de atribución</a></div>
    <?php endif; ?>

    <div class="sd-period"><h2><?php echo $ready ? esc_html($summary['store']) : 'Tu tienda'; ?></h2><span>Últimos 30 días<?php if ($ready): ?> · <?php echo esc_html($summary['from'].' al '.$summary['to']); ?><?php endif; ?></span></div>
    <div class="sd-metrics">
        <section class="sd-card"><span class="sd-label">Clics en productos</span><strong><?php echo $ready ? esc_html(number_format_i18n($summary['clicks'])) : '—'; ?></strong><span class="sd-caption">Enviados desde SoloDiscos</span></section>
        <section class="sd-card"><span class="sd-label">Ventas</span><strong><?php echo $ready ? esc_html(number_format_i18n($summary['orders'])) : '—'; ?></strong><span class="sd-caption">Pedidos pagados atribuidos</span></section>
        <section class="sd-card"><span class="sd-label">Conversión</span><strong><?php echo $ready && $summary['conversion_rate'] !== null ? esc_html(number_format_i18n($summary['conversion_rate'], 2)).'<small> %</small>' : '—'; ?></strong><span class="sd-caption"><?php echo $ready && $summary['clicks'] === 0 ? 'Sin clics para calcular' : 'Pedidos atribuidos / clics'; ?></span></section>
        <section class="sd-card sd-card-amount"><span class="sd-label">Monto vendido</span><div class="sd-amounts">
            <?php if ($ready): ?>
                <?php $amounts = $summary['amounts'] ?: [['currency' => get_woocommerce_currency(), 'net' => 0]]; ?>
                <?php foreach ($amounts as $amount): ?><strong><?php echo wp_kses_post(wc_price($amount['net'], ['currency' => $amount['currency']])); ?><small><?php echo esc_html($amount['currency']); ?></small></strong><?php endforeach; ?>
            <?php else: ?><strong>—</strong><?php endif; ?>
            </div><span class="sd-caption">Descontando devoluciones</span></section>
    </div>
    <div class="sd-actions">
        <?php if ($ready): ?><a class="sd-primary" href="<?php echo esc_url($sales_url); ?>">Ventas desde solodiscos.cl <span aria-hidden="true"><?php echo esc_html(number_format_i18n($summary['orders'])); ?></span></a><?php endif; ?>
        <a class="sd-secondary" href="https://www.solodiscos.cl/tiendas" target="_blank" rel="noopener noreferrer">Abrir mi portal</a>
    </div>
    <p class="sd-explanation">Atribución registrada por WooCommerce durante la sesión de compra. Pedidos según fecha de creación; el monto incluye impuestos y envío. Los pedidos reembolsados conservan su conteo y descuentan el importe devuelto. Los clics son interacciones, no visitantes únicos.<?php if ($ready): ?> Fechas: <?php echo esc_html($summary['timezone']); ?>.<?php endif; ?> Datos sincronizados; el resumen se actualiza cada cinco minutos.</p>

    <?php if ($show_sales && $ready): ?>
    <section class="sd-sales" id="sd-sales" aria-labelledby="sd-sales-title">
        <div class="sd-sales-heading"><div><span class="sd-eyebrow">PEDIDOS ATRIBUIDOS</span><h2 id="sd-sales-title">Ventas desde solodiscos.cl</h2></div><a class="sd-secondary" href="<?php echo esc_url($base_url); ?>">Cerrar listado</a></div>
        <?php if (is_wp_error($orders)): ?><p class="sd-alert" role="alert"><?php echo esc_html($orders->get_error_message()); ?></p>
        <?php elseif (empty($orders['data'])): ?><div class="sd-empty"><h3>Todavía no hay ventas atribuidas en este período.</h3><p>Las compras aparecerán aquí cuando WooCommerce registre SoloDiscos como origen y se complete su sincronización.</p></div>
        <?php else: ?>
        <p>La base de productos excluye el envío e incluye IVA, después de descuentos y devoluciones de productos. «Pendiente» requiere revisión del desglose; el monto neto del pedido no es automáticamente la base de comisión.</p>
        <div class="sd-table-scroll"><table class="sd-table"><thead><tr><th scope="col">Pedido</th><th scope="col">Fecha</th><th scope="col">Estado</th><th scope="col">Total</th><th scope="col">Devoluciones</th><th scope="col">Monto neto</th><th scope="col">Base de productos</th></tr></thead><tbody>
        <?php foreach ($orders['data'] as $row):
            $id = ctype_digit((string)$row['external_id']) ? (int)$row['external_id'] : 0;
            $order = $id ? wc_get_order($id) : false;
            $when = new DateTimeImmutable($row['ordered_at'], new DateTimeZone('UTC'));
        ?>
            <tr><td><?php if ($order && $order->get_type() === 'shop_order'): ?><a class="sd-order-link" href="<?php echo esc_url($order->get_edit_order_url()); ?>">#<?php echo esc_html($order->get_order_number()); ?><span>Abrir pedido</span></a><?php else: ?>#<?php echo esc_html($row['external_id']); ?><span class="sd-caption">No disponible en esta instalación</span><?php endif; ?></td>
                <td><?php echo esc_html(wp_date('d/m/Y H:i', $when->getTimestamp(), new DateTimeZone($summary['timezone']))); ?></td><td><span class="sd-order-status"><?php echo esc_html(wc_get_order_status_name($row['status'])); ?></span></td>
                <?php foreach (['total','refunded','net'] as $field): ?><td><?php echo wp_kses_post(wc_price($row[$field], ['currency' => $row['currency']])); ?> <small><?php echo esc_html($row['currency']); ?></small></td><?php endforeach; ?>
                <td><?php echo isset($row['commission_base']) ? wp_kses_post(wc_price($row['commission_base'], ['currency'=>$row['currency']])) : 'Pendiente'; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <nav class="sd-pagination" aria-label="Páginas de ventas"><span><?php echo esc_html('Página '.$orders['current_page'].' de '.$orders['last_page'].' · '.$orders['total'].' pedidos'); ?></span><div>
            <?php if ($orders['current_page'] > 1): ?><a class="sd-secondary" href="<?php echo esc_url(add_query_arg(['sd_view'=>'sales','sd_page'=>$orders['current_page']-1], $base_url).'#sd-sales'); ?>">Anterior</a><?php endif; ?>
            <?php if ($orders['current_page'] < $orders['last_page']): ?><a class="sd-secondary" href="<?php echo esc_url(add_query_arg(['sd_view'=>'sales','sd_page'=>$orders['current_page']+1], $base_url).'#sd-sales'); ?>">Siguiente</a><?php endif; ?>
        </div></nav>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <details class="sd-settings" <?php echo !$configured ? 'open' : ''; ?>><summary>Configuración de la integración</summary><div class="sd-settings-body">
        <p>Genera tu clave en <a href="https://www.solodiscos.cl/tiendas/integracion" target="_blank" rel="noopener noreferrer">SoloDiscos · Integración</a> y pégala aquí.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="solodiscos_settings"><?php wp_nonce_field('solodiscos_settings'); ?>
            <label for="sd-token">Clave de integración</label><input id="sd-token" type="password" name="token" value="" autocomplete="new-password" placeholder="<?php echo esc_attr($configured ? 'Clave guardada. Deja vacío para conservarla.' : 'Pega la clave de tu tienda'); ?>">
            <div class="sd-actions"><button class="sd-primary" name="sd_action" value="save">Guardar y comprobar conexión</button><button class="sd-secondary" name="sd_action" value="history">Sincronizar historial</button><button class="sd-disconnect" name="sd_action" value="disconnect">Desconectar</button></div>
        </form>
        <p><?php echo esc_html(get_option('solodiscos_notice', '')); ?></p><p>Último envío: <?php echo esc_html(get_option('solodiscos_last_sent', 'Todavía no registrado')); ?>.</p>
        <p><?php echo esc_html(get_option('solodiscos_history', '')); ?> <?php echo esc_html(get_option('solodiscos_last_error', '')); ?></p>
        <p>La atribución requiere WooCommerce 8.5 o superior y la función Atribución de pedidos activada. Al actualizar se revisa el historial; los pedidos sin un origen registrado no se atribuyen por suposición.</p>
        <p>Los envíos se procesan en segundo plano. Consulta las tareas del grupo <code>solodiscos</code> en WooCommerce · Estado · Acciones programadas. Se envían importes, cantidades, fechas, estado, ID y atribución; los datos personales permanecen en WooCommerce.</p>
    </div></details>
</div>
