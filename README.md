# SoloDiscos

## Integración de tiendas con WooCommerce

Cada cuenta verificada dispone de **Integración** en `/tiendas/integracion`: descarga del plugin, generación de clave mostrada una sola vez, reemplazo, revocación y ventas por período. La clave se guarda en SoloDiscos únicamente como SHA-256, queda vinculada a la tienda y su dominio y deja de funcionar si la tienda se desactiva o cambia de dominio. Cada tienda solo ve sus datos. El administrador dispone de **Ventas integradas** en `/admin/ventas-integradas`.

El plugin instalable está en `app/integrations/solodiscos-woocommerce.zip`; sus fuentes y manual están en `app/integrations/solodiscos-woocommerce/`. Para regenerar el ZIP en PowerShell: `Compress-Archive -LiteralPath app/integrations/solodiscos-woocommerce -DestinationPath app/integrations/solodiscos-woocommerce.zip -Force`. El destino del plugin es `https://www.solodiscos.cl`; no hay una dirección configurable que pueda recibir la clave por error. Usa APIs CRUD de WooCommerce y declara compatibilidad HPOS. Requiere WordPress 6.4+, PHP 7.4+ y WooCommerce 8.5+.

`POST /api/v1/woocommerce/orders` recibe snapshots de pedidos, con autenticación Bearer y límite de 120 solicitudes/minuto por clave. Se actualiza por tienda e ID y se ignoran versiones antiguas. `GET /api/v1/woocommerce/summary` conserva el resumen original para plugins anteriores. No hay sesión web ni exclusiones generales de CSRF en la API; las acciones del portal sí requieren sesión verificada y CSRF.

Desde el plugin 1.2.0 la sincronización conserva únicamente pedidos **atribuidos a SoloDiscos**. El portal y el administrador muestran ese total por fecha de creación UTC, excluyendo cancelados, fallidos y eliminados; incluye impuestos y envío, devoluciones, neto, unidades netas y ticket promedio neto, separados por moneda. Las devoluciones corrigen el período del pedido original. No se transmiten nombres, correos, direcciones, medios de pago ni líneas de productos. Sin datos sincronizados no se presume ausencia de ventas.

El plugin **1.2.0** muestra únicamente cuatro indicadores: **Clics en productos**, **Ventas** atribuidas, **Conversión** (pedidos atribuidos / clics × 100) y **Monto vendido** (neto de devoluciones, por moneda). El panel usa `GET /api/v1/woocommerce/dashboard`, con consultas acotadas sin generar listas de poca disponibilidad/reposición. El botón **Ventas desde solodiscos.cl** consulta `GET /api/v1/woocommerce/attributed-orders`, paginado de 20 en 20 y limitado a pedidos pagados atribuidos de la tienda autenticada. Cada pedido enlaza al editor local de WooCommerce mediante `get_edit_order_url()`, compatible con HPOS y almacenamiento clásico. La configuración queda plegada y un enlace permite abrir el portal completo.

La atribución requiere activar **WooCommerce → Ajustes → Avanzado → Características → Atribución de pedidos**. Los enlaces salientes a tiendas WooCommerce agregan `utm_source=solodiscos.cl`, `utm_medium=referral`, `utm_campaign=catalogo`; conservan producto, variación y fragmento. El plugin lee el origen nativo UTM/referral del pedido, aceptando únicamente `solodiscos.cl`/`www.solodiscos.cl`. No instala cookies adicionales ni modifica el consentimiento de WooCommerce. El modelo es el de sesión nativo; no se atribuyen pedidos históricos sin evidencia ni se promete recuperar ventas bloqueadas por el navegador. La nueva columna nullable `store_orders.attributed_to_solodiscos` distingue datos antiguos desconocidos; clientes anteriores no borran el indicador al reenviar snapshots sin ese campo. Al actualizar el plugin se reimporta el historial para recuperar atribuciones existentes. Ventas atribuidas y clics del panel se consultan con el mismo calendario **America/Santiago**; las fechas de los pedidos se almacenan en UTC.

Action Scheduler en WooCommerce envía los cambios, reintenta fallos e importa el historial en lotes de 50 pedidos. Debe funcionar el cron de WordPress. La pantalla muestra último envío y errores; las tareas pendientes/fallidas se revisan en WooCommerce → Estado → Acciones programadas. Reimportar el historial permite recuperar fallos sin duplicar ventas. El manual describe eliminaciones, reembolsos y conservación de datos al desconectar.

Despliegue: subir también `app/integrations/` y ejecutar desde `app/`: `php artisan config:clear`, `php artisan route:clear`, `php artisan view:clear`, `php artisan migrate --force`, `php artisan config:cache`. Conservar el `.env` de producción. Desplegar el receptor antes de instalar/actualizar el ZIP en las tiendas (WordPress permite reemplazar la versión instalada conservando la clave). Validación local: pruebas `StoreIntegrationTest`, `EngagementTest`, `StorePortalTest`, `SeoTest`, `php app/integrations/tests/plugin-smoke.php` y `php app/integrations/tests/plugin-render.php` desde la raíz. Las pruebas del plugin usan dobles; queda pendiente validar el ciclo completo en un WordPress/WooCommerce real con HPOS y almacenamiento clásico. Prueba manual: con atribución activada, entrar a la tienda desde un botón público de SoloDiscos en una sesión nueva, comprar con el método de prueba y marcar pagado; comprobar origen en el pedido, su aparición en Ventas desde solodiscos.cl y el enlace al editor. Repetir con una compra directa en otra sesión: no debe atribuirse. Un reembolso parcial debe reducir el monto; reimportar no debe duplicar pedidos. La configuración del servidor debe reconocer HTTPS y permitir el encabezado Authorization en `/api/v1/woocommerce/*`.

Las fuentes evaluadas que se dejaron para una etapa posterior están guardadas en [tiendas-pendientes.json](tiendas-pendientes.json), con plataforma, motivo y observaciones para retomar su integración. No se encolan ni se agregan a la base como tiendas activas.

## Diseño del sitio

El catálogo permite mostrar 25, 50, 100 o 250 discos por página. Las fichas incluyen un botón visible para volver al catálogo al principio y al final; se conservan página, filtros, orden, tamaño de página y posición de desplazamiento. La URL de regreso se limita al catálogo local. Los logos tienen flechas, avance automático y pausa; los banners avanzan cada seis segundos. Se respeta la preferencia de movimiento reducido y se detiene la reproducción al ocultarse la pestaña. El acceso administrativo permanece en `/admin` y `/login`, sin botón en la navegación pública.

La portada incluye una fotografía editorial original, un carrusel de tres banners con controles de pausa y navegación, una franja de tiendas, tarjetas de discos con sus portadas reales, filtros y ordenación por artista, cantidad de tiendas o actualización reciente. Los banners inferiores llevan a CDs o vinilos. La ficha mantiene las ofertas y descripciones de cada vendedor, con un diseño adaptado a móvil.

La imagen propia fue generada con la herramienta integrada de imágenes. Original: `app/public/images/vinyl-editorial.png`; versión web optimizada: `app/public/images/vinyl-editorial.jpg`; prompt: `design/banner-prompt.txt`. Las otras portadas y logos siguen procediendo del catálogo importado. Los textos de banners son HTML y se editan en `app/resources/views/storefront/hero.blade.php`. El diseño público vive en `app/public/storefront.css` y el carrusel en `app/public/storefront.js`. No se agregaron servicios de pago, suscripciones ni formularios promocionales ficticios.

Verificación del rediseño: 36 pruebas aprobadas; revisión en navegador de los tres banners, búsqueda, ordenación, filtro de formato y ficha con tres tiendas. Se comprobó el ancho de 390 px sin desbordamiento horizontal y se restableció el tamaño del navegador al finalizar. El carrusel se pausa con foco o interacción, permite pausa manual y respeta la preferencia de movimiento reducido. Vista de referencia local: `.local/previews/solodiscos-desktop.jpg`.

Comparador local de CDs y vinilos. Laravel 12, PHP 8.2+, MySQL/MariaDB, vistas Blade y CSS local. Esta versión no utiliza IA ni servicios externos de pago. No necesita Docker ni Redis. Livewire y Tailwind no son dependencias de esta primera implementación.

## Clonar y preparar en otro equipo

Requisitos: Git, PHP 8.2 o superior, Composer y MySQL/MariaDB. Las páginas usan CSS y JavaScript del directorio público, por lo que no es necesario compilar con Node para ejecutar esta versión.

```powershell
git clone https://github.com/juanand87/solodiscos.git
cd solodiscos/app
composer install
Copy-Item .env.example .env
php artisan key:generate
```

Crea una base MySQL vacía llamada `solodiscos` y ajusta los valores `DB_*` en `app/.env` a tu instalación. Después, desde `app/`:

```powershell
php artisan migrate
# Opcional: registrar la primera tienda para empezar a importar.
php artisan db:seed --class=InitialStoreSeeder
```

Desde la raíz, ejecuta `./iniciar-local.ps1` si utilizas XAMPP en `C:\xampp`; para otras instalaciones usa los comandos de «Ejecutar por separado». La primera visita al administrador permite crear tu usuario. No ejecutes el seeder genérico de Laravel para crear usuarios: contiene únicamente un usuario de ejemplo para desarrollo.

El repositorio contiene código, migraciones, pruebas e imágenes del diseño. La configuración `.env`, usuarios, catálogo, historial, colas, cachés, huellas de portadas y respaldos de bases de datos permanecen locales. Para conservar los datos existentes en otra instalación hace falta transferir un respaldo de MySQL por separado. GitHub no inicia ni aloja el servidor PHP o MySQL.

## Abrir el proyecto

1. Inicia MySQL desde XAMPP.
2. Ejecuta `./iniciar-local.ps1` en PowerShell desde esta carpeta. Inicia web, cola de importación y programador; los registros quedan en `.local/`.
3. Abre [SoloDiscos](http://127.0.0.1:8000) o [Administración](http://127.0.0.1:8000/admin).
4. La primera visita al acceso permite crear tu administrador local con una contraseña de al menos 12 caracteres. Al crearlo se cierra el registro. No hay una contraseña administrativa predefinida.

La base es `solodiscos` en `127.0.0.1:3306`, usuario `root`, sin contraseña, según la configuración local indicada. Los valores están en `app/.env`, excluido del control de versiones. No publiques esa configuración en producción.

El directorio público del servidor debe ser `app/public`. El `.htaccess` de la raíz impide que Apache exponga código, dependencias o archivos de configuración; para esta instalación abre el puerto 8000 indicado. No uses la raíz del proyecto como directorio público en producción.

En producción, con `APP_URL` configurado en HTTPS, las peticiones HTTP se redirigen a esa dirección antes de iniciar la sesión y verificar CSRF. Esto evita el error 419 al abrir `/login` por HTTP con `SESSION_SECURE_COOKIE=true`. El servidor debe reconocer correctamente las conexiones HTTPS; la instalación local por HTTP se mantiene accesible. La clave `APP_KEY` y los datos de `.env` se conservan al actualizar el código.

## Ejecutar por separado

Desde `app/`, en tres terminales:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
php artisan queue:work --sleep=3 --tries=1 --timeout=1800
php artisan schedule:work
```

El programador revisa cada cinco minutos qué tiendas requieren actualización. Cada tienda tiene un intervalo configurable (48 horas por defecto). Con el equipo apagado no se ejecutan trabajos. En producción se configura el programador con cron y los trabajadores como servicios supervisados.

## GoDaddy y cPanel: extracciones automáticas

En el hosting deben ejecutarse tanto el programador como el trabajador de colas. «Extraer ahora» agrega un trabajo a la cola; si aparece «Esperando al trabajador», el código web funciona pero falta atender esa cola.

El administrador incluye **Procesar pendientes** para iniciar `queue:drain` en segundo plano, sin mantener la página ocupada durante la extracción. Requiere sesión de administrador, CSRF y permite como máximo tres solicitudes por minuto. Comprueba el bloqueo del trabajador y espera una confirmación escrita por el proceso CLI antes de anunciar su inicio. En Linux utiliza un proceso desacoplado y en Windows lo inicia sin ventana visible. Si el hosting deshabilita `proc_open` o rechaza el proceso, muestra el motivo y conserva los trabajos en cola para atenderlos desde Terminal o cron. El registro del arranque está en `storage/logs/queue-button.log`. PHP CLI se detecta automáticamente; si la cuenta requiere una ruta particular, puede indicarse mediante `SCRAPER_CLI_PHP`. No es necesario modificar ni reemplazar `.env` para utilizar la detección automática. Este botón inicia los pendientes del momento; las tareas de cron siguen siendo necesarias para atender futuros trabajos automáticamente.

Después de actualizar el código desde GitHub, desde la carpeta `app/` puedes atender los pendientes manualmente:

```bash
php artisan queue:drain
```

El comando termina cuando no hay trabajos listos o al completar el ciclo de 50 segundos. Ese límite se comprueba **entre trabajos**: una extracción iniciada puede tardar más y termina antes de salir. Un bloqueo del sistema operativo impide que dos ejecuciones de este comando atiendan la cola simultáneamente y se libera incluso si termina PHP. No mantengas además otro `queue:work` manual ejecutándose en paralelo.

En **cPanel → Avanzado → Trabajos de cron**, agrega dos tareas, ambas una vez por minuto (`* * * * *`). Sustituye `USUARIO_CPANEL` y la ruta del proyecto por los valores de tu cuenta. GoDaddy documenta `/usr/local/bin/php` como ruta de PHP para cron; puedes verificar su versión con `/usr/local/bin/php -v` y contrastarla con `php -r 'echo PHP_BINARY, PHP_EOL;'` en Terminal.

Programador de extracciones y lotes de portadas:

```bash
/usr/local/bin/php /home/USUARIO_CPANEL/public_html/solodiscos.cl/app/artisan schedule:run >> /home/USUARIO_CPANEL/public_html/solodiscos.cl/app/storage/logs/scheduler-cron.log 2>&1
```

Trabajador de los trabajos pendientes:

```bash
/usr/local/bin/php /home/USUARIO_CPANEL/public_html/solodiscos.cl/app/artisan queue:drain >> /home/USUARIO_CPANEL/public_html/solodiscos.cl/app/storage/logs/queue-cron.log 2>&1
```

Las tareas respetan la frecuencia configurada por tienda: ejecutar el programador cada minuto no equivale a extraer todas las tiendas cada minuto. Los registros indicados permiten comprobar los errores. El usuario que ejecuta cron debe poder escribir en `storage/` y `bootstrap/cache/`. No hace falta modificar `.env` si la cola ya usa `QUEUE_CONNECTION=database`.

## Tiendas

El panel permite agregar, editar o pausar fuentes, seleccionar plataforma y moneda, configurar frecuencia y condición por defecto. La condición recomendada es “Sin especificar”: solo se usa Nuevo o Usado si hay evidencia o una configuración explícita de la tienda.

Vinilos Por Mayor se registra mediante `php artisan db:seed --class=InitialStoreSeeder`. El dominio definitivo es `https://vinilospormayor.cl` (sin www).

“Extraer ahora” encola el trabajo: debe estar funcionando el trabajador. Puedes también ejecutar `php artisan stores:scrape 1` para encolar la tienda 1. Se evitan duplicados mientras una extracción esté pendiente/en curso. Las extracciones fallidas conservan lo previamente importado y pueden repetirse. Los errores HTTP 429/502/503/504 se reintentan con pausas limitadas.

El panel actualiza automáticamente cada tres segundos sus contadores y las extracciones: tienda, estado, ofertas procesadas en la ejecución, página actual, duración y último avance. Las activas aparecen primero y no desaparecen por el límite del historial reciente. El trabajador guarda avances cada diez ofertas y al completar cada página. No se muestra un porcentaje inventado si el total es desconocido. La consulta de progreso (`/admin/progreso`) requiere sesión, no se almacena en caché y solo lee la base local; no consulta las tiendas. Se pausa cuando la pestaña está oculta, reintenta errores de conexión y avisa si termina la sesión.

## Alcance de los conectores

- Shopify: catálogo público `/products.json`, paginado de 100 publicaciones, una oferta por variante. La moneda se configura manualmente en la tienda: debe coincidir con la real. Este endpoint no está habilitado en todas las tiendas.
- WooCommerce: Store API pública, precios según moneda y unidad menor, disponibilidad y descripciones. Los productos variables se omiten y se reportan: necesitan adaptar el conector antes de importarlos, para no mezclar precios mínimos de un padre con variantes.
- Bsale: conector público para la plantilla que incluye `coll_data_base` en `/collection/vinilos` y `/collection/cds`, probado con Plaza Música. Recorre cada colección en páginas de 100 y sigue su indicación `rel=next`. Guarda un cursor por colección para reanudar errores. Usa `finalPrice` (precio final de la ficha del catálogo, no precio neto ni el anterior al descuento), `variantId`, `defaultImage`, descripción y `totalStock` con `stockControl=1`. Stock cero, bajo pedido o sin datos no se publica. El inventario se toma de los datos del catálogo; algunas plantillas tienen JSON-LD de disponibilidad inconsistente. La moneda debe configurarse correctamente en la tienda. No requiere claves de la API administrativa Bsale. Otras plantillas, rutas o estructuras necesitan adaptar el conector; no se garantiza compatibilidad universal.
- Plaza Música se registra con `php artisan db:seed --class=PlazaMusicaSeeder`. Sus títulos con patrón `VINILO ARTISTA / ÁLBUM` se normalizan para agruparlos, preservando el título de origen. Los marcadores explícitos de formato y algunas características de edición se quitan del nombre general. Las condiciones no indicadas permanecen sin especificar.
- Los registros Bsale sin identificadores, título o enlace de producto se omiten y se reportan en la extracción como observaciones. Una página que no contiene el catálogo estructurado se considera error, no un catálogo vacío. Así no se publican fichas incompletas ni se retiran ofertas por una respuesta inesperada.
- Primera carga verificada de Plaza Música (25 de noviembre de 2026): 4.589 ofertas guardadas (2.237 vinilos, 2.351 CDs y un formato fuera del catálogo). Se completaron ambas colecciones y se omitió un registro del proveedor sin título ni enlace válido. La importación se reanudó correctamente tras detectar ese caso. Verificación del proyecto: 36 pruebas aprobadas, 201 aserciones. Se contrastó en navegador el precio final de $44.993 y la compra habilitada de una ficha de ejemplo frente a sus datos de catálogo.
- No se ejecutan navegadores ni JavaScript de las tiendas. Las fuentes que requieran eso necesitan un conector adicional.
- Límite de 200 páginas por ejecución, peticiones secuenciales con pausa y registro de fallos.
- Las páginas completadas dejan un punto de reanudación: una nueva ejecución continúa desde la página pendiente. Los cambios de catálogo durante un ciclo pueden requerir el siguiente recorrido para reconciliarse.
- `SCRAPER_TRANSPORT=http` usa el cliente HTTP de Laravel. La instalación local usa `SCRAPER_TRANSPORT=system` (cURL del sistema) porque en la prueba de esta tienda el cliente PHP recibió 429 y cURL pudo acceder. Ambos verifican TLS, validan dominios públicos y no siguen redirecciones. El transporte del sistema requiere `curl.exe` en Windows o `curl` en Linux.
- Un error de consulta NO se interpreta como agotamiento. Después de un recorrido completo y validado, las ofertas ausentes quedan despublicadas sin borrar su último estado de origen; si reaparecen con stock vuelven a mostrarse. Un agotado explícito se despublica inmediatamente. Datos sin verificar por más de siete días tampoco se muestran.
- Se guardan observaciones cuando cambia precio o disponibilidad, sin un gráfico público todavía.

## Clasificación sin IA

Se reconocen CD/vinilo y nuevo/usado mediante reglas del título, etiquetas y categorías. Un título `Artista - Álbum` o con guion largo permite proponer una asociación conservadora. No se adivina el artista a partir del vendedor de Shopify. Sin evidencia suficiente, queda pendiente en Publicaciones.

La clasificación del formato usa el tipo original del producto y la variante antes de las etiquetas, categorías y atributos. Una descripción libre que menciona CDs o vinilos no basta para decidir el formato; un campo explícito `Formato:` sí puede aportar evidencia. Los libros y accesorios se excluyen por tipo/categoría explícitos o datos bibliográficos inequívocos (p. ej. encuadernación de libro, ISBN con número de páginas, o descripción de un volumen de la colección 33 1/3). Un disco que se llama “The Book of Souls”, incluye un booklet o gira a 33 1/3 RPM no se excluye por esas palabras.

Se guarda `source_metadata` con los tipos, etiquetas, categorías y atributos recibidos, además del motivo de formato o exclusión. Los productos excluidos no aparecen en el catálogo ni en mínimos y se conservan en administración con su historial. No se permite publicarlos eligiendo CD manualmente. `php artisan catalog:audit-products` revisa los datos históricos sin volver a consultar las tiendas y solo excluye ante evidencia explícita; no reclasifica a ciegas los registros antiguos que aún no tienen metadatos de origen. Las siguientes importaciones completan esa evidencia.

La pantalla Revisar permite fijar artista, álbum, formato y condición. Dos ofertas con el mismo artista y álbum se agrupan; las correcciones manuales permanecen al importar. No se identifican ediciones exactas. La agrupación automática por texto es inicial: variantes de nombres, sufijos de edición y productos especiales necesitan revisión manual. El precio mínimo excluye agotados, precio cero/desconocido, formato desconocido, ofertas sin álbum, tiendas pausadas y datos de más de siete días. Las monedas distintas se presentan separadas.

Cada publicación pública corresponde a artista + álbum + formato: CD y vinilo tienen tarjetas y enlaces separados (`/album/{id}/cd` y `/album/{id}/vinyl`). Los nombres se normalizan por mayúsculas, tildes, puntuación y algunos marcadores explícitos de formato/edición. No se usa similitud difusa para unir títulos distintos. La condición se muestra por oferta y no divide la publicación general. Las fichas solo cargan ofertas publicables; si se agotan todas, la ficha deja de estar disponible públicamente. “Avisarme”, cuando aparece como control de compra/disponibilidad, también despublica la oferta. No se toma una mención genérica en la descripción como prueba de agotamiento.

## Logos y segunda tienda

Obi Vinilos (WooCommerce, `https://obivinilos.cl`) se registra con `php artisan db:seed --class=ObiStoreSeeder`. La primera importación completa incorporó 2.346 publicaciones el 25 de noviembre de 2026. El conector conserva descripción corta y extensa y reconoce formato/condición a partir de categorías y atributos.

El logo se descubre al importar por primera vez buscando imágenes de marca del HTML, con icono del sitio como alternativa. Se guarda su URL pública, no se descargan ni ejecutan archivos de las tiendas. Se puede editar o extraer de nuevo desde Editar tienda, o ejecutar `php artisan stores:logos`. Si la extracción del logo falla, el catálogo sigue importándose. Las ofertas muestran “Vendido por:” junto al logo y nombre; sin logo se conserva el nombre.

`php artisan catalog:regroup` reagrupa asociaciones automáticas existentes con las reglas actuales y decodifica títulos de origen; conserva las clasificaciones manuales. No borra los álbumes antiguos para preservar su referencia administrativa. Las URLs públicas antiguas sin formato redirigen a un formato con ofertas publicadas.

Las descripciones se muestran como texto escapado; se conservan títulos e imágenes originales. Los vínculos a las tiendas abren en otra pestaña. El envío no forma parte del precio mostrado.

Las descripciones se compactan al importar: una línea por dato o canción, sin líneas vacías repetidas, y una separación breve antes del tracklist o los lados del disco. `php artisan catalog:clean-descriptions` aplica la misma limpieza a lo ya guardado, sin volver a consultar las tiendas.

Musicland (`https://musicland.cl`) utiliza WooCommerce y se registra con `php artisan db:seed --class=MusiclandSeeder`. Comparte el conector público de Obi Vinilos, con logo automático e intervalo inicial de 48 horas. Su catálogo también contiene equipos y productos con categorías de formato contradictorias: se aplican las exclusiones y la revisión conservadora habituales. Las descripciones admiten textos extensos de box sets mediante una columna MEDIUMTEXT; la migración conserva esa capacidad al revertirse para evitar pérdida de tracklists.

La identidad normaliza espacios alrededor de `/`, `&` y `+`, además de mayúsculas, tildes y caracteres invisibles: `AC / DC` y `AC/DC` comparten álbum, sin borrar espacios entre palabras distintas. `catalog:regroup` aplica la regla a asociaciones automáticas existentes y conserva revisiones manuales. Los importadores guardan SKU y códigos de barras disponibles; también se extraen números de catálogo explícitos de la descripción. Un GTIN solo se considera válido con longitud estándar y dígito de control correcto y se representa con 14 dígitos (UPC y EAN con cero inicial equivalentes). Los códigos originales se conservan en administración como evidencia adicional; no se fusionan títulos contradictorios ni se usa similitud de SKU como prueba automática. El formato público sigue separado entre CD y vinilo.

Billboard (`https://www.billboard.cl`) se registra con `php artisan db:seed --class=BillboardSeeder`. Usa Bsale con colecciones `vinilos` y `cd`, configuradas por dominio en `config/scraping.php`. El conector admite tanto el bloque JSON `coll_data_base` como el objeto JSON contenido en `window.INIT.collections.push(...)`; solo decodifica el literal JSON y nunca ejecuta JavaScript. Los títulos sin separación fiable de artista y álbum quedan pendientes; no se adivinan sus límites a partir de nombres concatenados.

La identificación de Billboard también contrasta prefijos completos de artista y álbum con referencias de otras tiendas, admite nombres invertidos marcados con coma (`MORISSETTE,ALANIS`) y variantes inequívocas con/sin `The`. Si el álbum completo coincide se reutiliza su identidad; si solo se conoce el artista se exige un límite inequívoco y un nombre de varias palabras o una inversión explícita. Nombres de una palabra sin álbum conocido, colaboradores ambiguos y múltiples candidatos quedan pendientes. No se usa similitud difusa ni IA, ni se aprende de las propias inferencias de Billboard. Se guarda `identity_evidence`, visible al revisar la publicación. Las próximas importaciones usan las mismas reglas. `php artisan catalog:identify-billboard` simula sobre pendientes ya guardados; `--apply` guarda las asociaciones, conserva correcciones manuales y no modifica precio, stock ni fecha de comprobación. `--examples=4000` amplía el informe de ejemplos.

La segunda revisión de Billboard unifica referencias de artista con artículo inicial o final (`The Beatles`, `BEATLES, THE`; `Los Bunkers`, `BUNKERS, LOS`), admite apellidos compuestos con coma y separadores sin espacios. Para artistas de una palabra se exigen dos tiendas y dos álbumes, o al menos tres álbumes y cinco caracteres de nombre; palabras genéricas como `Love`, `Air`, `Yes` o `Heart` siguen requiriendo coincidencia de álbum. `G.H.` se contrasta con `Greatest Hits` únicamente como título completo, y `Various Artists`/`Varios Artistas` permite identificar compilaciones explícitas. No se interpreta `OST` por sí solo como varios artistas, ni se consumen iniciales de compositores como parte del álbum. El informe de `catalog:identify-billboard --examples=4000` incluye tanto asociaciones propuestas como títulos no resueltos. Las referencias de nombres obtenidas por estas reglas no se realimentan al diccionario.

La revisión por códigos se ejecuta de forma explícita con `php artisan catalog:enrich-billboard --apply --limit=300` (por defecto solo pendientes con stock; `--include-out-of-stock` incluye agotados y `--offer=ID` permite comprobar uno). Lee el SKU/GTIN del bloque Product JSON-LD de la ficha, exige código válido y coincidencia de formato, artista y álbum en una respuesta completa de MusicBrainz. Un resultado ambiguo o error no asigna identidad. Hay pausas de al menos 1,1 segundos entre consultas de MusicBrainz, caché privada de siete días y bloqueo para impedir dos revisiones simultáneas; se detiene tras diez errores. Las respuestas con error no se cachean como ausencias. La consulta externa no cambia precio, stock ni fecha de comprobación; una identidad confirmada se conserva en las siguientes importaciones solo mientras el título original sea el mismo. No se añadió esta tarea al programador automático. Se conserva el enlace de MusicBrainz en `verified_identity.source_url`. Documentación y condiciones del servicio: https://musicbrainz.org/doc/MusicBrainz_API .

También se distinguen prefijos de artista solapados (p. ej. Pink/Pink Floyd) cuando el nombre más específico tiene al menos dos tiendas y tres álbumes de referencia y las demás coincidencias son prefijos estrictos. Se limpian separadores literales `\\t`/`\\n`, errores explícitos de prefijo `VNILO`/`VIN ILO` y notas técnicas de edición solo al contrastar títulos completos; se conservan subtítulos musicales como `(Live in New York)`.

La Tienda Nacional (`https://latiendanacional.cl`, Shopify) se registra con `php artisan db:seed --class=LaTiendaNacionalSeeder`. Por su catálogo mixto se recorren únicamente las colecciones públicas `vinilos` y `cds`, definidas en `scraping.shopify_collections` por dominio. El campo `shopify_collection` guarda la colección pendiente junto con `next_page`; un fallo no se interpreta como agotamiento y solo un recorrido completo concilia ofertas ausentes. El título original se conserva, pero se elimina el sufijo explícito `| VINILO` o `| CD` para agrupar álbumes y se retira `(PREVENTA)` del nombre del artista sin publicar esa oferta como disponible. Se conservan las exclusiones de libros y accesorios y el stock por variante.

Punto Musical (`https://puntomusical.cl`) se registra con `php artisan db:seed --class=PuntoMusicalSeeder`. Su catálogo público usa la ruta anterior `/wp-json/wc/store/products`, configurada por dominio en `scraping.woocommerce_endpoints`; los demás sitios WooCommerce conservan `/wp-json/wc/store/v1/products`. Se reutilizan las reglas de paginación, moneda, variantes, stock, clasificación y exclusiones del conector común. Los sufijos de formato con guion (`Álbum – VINILO`) se eliminan junto con el guion al normalizar el nombre del álbum. El transporte cURL del sistema reintenta errores transitorios de conexión (18, 28, 35, 52, 55 y 56) dentro de su máximo de tres intentos, con pausas de 5 y 10 segundos. Las consultas configuradas sin reintento, como MusicBrainz, mantienen un solo intento.

`php artisan catalog:review-punto` simula la reclasificación de pendientes de Punto Musical; `--apply` la guarda. Usa los metadatos ya importados y no hace consultas externas. Reconoce cantidades de varios dígitos (`10CD`, `56CD`), toma como formato principal el sufijo explícito del título en esta tienda y diferencia a la artista LP del formato. Los DVD/Blu-ray/cassettes identificados y los equipos de reproducción se excluyen; un CD con DVD adicional se conserva como CD. También contrasta GTIN válidos y únicos con otras tiendas, exige nombre compatible y deja pendientes códigos contradictorios. Para artista/álbum puede usar referencias de otras fuentes o separadores explícitos, sin aprender de las propias asociaciones de Punto Musical. Bandas sonoras sin artista claro y compositores unidos no se separan a ciegas. Se guardan las evidencias en los metadatos y se conservan stock, precio, fecha de comprobación y revisiones manuales. Las siguientes extracciones de Punto Musical aplican la misma revisión automáticamente.

Al Toque Records (`https://altoquerecords.cl`, Shopify) se registra con `php artisan db:seed --class=AlToqueRecordsSeeder`. Se importa mediante el catálogo público común, con precio y stock por variante. Los tipos `Vinilo usado` y `Nuevos & Reediciones` aportan la condición. Se conservan título y descripción originales, pero las notas explícitas de edición (`LIMITED EDITION`), segunda mano (`2DA MANO`), postal incluida y colores técnicos (`MARBLE`, `SPLATTER`, `SWIRLS`) se omiten de la identidad del álbum; la condición sigue mostrándose por oferta.

`php artisan catalog:review-altoque` simula la revisión de pendientes de Al Toque Records; `--apply` guarda los resultados sin modificar precio, stock, condición, fecha de comprobación ni correcciones manuales. Lee bloques explícitos `Formato:`/`Format:` de las descripciones (incluidos `2 x Vinilo` y un CD adicional), diámetros de discos en el título y separadores con punto o guion. Gift cards de la tienda, slipmats, calendarios y logos digitales quedan excluidos. El SKU original se conserva: solo para esta tienda puede derivarse un código de `N` seguido exclusivamente de dígitos si cumple longitud y checksum GTIN; ese código requiere una coincidencia local única y nombre compatible para aportar identidad o formato. Las referencias excluyen a la propia tienda. Las bandas sonoras no se asignan a un artista por un prefijo parcial del título. Las próximas importaciones usan las mismas reglas; no se añaden consultas a servicios externos.

El empaque explícito al final del nombre (`CD Jewel Case`, `CD Digipak` o `CD Digipack`) se omite de la identidad del álbum. Por ejemplo, `Adele – 30 CD Jewel Case` comparte álbum con `Adele – 30`, manteniendo el título original de la oferta y la separación pública entre CD y vinilo.

## Revisión de portadas sin IA

La administración incorpora `/admin/portadas`, enlazada como **Portadas**. El botón **Comparar portadas** encola un recorrido con filtro opcional de artista y límite de imágenes nuevas **por lote** (25 por defecto en el formulario). Con artista vacío recorre todo el catálogo identificado de tiendas activas, incluidos agotados. También puede ejecutarse `php artisan covers:scan --limit=25` o filtrarse con `--artist=Adele`; siempre se encola, y `--queue` se conserva por compatibilidad. Se necesita PHP GD y el trabajador activo. No se usan modelos, embeddings ni APIs de IA.

Los pares y las imágenes pendientes se guardan en MySQL. Cada trabajo descarga hasta el límite por lote, con una pausa de medio segundo entre imágenes nuevas y un presupuesto aproximado de 90 segundos; una consulta ya iniciada puede excederlo. Procesa hasta 500 imágenes cacheadas y 1.000 pares preparados por trabajo, y encola automáticamente el siguiente hasta resolver el recorrido. No hay límite global de pares ni de dos portadas por álbum: se consideran todas sus URLs distintas. El progreso muestra imágenes, pares resueltos, lotes, propuestas y errores acumulados. El cursor de lote evita repetir trabajos atrasados; las descargas y pares ya guardados se reutilizan tras un reintento. `covers:continue`, programado cada minuto, recupera continuaciones perdidas con más de diez minutos sin avance cuando no existe ya un trabajo del recorrido en la cola. Se requieren trabajador y programador activos (`iniciar-local.ps1`); no inicia otro recorrido completo periódicamente. Cada recorrido toma una instantánea del catálogo; para incluir nuevas importaciones posteriores se inicia otro desde el administrador al terminar el actual.

El arranque local configura el trabajador con `--memory=512`, porque preparar el plan completo de unas 48.000 ofertas supera el umbral predeterminado de 128 MB que haría salir al trabajador después del primer lote. La cola y los puntos de avance permanecen en MySQL si se apaga el equipo; al reiniciarlo se ejecuta nuevamente `iniciar-local.ps1`.

La huella `phash-dct32-v1` convierte la imagen a luminancia de 32 × 32, aplica una DCT y compara los 63 coeficientes AC de baja frecuencia con su mediana; se almacena como 16 dígitos hexadecimales, con el bit DC fijo. Se proponen distancias de Hamming de hasta 10 y similitud textual mínima de 0,30: son umbrales iniciales para revisión humana, no probabilidades. Solo se comparan ofertas del mismo artista normalizado y formato CD/vinilo, en álbumes distintos, con identidad conocida e imagen. Se excluyen artistas genéricos de compilaciones, imágenes uniformes o URLs de imágenes genéricas conocidas. Números de álbum contradictorios como 25/30 bloquean la propuesta. No se afirma que este filtro encuentre todas las variantes de portada, fotos o recortes. Las imágenes fallidas se contabilizan y sus pares se omiten sin bloquear las demás comparaciones.

Las imágenes se descargan con límite de 5 MB y 16 MP, solo JPEG/PNG/WebP. Las miniaturas JPEG quedan en almacenamiento privado y se sirven con autenticación. Las redirecciones de imágenes se siguen hasta tres saltos, validando destinos públicos y fijando DNS en cada uno; las consultas de catálogo continúan sin seguir redirecciones. Las huellas, propuestas y recorridos se guardan en sus tablas dedicadas.

Toda agrupación requiere elegir el álbum de destino y confirmar que es el mismo álbum y formato. Se mueven solo ofertas automáticas del formato elegido y se marcan revisadas para conservar la decisión al importar; no cambian títulos originales, condición, stock, precio ni fecha de comprobación. Las clasificaciones manuales previas se protegen. Se registran las ofertas movidas y se permite deshacer mientras no haya cambios posteriores. Descartar evita volver a proponer el mismo par con las mismas imágenes e identidades; propuestas con datos cambiados no se pueden aprobar. El primer recorrido real dejó pendiente Adele «25 CAN»/«25», con distancia 6; no se agrupó automáticamente.

## Estadísticas del catálogo

La sección privada `/admin/estadisticas` ofrece resumen general y por tienda, incorporaciones, agotamientos confirmados, reposiciones y cambios de precio. Filtra por fechas inclusivas en America/Santiago, tienda, formato y movimiento; el CSV UTF-8 con BOM y separador punto y coma exporta todos los registros filtrados, no solo la página. Neutraliza fórmulas en las celdas. Los contadores resumen los cuatro movimientos con los mismos filtros de fechas, tienda, formato y carga inicial; el filtro de tipo se aplica al listado y a la exportación.

`offer_events` se registra en la misma transacción que cada oferta y observación. Solo `in_stock → out_of_stock` cuenta como agotamiento y `out_of_stock → in_stock` como reposición; no se interpretan ausencias, fallos, preventas ni estados desconocidos como ventas o reposiciones. Se guarda la comprobación anterior y la fecha de detección. Las novedades son ofertas por tienda, no álbumes únicos. La primera carga de cada fuente se marca y se oculta por defecto. No se suman importes ni se infieren unidades vendidas. El formato y la identidad del disco usan la clasificación actual, de modo que una corrección manual también se refleja en los filtros.

Después de migrar, `php artisan stats:backfill` recupera eventos del historial existente por lotes de 200 ofertas. Se puede repetir: las claves de evento evitan duplicados y conservan los registros nuevos ya existentes. La carga inicial histórica se determina usando la primera extracción completada de cada tienda; sin una extracción completada se considera carga inicial. Las observaciones antiguas no registraban moneda ni todas las comprobaciones sin cambios: sus variaciones numéricas se muestran con moneda no registrada y su referencia anterior es la observación anterior disponible. Desde esta implementación se guardan moneda y comprobación anterior; un cambio de moneda o de precio desconocido no se cuenta como variación de precio. El historial recuperado se distingue del nuevo.

## Rankings de tiendas

Cada tienda permite elegir **Identificar automáticamente si es nuevo o usado**, **Todos los productos son nuevos** o **Todos los productos son usados**. Se conserva el campo `default_condition` (`unknown`, `new`, `used`), pero las opciones `new`/`used` ahora son políticas obligatorias, no simples valores de respaldo. Al guardar se actualiza la condición de las ofertas existentes, incluso las revisadas, sin cambiar identidad, formato, precio, stock o comprobación. Las siguientes extracciones aplican esa política aunque la fuente diga otra cosa; el importador consulta y bloquea la configuración vigente por producto para evitar que un trabajo iniciado antes use una política antigua. La lista de tiendas muestra el modo elegido y la edición individual remite a esa configuración si la condición está fijada.

Al volver a automático se reevalúan los metadatos guardados y el título de las ofertas no revisadas, respetando correcciones manuales y conservando «sin especificar» cuando no hay evidencia. Un cambio de condición invalida los rankings y cierra las rebajas cuyo seguimiento correspondía a la condición anterior; no genera bajadas de precio ni comprobaciones ficticias. Punto Musical se configura como «Todos los productos son nuevos» por confirmación del usuario.

La revisión de fuentes oficiales del 26/11/2026 queda en `.local/store-condition-review.json`, con enlaces, motivos, modos y cantidades por tienda. Se aplicó «Todos nuevos» a Tienda Vinilos y La Tienda de Música por declaración expresa en sus páginas; las mixtas o no confirmadas conservan modo automático. La identificación automática descarta expresiones musicales como New Wave, New Age, Nueva Canción, Nueva Ola o The Used como evidencia de condición y reconoce afirmaciones explícitas en descripciones, como «nuevo y sellado», «Estado: Nuevo» o «Vinilo usado». Se conservan clasificaciones anteriores sin metadatos en revisiones de registros antiguos cuando no existe evidencia nueva ni contradictoria. Las categorías antiguas de Viniloteca se recuperaron de su API pública; los registros antiguos de Obi se restauraron verificando sus huellas de identidad del seguimiento y los totales previos, documentado en `.local/obi-condition-recovery.json` y `.local/viniloteca-condition-recovery.json`. El script de revisión guarda copias individuales de las condiciones antes de aplicar nuevas revisiones.

Los rankings están en `/rankings` (público) y `/admin/rankings` (autenticado). Filtran por CD/vinilo, nuevo/usado y CLP/USD/EUR, sin convertir monedas. Solo consideran ofertas identificadas, de tiendas activas, no excluidas, disponibles, no ausentes, con precio positivo y comprobadas en las últimas 72 horas. El caché dura cinco minutos y cambia de versión después de confirmar transacciones que modifican precio, identidad o disponibilidad, y al terminar una importación.

El ranking de precios separa nuevos y usados y excluye condición desconocida. Elige el precio mínimo por tienda, álbum, formato y condición, exige al menos dos tiendas por grupo y 30 álbumes/formato comparables por tienda. El índice es 100 por la mediana de las relaciones entre precio de la tienda y mediana de sus competidores; menor es mejor. Muestra cantidad comparada y porcentaje de mínimos, incluidos empates. Las tiendas sin muestra suficiente no reciben posición. Cada fila indica cuántos competidores distintos tiene. Se advierte muestra concentrada cuando más del 50% de sus discos se compara exclusivamente con el mismo competidor; la presencia de un competidor en comparaciones con varias tiendas no activa por sí sola el aviso. Las ediciones pueden variar y las muestras compartidas difieren, como advierte la página.

La variedad cuenta álbum y formato una sola vez aunque haya múltiples ediciones o condiciones; muestra además CDs, vinilos y publicaciones. Respeta todos los filtros, incluida moneda. Los descuentos ordenan por mediana de porcentajes de ofertas históricas activas; requieren diez álbumes/formato distintos en oferta y muestran su proporción del catálogo. Por álbum/formato/condición se considera la publicación más económica y, si hay rebajas en más de una condición, ese álbum/formato cuenta una vez con su mayor porcentaje.

`offer_price_days` guarda las comprobaciones diarias, incluso sin cambios, con mínimo intradía y precio más reciente. `offer_price_tracking` conserva el estado de la rebaja. Una oferta verificada requiere mejorar el mínimo de los 30 días previos a su inicio, al menos diez días con comprobaciones y cobertura desde el comienzo de la ventana sin intervalos mayores de 72 horas. La última comprobación anterior al inicio de la ventana aporta una referencia conservadora y no puede superar las 72 horas de antigüedad. Las jornadas inválidas, cambios de moneda/identidad y huecos de seguimiento impiden verificar la oferta. La referencia se mantiene fija durante la rebaja; una subida, indisponibilidad, cambio de identidad o hueco de seguimiento la cierra. La web deja de mostrarla si ya no coincide con precio, identidad o comprobación actual. Una nueva bajada se contrasta nuevamente con el mínimo histórico, incluyendo los precios bajos de ofertas anteriores.

Sin cobertura histórica suficiente se muestra **Bajada detectada** con ahorro, porcentaje y fechas, tanto en el ranking como en la ficha de álbum. Estas bajadas no suman al ranking de descuentos verificados. `php artisan prices:seed` inicia, de forma repetible, el seguimiento con la última comprobación conocida de las ofertas disponibles recientes; no reconstruye días de historial ni inventa promociones. Las siguientes extracciones completan el historial automáticamente; hay que reiniciar el trabajador al desplegar este cambio.

## Comprobaciones

`php artisan rankings:audit` verifica las 27 combinaciones de formato, condición y moneda en una instantánea consistente de MariaDB y con la hora de corte fijada. Contrasta agrupaciones y precios mínimos mediante SQL independiente, medianas de competidores, muestras, empates de precio mínimo, orden de las tiendas, variedad y descuentos. Renderiza las vistas pública y administrativa y comprueba que sus posiciones coincidan y que las tiendas no clasificadas queden sin posición. Guarda resultados y cobertura por tienda en `.local/rankings-audit.json`; no modifica productos, precios ni stock. Los estados que aún no tienen ejemplos reales se cubren además con `RankingsTest`.

`php artisan stats:audit` contrasta todas las tiendas, formatos, tipos de movimiento, inclusión de carga inicial y límites de fechas con consultas SQL independientes sobre una instantánea consistente de MariaDB. Comprueba los totales generales, el desglose por tienda, la paginación, el CSV completo, UTF-8, columnas, valores filtrados y protección contra fórmulas; también contrasta las transiciones del historial con sus eventos. No modifica ofertas, precios, stock ni eventos. Guarda el informe en `.local/statistics-audit.json` y un CSV general de referencia en `.local/estadisticas-todas-las-tiendas.csv`, fuera de la carpeta pública. Las extracciones pueden continuar mientras se ejecuta.

```powershell
cd app
php artisan test
php artisan view:cache
```

Las pruebas usan SQLite en memoria, no alteran la base real. Cubren agrupación, importaciones repetidas, agotados, fallos, correcciones persistentes, colas duplicadas, moneda WooCommerce y acceso administrativo.

Verificación inicial del 25 de noviembre de 2026: 12 pruebas aprobadas (56 aserciones), migraciones aplicadas en MariaDB de XAMPP e importación completa de Vinilos Por Mayor. Se importaron 1.176 ofertas: 879 vinilos, 276 CDs y 21 formatos sin especificar. Había 72 publicaciones pendientes de artista/álbum o formato. Se verificaron en navegador el catálogo, la búsqueda, una ficha de álbum y la pantalla inicial de administración. No se creó una cuenta administrativa: el usuario elige sus propias credenciales en la primera visita.

## Preparar producción

Configura un servidor con directorio público `app/public`, PHP compatible, base de datos y usuario propios, `.env` de producción, `APP_DEBUG=false`, HTTPS, respaldos, cron y supervisión de trabajadores. Ejecuta migraciones y cachea configuración/vistas. Conserva las credenciales fuera del repositorio. La creación inicial de administradores por web está restringida al entorno local.
# Condición por categoría

El selector **Cuando no coincida ninguna categoría** permite elegir Sin especificar, Nuevo o Usado. Solo actúa cuando se recibieron categorías válidas y ninguna coincide; la falta de categorías y las coincidencias contradictorias siempre quedan sin especificar. Por ejemplo, Disquería: categoría de nuevos `Nuevos y Sellados`, categorías de usados vacías y selector `Usado`. Esta regla aplica también al catálogo existente al guardar y respeta las correcciones manuales.

En Administrador → Editar tienda, selecciona **Identificar condición por categoría** y escribe las categorías de nuevos y usados en sus campos separados, una por línea. Puedes usar el nombre o slug exacto; se normalizan tildes, mayúsculas, espacios y guiones. No se usan coincidencias parciales ni se heredan subcategorías.

Al guardar se reclasifican las ofertas existentes no corregidas manualmente y se invalidan los rankings. Las próximas extracciones usan las reglas vigentes. Por defecto, si no hay coincidencia la condición queda sin especificar; el selector permite definir otra condición para esos casos. Si el producto coincide con ambos grupos, siempre queda sin especificar. No se modifican precios, stock ni fechas de comprobación. Se impide guardar una categoría en ambos grupos.

WooCommerce conserva nombres y slugs de categorías en `source_metadata`; las importaciones antiguas pueden necesitar una nueva extracción para obtener los slugs. Bsale permite comparar la colección consultada. Shopify no entrega las colecciones en su catálogo público: requiere un conector adicional para usar este modo. Las etiquetas y los tipos de producto no se toman como categorías. Los campos quedan almacenados por tienda para futuros filtros.

# Identificación de Needle sin IA

El conector de Needle conserva el campo público Shopify `vendor`, que esta tienda usa para indicar el artista. La adaptación lee ese artista y el título del álbum, retira el artista al final de títulos `Álbum – Artista` y normaliza los indicadores de formato. Una coincidencia completa de artista, álbum y formato reutiliza la publicación general de otra tienda. No se usan similitudes aproximadas ni se agrupan CDs con vinilos. Los títulos antiguos sin artista comprobable quedan pendientes. Las correcciones manuales se conservan y los equipos de reproducción quedan excluidos.

Para revisar el catálogo existente se puede ejecutar `php artisan needle:review --catalog=../.local/needle-live-catalogue.json` y añadir `--apply` para aplicar la propuesta. El archivo de entrada contiene `complete=true` y los productos de todas las páginas públicas. La revisión crea un respaldo y un informe en `.local`, conserva precio, condición, stock y fecha de comprobación y cierra el seguimiento de descuentos cuya identidad cambió. Las próximas extracciones aplican automáticamente la adaptación.

# Precios inválidos del origen

La importación comprueba que los precios sean finitos, no negativos y representables en las columnas DECIMAL(14,2), antes de guardar ofertas e históricos. Un valor fuera de rango queda como precio nulo, despublicado y con el valor recibido en `source_metadata.price_issue`. La extracción continúa y termina con una observación que indica cuántos precios fueron rechazados. La siguiente extracción puede recuperar automáticamente el precio si la tienda lo corrige; no se adivina un precio ni se conserva uno anterior como vigente.

# Discos incluidos en la muestra del ranking

El contador de álbumes comparados enlaza a `/rankings/{tienda}/comparados/{new|used}` (también en `/admin/rankings`). Conserva formato, condición y moneda. La página usa los mismos filtros de disponibilidad y antigüedad que el cálculo del ranking, muestra doce álbumes por página y las tres ofertas más económicas de tiendas distintas, o dos si solo existen dos. Los precios empatados reciben las mismas estrellas. El precio de la tienda consultada permanece visible aunque quede fuera de las tres más baratas. Cada álbum enlaza a todas sus alternativas y descripciones.

# Tiendas recomendadas: mínimo de 100 productos

`/rankings` y `/admin/rankings` muestran hasta diez tiendas con al menos 100 productos comparables, ordenadas por el precio relativo exacto. Se usa toda la muestra disponible, sin limitarla a cien. En la vista general se calcula la mediana del conjunto de relaciones de nuevos y usados, comparados siempre por separado; no se promedian los índices de ambas condiciones. Los filtros de formato, condición y moneda también se aplican a esa muestra.

Las estrellas representan la posición en el Top 10: 5 en el primer puesto y 3 en el décimo, con una progresión lineal redondeada a un decimal. Si hay menos tiendas, se mantiene esa misma escala por posición. El tooltip conserva la diferencia real de precios respecto de la referencia. No son reseñas ni calificaciones de servicio. Las tiendas restantes aparecen sin estrellas; las que no alcanzan el mínimo tampoco reciben posición. El contador «Productos recomendados» abre la muestra completa, con sus ofertas y filtros. Las estadísticas de variedad, descuentos y bajadas se encuentran en `/rankings/estadisticas` y su equivalente privado.

Auditoría independiente de Obi: `.local/audit-obi-comparisons.php`; comprueba las 27 combinaciones de formato, condición y moneda, las tres ofertas mínimas por tiendas distintas, empates y correspondencia entre muestra y ranking. Guarda resultados en `.local/obi-comparisons-audit.json`.

# Extracción de agotados de WooCommerce

Las consultas al catálogo público solicitan explícitamente `stock_status[]=instock`, `outofstock` y `onbackorder`. Algunas tiendas, como Viniloteca, ocultan los agotados cuando no se indica este filtro. Incluirlos permite conservar el estado real y registrar las transiciones confirmadas de reposición y agotamiento. Una ausencia del listado por sí sola sigue sin considerarse una venta ni prueba de agotamiento. La fecha del movimiento corresponde a su detección, no a una fecha de venta confirmada.

# Verificación de stock y eventos por tienda

La auditoría `.local/audit-store-stock-visibility.php` contrasta muestras públicas de las tiendas activas y conserva los resultados en `.local/stock-visibility-{id}.json`. En WooCommerce se contrastan un agotado explícito y su visibilidad con y sin el filtro de estados; Shopify se revisa por variante; Bsale usa stock controlado y cantidad real informados por su catálogo.

Después de completar una extracción se verifican hasta 20 publicaciones ausentes por ciclo. La comprobación usa el producto individual de WooCommerce o Shopify, o el JSON-LD de la ficha Bsale, exigiendo que corresponda a la URL del producto y un estado inequívoco. Si el origen no confirma el estado, no se genera un agotamiento confirmado. La regla de desaparición descrita arriba puede generar una posible venta estimada. La comprobación fallida tiene una espera de 24 horas antes de reintentar. Esta revisión no actualiza el precio ni su fecha de comprobación y no vuelve a publicar una oferta ausente. Los eventos de stock quedan fechados cuando se detectan. Una reposición confirmada al reaparecer en el catálogo se registra por el importador común.

`OfferEvents` registra una incorporación por oferta y movimientos de reposición, agotamiento y precio por observación. La carga inicial está registrada y se oculta por defecto en las estadísticas. «Recién agregados» significa primera detección en SoloDiscos, no la fecha original de publicación en la tienda ni la condición nuevo/usado.

# Referencia estadística desde el registro en SoloDiscos

Los movimientos se miden desde que SoloDiscos conoce una oferta. Un agotamiento exige una disponibilidad anterior confirmada `in_stock` y una posterior `out_of_stock`; una reposición exige el paso inverso. Encontrar por primera vez un producto sin stock no crea un agotamiento ni una novedad disponible. Las próximas extracciones omiten productos no disponibles que nunca habían estado registrados y siguen actualizando los conocidos, aunque lleguen a cero.

Las incorporaciones históricas cuya primera observación no tenía stock se conservan como estado de referencia (`initial_load=true`), sin borrar ofertas ni movimientos válidos posteriores. Las estadísticas y el CSV normales excluyen esas incorporaciones. No se reinicia el historial al día actual. «Vendidos» muestra posibles copias calculadas por el seguimiento de cantidades y las reglas de estimación descritas arriba; no se presentan como ventas confirmadas.

# Cantidades y posibles copias vendidas

Cada oferta conserva `stock_quantity`, `stock_quantity_source` y `stock_quantity_checked_at`. Las observaciones guardan la cantidad y su origen. WooCommerce usa cantidades explícitas, `low_stock_remaining` o números inequívocos del texto de disponibilidad; Shopify usa `inventory_quantity` cuando lo expone; Bsale usa `totalStock` con control de stock activo. Sin cantidad informada se usa 1 para disponible, 0 para agotado y nulo para desconocido. La administración distingue cantidades informadas, estimadas, cero confirmado y desapariciones.

Una baja entre cantidades informadas registra su diferencia como `possible_sale`; una última cantidad informada que pasa a agotado confirmado usa cero como final. Si solo había disponibilidad, pasar de 1 a 0 suma una posible copia. Los cambios entre cantidad informada y estimada, sin agotamiento, solo establecen una nueva referencia. Los aumentos y las primeras lecturas no generan posibles ventas. La referencia se inicializa con `php artisan stocks:baseline`, sin crear ventas retroactivas.

Según la regla del proyecto, una publicación anteriormente disponible que desaparece después de un recorrido completo genera tantas posibles copias vendidas como su última cantidad registrada: por ejemplo, 2 → 0 suma 2; si no informaba cantidades, 1 → 0 suma 1. Se registra stock efectivo 0 y origen `catalog_disappearance`; no se vuelve a contar mientras siga ausente, ni al recibir posteriormente la confirmación de stock 0. No se aplica a extracciones fallidas, caídas anormales o recorridos con registros omitidos por incompatibilidad. Si la ficha confirma que continúa disponible, no se estima una venta por ausencia de la colección. Las ofertas ausentes permanecen despublicadas.

El cuadro «Vendidos» suma unidades de estos eventos; el listado, los filtros y el CSV explican el stock anterior y posterior y si la baja procede de cantidades informadas, disponibilidad estimada o desaparición. Son posibles copias vendidas: incluyen hipótesis y ajustes de inventario, no confirmaciones de compra. El seguimiento nuevo no reconstruye cantidades que no se hubieran guardado históricamente.

## Políticas públicas

Las páginas `/politicas/compras`, `/politicas/transparencia`, `/politicas/privacidad`, `/politicas/seguridad` y `/politicas/tiendas` son públicas y están enlazadas en el pie de página. Las fichas y comparaciones incluyen un aviso de compra directa en cada tienda, sin pagos ni pedidos en SoloDiscos. Los textos y su fecha de revisión se mantienen en `app/config/policies.php`; no requieren migraciones ni cambios en `.env`.

Por indicación del titular se usa únicamente SoloDiscos.cl, sin inventar identidad legal, RUT, domicilio ni correo. La identificación del responsable, los canales de privacidad y correcciones y los plazos de conservación siguen pendientes: el texto no constituye una declaración de cumplimiento legal completo. Las medidas de seguridad descritas son las verificadas en la aplicación; no se anuncian certificaciones o respaldos sin verificar. Revisar los textos al incorporar nuevos proveedores, analítica, afiliación, publicidad o cambios en el modelo.

Después de actualizar el código en producción, ejecutar desde la carpeta `app`: `php artisan config:clear`, `php artisan route:clear` y `php artisan config:cache`. Esto incorpora el archivo de políticas en servidores que ya tenían la configuración cacheada; se conserva el `.env` del servidor.

## Portal para tiendas

Acceso público en `/tiendas/ingresar` y registro en `/tiendas/registro`, enlazados en el pie de página. Solo admite tiendas activas ya incorporadas al catálogo y correo del dominio exacto de su URL (sin `www`). No acepta subdominios diferentes, dominios parecidos ni correos personales. Cada persona del dominio puede registrar su propia cuenta. Los enlaces de verificación caducan en 60 minutos y requieren ingresar con la cuenta destinataria. No hay acceso al panel hasta verificar. Si se desactiva la tienda o cambia su dominio, sus cuentas dejan de acceder. No se reutiliza la tabla de administradores: el guard `store` y la tabla `store_accounts` son independientes de `web` y `users`.

El portal incluye resumen, catálogo paginado con filtros y estados, comparación de precios, estadísticas por fecha y formato, recuperación de contraseña y solicitudes de corrección. Las consultas se limitan al `store_id` de la cuenta autenticada e ignoran parámetros de tienda enviados por el cliente. Las comparaciones se realizan por álbum, formato y condición, con stock y comprobación dentro de las últimas 72 horas; usan el mínimo por tienda y reconocen empates. Las estadísticas reutilizan el registro existente y distinguen posibles copias vendidas de ventas confirmadas. La medición de consultas y clics se describe más abajo; no atribuye compras.

En `/admin/solicitudes-tiendas` el administrador revisa las solicitudes, abre la publicación para corregirla si procede y guarda una respuesta como «Resuelta» o «Rechazada». Cerrar una solicitud no modifica el catálogo: las correcciones se guardan primero desde el editor existente. Precio y stock continúan procediendo de las extracciones. La tienda ve el estado y la respuesta; no puede aprobar solicitudes ni editar publicaciones de otras tiendas.

El administrador configura el envío en `/admin/correo` (enlace «Correo SMTP»). Host, puerto, usuario, contraseña, remitente y seguridad se almacenan en `smtp_settings`, sin reemplazar `.env`. La contraseña se cifra con `APP_KEY`, nunca se devuelve en el formulario ni se conserva como dato anterior de validación. No regenerar la clave en producción. Se exige TLS: STARTTLS suele utilizar 587 y TLS directo 465, según el proveedor. Guardar, habilitar y enviar una prueba antes de invitar a tiendas; el remitente debe estar autorizado por el proveedor. Verificación y recuperación se envían directamente por SMTP, sin depender del trabajador de la cola. La aceptación del envío por SMTP no garantiza recepción; comprobar también spam, SPF y DKIM. No se realizaron envíos reales sin credenciales.

Despliegue: subir todos los archivos del cambio, conservar el `.env` y ejecutar desde `app`, en este orden:

```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan migrate --force
php artisan config:cache
```

La migración `2026_11_27_120000_create_store_portal` agrega cuatro tablas y no modifica el catálogo ni las cuentas administrativas. Hasta habilitar SMTP, el registro queda cerrado. Para comprobar aislamiento, dominios, firmas, caducidad, cifrado SMTP, recuperación, precios y solicitudes: `php artisan test --filter=StorePortalTest`.

## Interés, clics y oportunidades

Las estadísticas del administrador (`/admin/estadisticas`) y de cada tienda (`/tiendas/estadisticas`) incluyen consultas, clics, gráfico diario, productos más consultados y con más clics, poca disponibilidad y reposiciones. El administrador puede seleccionar tienda; una cuenta del portal solo consulta sus contadores. El resumen general de consultas cuenta fichas de álbum y formato, sin multiplicarlo por sus vendedores. Para una tienda se muestran consultas de fichas donde se incluyó al menos una de sus publicaciones: no son visitas al sitio del vendedor ni impresiones comprobadas en pantalla. Las consultas por tienda pueden solaparse y no se suman para calcular el total general.

La migración `2026_11_27_150000_create_engagement_statistics` agrega `engagement_state` (fecha de inicio) y `engagement_days` (contadores diarios). No importa tráfico anterior ni modifica el catálogo. Ejecutar después de subir todos los archivos: `php artisan config:clear`, `php artisan route:clear`, `php artisan view:clear`, `php artisan migrate --force` y `php artisan config:cache`. No requiere reemplazar `.env`, servicios externos, IA ni un trabajador de cola.

Las fichas públicas registran consultas; los botones públicos «Ver tienda» de fichas, comparaciones y descuentos utilizan `/ir/{offer}`. Esta ruta solo redirige a la URL almacenada del producto y al dominio de la tienda, con HTTP/HTTPS, y no acepta destinos arbitrarios en parámetros. Los accesos internos del portal y administrador conservan sus enlaces directos. Clic no significa compra ni venta atribuida.

Se excluyen bots conocidos, prefetch y sesiones de administración o tiendas. Se deduplican consultas del mismo álbum/formato durante 30 minutos y clics de la misma publicación durante 60 segundos por sesión, con un límite adicional de solicitudes. No se prometen personas únicas ni eliminación de todos los bots. Los contadores no contienen IP, agente de navegador, sesión ni identificadores de visitante. La caché utiliza hashes con clave de corta duración para deduplicación y protección. La captura falla sin interrumpir la ficha o redirección si hay un problema de almacenamiento; los paneles requieren la migración aplicada. Para probar actividad pública hay que usar una sesión sin ingreso al portal o administrador.

«Poca disponibilidad» significa presencia en una o dos tiendas activas monitoreadas, agrupando álbum/formato/condición y contando tiendas distintas, no publicaciones ni unidades estimadas. Solo usa publicaciones válidas en CLP con stock y comprobación en las últimas 72 horas. No representa el stock nacional. En el portal se limita a discos que la tienda tiene registrados. La lista muestra hasta 15 resultados.

«Oportunidades de reposición» exige un agotamiento explícito reciente en la tienda, que no conserve otra publicación disponible del mismo álbum/formato/condición, que haya stock en otra tienda y que existan consultas generales del álbum/formato o posibles copias vendidas en el periodo. Las señales generales son agregadas: no se muestran clics privados de competidores. Los filtros de fecha/formato afectan a tráfico y señales de demanda; la disponibilidad es una fotografía actual. El filtro de movimiento sigue aplicándose al listado de movimientos y al CSV existente. Los paneles muestran hasta 10 discos consultados, 15 productos con clics y 15 oportunidades, con sus cifras de periodo.

## Descuentos desde el primer precio registrado

El menú público enlaza `/descuentos`, con filtros por tienda, formato, condición, artista/álbum y orden por porcentaje, precio o última comprobación. Se muestran todas las publicaciones que cumplen las reglas, paginadas de 12 en 12. Cada publicación se compara con su propio primer precio positivo identificado en CLP: `(precio inicial - precio actual) / precio inicial × 100`. La fecha de referencia se muestra. Los registros antiguos sin moneda identificada y los de otras monedas no se utilizan ni convierten.

Solo aparecen publicaciones válidas de tiendas activas, con stock, comprobadas en las últimas 72 horas y con precio actual inferior al inicial. Cuando el precio vuelve al inicial o lo supera, dejan de aparecer. Una subida intermedia que todavía deje el precio por debajo del inicial conserva la bajada respecto al inicio. Esta comparación no usa precios tachados del vendedor ni demuestra una promoción oficial. La regla existente de bajadas verificadas con referencia de 30 días conserva su cálculo independiente.

Las tarjetas muestran portada, vendedor, porcentaje, importe, primer precio y gráfico. `/descuentos/{offer}` ofrece el historial completo paginado de 50 registros válidos por página. El gráfico comprime repeticiones y resume hasta 200 puntos conservando extremos; la tabla mantiene los registros originales. La línea une observaciones, no prueba el precio entre comprobaciones. Las fichas muestran el porcentaje por tienda y el catálogo destaca la bajada de su oferta más barata en CLP cuando corresponde.

La migración `2026_11_27_170000_index_offer_price_history` agrega únicamente un índice a `offer_observations`; no crea tablas ni modifica precios o historial. Tras subir los archivos, ejecutar desde `app`: `php artisan config:clear`, `php artisan route:clear`, `php artisan view:clear`, `php artisan migrate --force`, `php artisan config:cache`. Conservar el `.env` del servidor. Validación: `php artisan test --filter=DiscountsTest`.

### Portadas y detalle de clics en estadísticas

Los listados de interés, clics, poca disponibilidad y reposiciones incorporan miniaturas. Al pulsarlas se abre un modal con portada y clics mensuales de los últimos seis meses calendario, incluido el actual. El detalle mantiene el formato y el ámbito de tienda/publicación de la fila; no hereda las fechas del listado. Los meses anteriores al inicio de la medición se identifican como «Sin medición». La información se consulta al abrir el modal, sin precargar cientos de series.

Los endpoints `/admin/estadisticas/discos/{album}/{format}` y `/tiendas/estadisticas/discos/{album}/{format}` requieren sus respectivas sesiones. El portal fuerza la tienda de la cuenta y rechaza publicaciones ajenas aunque se alteren los parámetros. Las respuestas no se cachean. No hay nuevas tablas ni migraciones. Los movimientos de stock/precio, cuadros de movimientos, resumen por tienda y CSV permanecen exclusivamente en el administrador; el portal de tiendas muestra interés y oportunidades.

### Paginación de revisión de portadas

La revisión admite 100, 200, 300, 400 o 500 propuestas por página, con 100 por defecto. La aprobación individual se realiza con el botón, sin casilla adicional. La aprobación de página admite hasta 500 propuestas del token cifrado emitido para esa página y conserva la cantidad seleccionada al regresar. Siguen vigentes las comprobaciones de artista, formato, datos actuales, correcciones manuales y pertenencia a la página; no se aprueban propuestas mediante solicitudes GET. Se cargan solo los campos necesarios de las ofertas para limitar memoria con páginas grandes.

## SEO técnico y estrategia de posicionamiento

La primera etapa incorpora títulos y descripciones por página, enlaces canónicos en fichas y listados principales, Open Graph/Twitter y JSON-LD de Organization, WebSite, MusicAlbum y BreadcrumbList. No publica valoraciones ficticias, estrellas de reseñas ni ofertas de una edición concreta cuando las tiendas se agrupan por álbum/formato. El marcado de álbum describe el contenido, sin prometer resultados enriquecidos de productos.

Las búsquedas internas, filtros combinados, ordenaciones y tamaños de página alternativos llevan `noindex, follow`. Las fichas con solo parámetros de retorno/campaña conservan su canónico limpio. Las páginas consecutivas del catálogo principal mantienen canónicos propios; no apuntan todas a la primera. Las zonas privadas y redirecciones externas incorporan `X-Robots-Tag: noindex, nofollow`; la autenticación sigue siendo el control de acceso. `robots.txt` restringe el rastreo de administración, portal y redirecciones y anuncia el sitemap público.

`/sitemap.xml` enlaza `/sitemap-paginas.xml` y fragmentos `/sitemap-discos-{numero}.xml`, de hasta 1.000 álbumes/formato publicados por fragmento. Excluye agotados, ausentes, excluidos, tiendas inactivas y publicaciones sin vigencia; agrupa varias ofertas del mismo álbum/formato. Se utiliza el catálogo real, sin cargar todos los modelos en memoria, ni inventar fechas de modificación. Las páginas principales sin catálogo disponible no se anuncian en el sitemap. El conteo de fragmentos se guarda por cinco minutos.

Los GET públicos en producción redirigen permanentemente al host y HTTPS de `APP_URL`. El comportamiento 307 de los formularios HTTP se conserva. Verificar que el `.env` de producción tenga `APP_URL=https://www.solodiscos.cl`; este cambio no reemplaza `.env`. Después de subir todos los archivos: `php artisan config:clear`, `php artisan route:clear`, `php artisan view:clear`, `php artisan config:cache`. No requiere migración. Verificar los XML en producción antes de enviarlos a buscadores.

Orden de trabajo recomendado: (1) verificar una propiedad de dominio en Google Search Console mediante el TXT que entregue Google y configurar Bing Webmaster Tools, (2) enviar el sitemap, revisar cobertura/canónico de una ficha CD y otra vinilo y seguir clics, impresiones y CTR orgánicos por URL/consulta, (3) depurar agrupaciones, títulos, formatos, condiciones e imágenes que dañen la comparación, (4) construir páginas útiles por artista a partir de identidades fiables y añadir enlaces desde álbumes y catálogo, (5) publicar guías propias basadas en preguntas reales y comparaciones, (6) conseguir menciones y enlaces editoriales de tiendas y comunidades, (7) medir rendimiento móvil y mejorar imágenes/consultas según mediciones, no por una puntuación aislada.

Priorizar consultas con intención concreta: artista + álbum + CD/vinilo + precio/Chile, después categorías y descuentos. El valor diferencial es la comparación real entre vendedores, disponibilidad e historial de precios, no copiar descripciones ni generar páginas repetitivas. Las métricas orgánicas de Search Console son distintas de los clics hacia vendedores registrados por SoloDiscos. No se instaló un rastreador publicitario ni se enviaron solicitudes de verificación sin acceso a las cuentas. Indexación y posiciones dependen del buscador y no están garantizadas.

### Acceso completo de tiendas asociadas

En la edición administrativa de cada tienda, «Acuerdo comercial activo» permite habilitar o retirar la condición comercial. La migración `2026_11_27_200000_add_store_partner_access` agrega el estado y los datos de última activación (fecha y administrador), además de la conexión verificada de la integración. Todas las tiendas comienzan con el acuerdo desactivado. Una edición que omita este campo conserva su estado; el formulario incluye el valor 0 para permitir desactivarlo.

El portal muestra el catálogo completo de oportunidades, paginado de 25 en 25, solo cuando el acuerdo está activo y existe una conexión API exitosa con la clave vigente y el dominio actual. Crear la clave por sí solo no basta. Una respuesta API correcta registra la conexión; una petición inválida no la valida. Revocar o rotar la clave bloquea inmediatamente el listado completo hasta reconectar. Las integraciones anteriores con una sincronización registrada se reconocen al migrar. «Conectado» significa que la API verificó la clave vigente, no una comprobación continua de que el plugin siga instalado.

Sin ambas condiciones se mantiene la muestra de hasta cinco resultados por filtros, estable durante el día y sin paginación adicional. Los parámetros del navegador no pueden activar un acuerdo. Solo las rutas del administrador permiten cambiarlo. El ranking público conserva sus reglas independientes; este estado no activa facturación ni cobros automáticos.

Despliegue desde `app`, conservando `.env`: `php artisan config:clear`, `php artisan migrate --force`, `php artisan route:clear`, `php artisan view:clear`, `php artisan config:cache`. La migración conserva catálogo, pedidos y claves existentes. Comprobar las combinaciones de acceso y la revocación con `php artisan test --filter=StorePartnerAccessTest`.

## Plan asociado, formalización y finalidad limitada

El portal incorpora Plan y acuerdo en /tiendas/plan. La configuración versionada está en app/config/partner-plan.php: comisión del 4% sobre productos pagados tras descuentos y devoluciones, sin envío y con IVA de productos incluido; titular informado Articulos Musicales Juan Fica Sepulveda EIRL, RUT 76.879.846-K. Representante, domicilio, contacto, condiciones tributarias de la comisión y formalidades de la notaría están pendientes de completar antes de firmar. El contenido se identifica como borrador. Solicitar formalización solo guarda una solicitud interna y su versión; no firma, no activa el acuerdo y no genera cobros.

El administrador ve la solicitud en Tiendas. Para activar un acuerdo debe informar referencia del documento y fecha de firma. Se registra la versión del texto y SHA-256 del ZIP revisado; una edición sin cambios en la referencia/fecha conserva ese registro. La huella identifica un paquete, no certifica seguridad ni verifica una firma. La descarga incluye los fuentes para revisión. Terminar desde el portal desactiva el acuerdo y revoca la clave en una transacción; desde administración se puede desactivar igualmente. No hay permanencia ni penalidad configuradas. No existe proveedor notarial ni firma electrónica implementada en la web: la formalización debe gestionarse entre las partes ante la notaría correspondiente.

El plugin 1.2.0 solo exporta pedidos con atribución nativa exacta a SoloDiscos. Consulta localmente metadatos para decidir; marca con _solodiscos_exported los que envió. Si retiran su atribución, solo transmite ID y fecha a orders/withdraw. Las eliminaciones en cola conservan el aviso mínimo aunque el pedido ya no exista. Pedidos desconocidos/no atribuidos nuevos se ignoran en el receptor; actualizaciones antiguas de un pedido ya atribuido siguen permitiendo corregir su estado o devolución sin inventar una base de comisión. Los reportes generales también se limitan a atribuidos. No se borran automáticamente registros de versiones anteriores.

Los campos products_total, products_refunded y commission_base describen productos con impuestos, después de descuentos, sin envío ni recargos. La base resta devoluciones de productos; si no hay desglose suficiente queda nula, pendiente de revisión. Una devolución total deja base cero. La API valida la coherencia de la base y el listado del plugin permite consultarla. No es facturación automática ni prueba independiente del pago. Los reportes conservan su total neto del pedido como una magnitud distinta de la base de productos.

Despliegue: subir todos los archivos y el ZIP, conservar .env y ejecutar desde app: php artisan config:clear, php artisan migrate --force, php artisan route:clear, php artisan view:clear, php artisan config:cache. La migración 2026_11_27_210000_add_partner_plan_requests agrega datos de solicitud/firma/término y las bases de productos, sin borrar pedidos. Actualizar también el plugin instalado en WordPress con el nuevo ZIP. Validación: StorePartnerAccessTest, StoreIntegrationTest y los scripts plugin-smoke.php/plugin-render.php.
