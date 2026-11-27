=== SoloDiscos para WooCommerce ===
Contributors: solodiscos
Requires at least: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

== Instalación ==
1. El servidor SoloDiscos debe tener desplegada esta versión y sus migraciones.
2. Descarga el ZIP desde Portal de tiendas > Integración.
3. En WordPress: Plugins > Añadir plugin > Subir plugin. Sube el ZIP y actívalo.
4. Genera una clave desde tu portal verificado de SoloDiscos.
5. En WooCommerce > SoloDiscos pega la clave y pulsa Guardar y comprobar conexión.
6. El historial se encola automáticamente al guardar una clave. También puedes reimportarlo manualmente.

== Datos y métricas ==
El destino es exclusivamente https://www.solodiscos.cl. Los envíos usan HTTPS y no siguen redirecciones.
Se envían ID interno, estado, moneda, fecha de creación y modificación UTC, indicador de pago, total,
devoluciones monetarias, unidades y unidades devueltas. No se envían datos de clientes, productos,
facturación, direcciones, correos ni información de tarjetas.
Incluye TODOS los productos de la tienda: no filtra únicamente discos ni atribuye ventas a SoloDiscos.
Ventas pagadas por fecha de creación UTC, con impuestos y envío. Excluye cancelados, fallidos y eliminados.
Neto = total menos devoluciones. Una devolución sin cantidades reduce el importe, pero no las unidades.
Las devoluciones corrigen el período original del pedido. Los reembolsados completos siguen contándose
como pedidos pagados con importe neto cero. No se mezclan monedas.
El plugin muestra catálogo, vistas, clics, listas de interés y oportunidades, ventas netas y enlaces a
las herramientas completas del portal (requieren inicio de sesión). Las estadísticas se consultan cada 5 minutos.
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
