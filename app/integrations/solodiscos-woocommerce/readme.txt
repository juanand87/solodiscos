=== SoloDiscos para WooCommerce ===
Contributors: solodiscos
Requires at least: 6.4
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later

== Instalación ==
1. El servidor SoloDiscos debe tener desplegada esta versión y sus migraciones.
2. Descarga el ZIP desde Portal de tiendas > Integración.
3. Requiere WooCommerce 8.5+. En WordPress: Plugins > Añadir plugin > Subir plugin. Sube el ZIP y actívalo.
4. Genera una clave desde tu portal verificado de SoloDiscos.
5. En WooCommerce > SoloDiscos pega la clave y pulsa Guardar y comprobar conexión.
6. El historial se encola automáticamente al guardar una clave. También puedes reimportarlo manualmente.
7. Activa WooCommerce > Ajustes > Avanzado > Características > Atribución de pedidos.

== Panel simple y atribución (1.1.0) ==
Cuatro indicadores de los últimos 30 días: Clics en productos, Ventas, Conversión y Monto vendido.
Ventas = pedidos pagados atribuidos, excluyendo cancelados, fallidos y eliminados. Los reembolsados
conservan su conteo; Monto vendido descuenta las devoluciones, con impuestos y envío incluidos.
Conversión = pedidos atribuidos / clics enviados x 100. Sin clics se muestra un guion, no una tasa ficticia.
Los clics son interacciones deduplicadas durante 60 segundos por producto/sesión, no visitantes únicos;
por tanto la tasa es una relación entre pedidos y clics, no una medición individual del recorrido completo.
El monto se separa por moneda. Fechas del panel/listado en America/Santiago, coincidiendo con los clics.

Los enlaces salientes de SoloDiscos hacia tiendas WooCommerce incorporan utm_source=solodiscos.cl,
utm_medium=referral y utm_campaign=catalogo, conservando parámetros del producto y fragmentos.
El plugin consulta los metadatos nativos de atribución mediante WC_Order::get_meta; acepta únicamente
los tipos utm/referral con origen exacto solodiscos.cl o www.solodiscos.cl. No agrega cookies propias,
ni cambia automáticamente la configuración de atribución o consentimiento de WooCommerce.
Se respeta el modelo de sesión de WooCommerce. Compras posteriores en otro dispositivo o con seguimiento
bloqueado pueden no atribuirse. Pedidos sin metadatos no se atribuyen por suposición.

El botón Ventas desde solodiscos.cl abre un listado de 20 pedidos por página con fecha, estado, total,
devoluciones y monto neto. El número del pedido abre su edición local usando get_edit_order_url(),
compatible con HPOS y almacenamiento clásico. Los números comerciales se leen localmente; no se envían
datos personales ni URLs de administración a SoloDiscos. Solo acceden usuarios con manage_woocommerce.
La configuración queda plegada y las herramientas del catálogo están en Abrir mi portal.

Actualizar: primero desplegar SoloDiscos y ejecutar sus migraciones; luego subir el ZIP nuevo a WordPress
y elegir reemplazar el plugin instalado. La clave se conserva. La primera visita administrativa posterior
a actualizar encola una revisión del historial para recuperar atribuciones nativas que ya existieran.
Los datos antiguos sin atribución enviada permanecen desconocidos hasta recibir el nuevo snapshot.
Los endpoints nuevos son GET /api/v1/woocommerce/dashboard y /api/v1/woocommerce/attributed-orders.
El endpoint summary y las estadísticas totales se mantienen para compatibilidad con la versión anterior.

== Datos y métricas ==
El destino es exclusivamente https://www.solodiscos.cl. Los envíos usan HTTPS y no siguen redirecciones.
Se envían ID interno, estado, moneda, fecha de creación y modificación UTC, indicador de pago, total,
devoluciones monetarias, unidades y unidades devueltas, e indicador de atribución a SoloDiscos. No se envían datos de clientes, productos,
facturación, direcciones, correos ni información de tarjetas.
La sincronización incluye TODOS los productos de la tienda. El panel del plugin filtra las ventas atribuidas
a SoloDiscos; el resumen administrativo y el portal conservan las ventas totales por separado.
Ventas pagadas por fecha de creación UTC, con impuestos y envío. Excluye cancelados, fallidos y eliminados.
Neto = total menos devoluciones. Una devolución sin cantidades reduce el importe, pero no las unidades.
Las devoluciones corrigen el período original del pedido. Los reembolsados completos siguen contándose
como pedidos pagados con importe neto cero. No se mezclan monedas.
El plugin muestra clics, ventas atribuidas, conversión y monto neto. El catálogo y las demás estadísticas
se consultan en el portal (requiere inicio de sesión). El resumen se consulta cada 5 minutos.
Los pedidos usan las APIs CRUD de WooCommerce y son compatibles con HPOS.

== Operación ==
Action Scheduler (incluido en WooCommerce) ejecuta envíos y lotes de 50 pedidos. WordPress debe tener cron
operativo. Revisa WooCommerce > Estado > Acciones programadas, grupo solodiscos.
Cada envío fallido se reintenta hasta 8 veces con espera creciente, hasta 6 horas por espera.
Tras agotar reintentos queda una acción fallida visible. Recuperada la conexión, reimporta el historial;
para eliminaciones permanentes reejecuta la acción fallida de eliminación.
Los reenvíos actualizan el mismo ID por tienda; no suman dos veces. Un snapshot antiguo no reemplaza uno nuevo.
El historial se importa en orden de ID. Si se eliminan pedidos durante la importación, vuelve a ejecutarla
al terminar para recuperar posibles saltos por paginación. La desactivación del plugin detiene los envíos.
Desconectar borra la clave local y cancela tareas pendientes. Los datos recibidos en SoloDiscos se conservan.
Reemplazar o revocar una clave en el portal invalida inmediatamente la anterior.
No conectes dos bases WooCommerce distintas a la misma tienda: los IDs de pedido podrían coincidir.
Antes de distribuir, validar en un WooCommerce de pruebas con HPOS y almacenamiento clásico,
incluyendo reembolso parcial/completo, compra con checkout clásico y bloques, borrado/restauración y cron.
