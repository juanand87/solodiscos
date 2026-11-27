<?php

return [
    'updated_at' => '27 de noviembre de 2026',
    'pages' => [
        'compras' => [
            'title' => 'Cómo funciona SoloDiscos y las compras',
            'label' => 'Cómo funcionan las compras',
            'intro' => 'Comparas en SoloDiscos.cl y compras directamente en el sitio de cada tienda.',
            'sections' => [
                ['title' => 'Nuestro papel', 'paragraphs' => [
                    'SoloDiscos.cl es un buscador y comparador de publicaciones de CDs y vinilos de tiendas independientes. No vendemos los discos, no recibimos pagos, no procesamos pedidos y no reservamos existencias.',
                    'Al seleccionar «Ver tienda» o «Ver en tienda», accederás al sitio del vendedor. La compra se realiza allí, con los medios de pago y las condiciones que informe esa tienda.',
                ]],
                ['title' => 'Antes de comprar', 'items' => [
                    'Confirma en la tienda el precio final, la disponibilidad y el costo de envío o retiro. Los precios del comparador se expresan en pesos chilenos (CLP); el envío no está incluido en la comparación.',
                    'Revisa la edición, el prensado, el color, el número de discos y los contenidos adicionales. Una misma agrupación puede reunir ediciones diferentes del mismo álbum y formato.',
                    'Comprueba si el producto es nuevo o usado y, especialmente en los usados, el estado del disco y de la carátula. «Sin especificar» significa que no hemos podido determinar la condición.',
                    'Lee la descripción del vendedor. Las imágenes son referenciales y pueden corresponder a otra presentación del álbum.',
                ]],
                ['title' => 'Precios y disponibilidad', 'paragraphs' => [
                    'Consultamos los catálogos de forma periódica; la información no se actualiza en tiempo real. Un producto puede cambiar de precio o agotarse entre la última extracción y tu visita. Mostrarlo en SoloDiscos no confirma ni garantiza una reserva o una venta.',
                    'Si encuentras una diferencia, verifica la información con el vendedor antes de pagar. Una discrepancia en SoloDiscos no confirma por sí sola las condiciones de una compra en la tienda.',
                ]],
                ['title' => 'Pedidos, envíos y posventa', 'paragraphs' => [
                    'El vendedor gestiona el pago, el comprobante de compra, la entrega, los cambios, las devoluciones y las garantías, conforme a la normativa aplicable. Las consultas sobre un pedido deben dirigirse a la tienda en la que lo realizaste.',
                    'SoloDiscos no dispone de los datos de tu pedido ni puede cancelarlo o devolver el pago. Esto no limita los derechos que te reconoce la ley ni excluye las obligaciones que correspondan a SoloDiscos por su propio servicio de información.',
                ]],
            ],
        ],
        'transparencia' => [
            'title' => 'Transparencia y metodología',
            'label' => 'Transparencia y rankings',
            'intro' => 'Explicamos qué comparamos, cómo ordenamos las tiendas y cuáles son los límites de los resultados.',
            'sections' => [
                ['title' => 'Qué incluye el catálogo', 'paragraphs' => [
                    'Mostramos publicaciones de las tiendas incorporadas al comparador. La cobertura no representa necesariamente a todas las tiendas ni a todos los discos del mercado. La incorporación de una tienda no certifica su calidad de servicio ni implica una alianza comercial.',
                    'La identificación de artista, álbum, formato y condición utiliza reglas automáticas y puede recibir correcciones manuales. La comparación de portadas ayuda a proponer agrupaciones; una portada parecida no prueba que dos ediciones sean idénticas.',
                ]],
                ['title' => 'Cómo calculamos el Top 10', 'items' => [
                    'Comparamos el mismo álbum, formato y condición en al menos dos tiendas. Nuevos y usados se comparan por separado; los productos de condición desconocida quedan fuera del ranking de precios.',
                    'Para cada producto tomamos la oferta más económica de cada tienda. Dividimos su precio por la mediana de los precios de las otras tiendas y calculamos la mediana de esas relaciones para ordenar las tiendas.',
                    'Cada tienda necesita al menos 100 productos comparables para recibir posición. Usamos todos los comparables elegibles, no una selección fija de 100. La muestra puede ser diferente para cada tienda y cambia con los filtros.',
                    'Solo participan publicaciones de tiendas activas, con álbum, formato y precio válidos, disponibles y comprobadas en las últimas 72 horas. Una condición o identidad sin resolver puede reducir la muestra.',
                ]],
                ['title' => 'Qué significan las estrellas', 'paragraphs' => [
                    'Las estrellas representan la posición dentro del Top 10: el primer puesto recibe 5 y el décimo 3, con una progresión gradual. Si participan menos de diez tiendas, mantenemos la escala por posición. No son valoraciones de clientes, notas de seguridad ni una evaluación de la atención o el despacho.',
                    'El porcentaje de precio relativo muestra la diferencia del índice respecto de la referencia. Una mejor posición no significa que esa tienda tenga el menor precio para todos los productos ni para tu compra concreta.',
                ]],
                ['title' => 'Precios mínimos, empates y diferencias', 'paragraphs' => [
                    '«Productos más baratos que otras tiendas» cuenta los productos comparables en los que una tienda comparte o tiene el precio mínimo. Incluye empates; no significa que sea siempre más barata que todas las demás.',
                    'Las tarjetas de comparación muestran hasta tres tiendas distintas, ordenadas por precio, y reconocen los empates en el mejor precio. Si hay más alternativas, puedes verlas en «Ver todas las tiendas». Un empate con más de tres tiendas puede continuar en ese listado completo.',
                    '«Ahorra hasta» expresa la diferencia entre el menor y el mayor precio de las alternativas mostradas. No es un descuento histórico ni garantiza que las ediciones sean idénticas. El envío no forma parte del cálculo.',
                    'Las bajadas de precio históricas se calculan con las observaciones que registra SoloDiscos de una publicación de la misma tienda. Una diferencia entre tiendas y una bajada histórica son medidas distintas.',
                ]],
                ['title' => 'Actualización e independencia', 'paragraphs' => [
                    'El ranking se recalcula con los datos registrados y puede utilizar resultados guardados durante hasta cinco minutos. Recalcularlo no equivale a consultar nuevamente todos los sitios. La frecuencia y el éxito de cada extracción condicionan la actualidad de la información.',
                    'El orden del Top 10 se determina por el cálculo de precios descrito. Una aparición en el catálogo o un logo no prueba que la tienda haya pagado por estar allí. Cualquier publicidad o enlace de afiliación que se incorpore deberá identificarse y distinguirse del orden calculado.',
                    'Las bajas de stock son indicios de posibles copias vendidas, no ventas confirmadas: también pueden deberse a ajustes, retiros o cambios del catálogo. SoloDiscos no conoce las transacciones de cada vendedor.',
                ]],
            ],
        ],
        'privacidad' => [
            'title' => 'Privacidad y uso de cookies',
            'label' => 'Privacidad y cookies',
            'intro' => 'Esta página describe el uso de información al navegar por SoloDiscos.cl. No solicitamos datos de pago para comprar discos.',
            'sections' => [
                ['title' => 'Información utilizada por el sitio', 'paragraphs' => [
                    'Puedes consultar el catálogo sin crear una cuenta de comprador. Las cuentas de acceso existentes corresponden a la administración del servicio.',
                    'Para entregar las páginas y proteger el servicio, el alojamiento y la aplicación pueden registrar información técnica, como dirección IP, fecha, URL solicitada, navegador y errores. Los términos de búsqueda pueden formar parte de las URL; evita introducir datos personales o sensibles en el buscador.',
                    'Estos datos técnicos se utilizan para el funcionamiento del sitio, la autenticación administrativa y la detección y resolución de errores o abusos. Los proveedores de alojamiento intervienen en la infraestructura y sus registros técnicos.',
                ]],
                ['title' => 'Cookies y almacenamiento en el navegador', 'items' => [
                    'Cookie de sesión: permite mantener la sesión y los mensajes de navegación; también se utiliza al acceder a la administración. Su duración depende de la configuración del servicio.',
                    'Cookie XSRF-TOKEN: participa en la protección de formularios frente a solicitudes no autorizadas.',
                    'Almacenamiento de sesión del navegador: recuerda la página del catálogo y la posición de desplazamiento para que puedas volver a los discos que estabas revisando. No registra una compra.',
                    'Puedes borrar o bloquear estos elementos desde el navegador. Esto puede afectar el acceso administrativo, los formularios o la recuperación de la navegación.',
                ]],
                ['title' => 'Imágenes y sitios externos', 'paragraphs' => [
                    'Algunas portadas y logos se cargan desde servidores de las tiendas o sus proveedores. Al solicitar una imagen, tu navegador puede comunicarles la dirección IP y otros datos técnicos necesarios para entregarla, incluso sin pulsar «Ver tienda».',
                    'Cuando visitas una tienda externa, esa tienda aplica su propia política de privacidad y sus cookies. SoloDiscos no recibe por ese hecho los datos de tu pedido, tu tarjeta ni una confirmación de compra.',
                ]],
                ['title' => 'Analítica, conservación y derechos', 'paragraphs' => [
                    'La versión actual de la aplicación no incorpora herramientas propias de publicidad comportamental ni seguimiento de compras. Si añadimos analítica, afiliación o nuevas finalidades, esta información deberá actualizarse y se habilitarán los controles que correspondan.',
                    'El almacenamiento de sesión del navegador es temporal. Los plazos de los registros del alojamiento y de la aplicación dependen de su configuración; esta página no fija un plazo que no haya sido verificado. El historial de precios y stock describe publicaciones de tiendas y no acredita la identidad de compradores.',
                    'El acceso, la rectificación, la eliminación y los demás derechos sobre datos personales se rigen por la normativa aplicable. La identificación legal del responsable, el canal específico para ejercer estos derechos y los plazos de conservación están pendientes de incorporación; esta página no los sustituye.',
                ]],
            ],
        ],
        'seguridad' => [
            'title' => 'Seguridad de la información',
            'label' => 'Seguridad de la información',
            'intro' => 'Describimos las medidas del servicio sin prometer seguridad absoluta ni certificaciones que no se hayan acreditado.',
            'sections' => [
                ['title' => 'Protección del acceso', 'paragraphs' => [
                    'SoloDiscos.cl utiliza HTTPS para proteger la comunicación con el sitio. La administración requiere autenticación y sus formularios incorporan protección contra solicitudes entre sitios. El acceso de inicio de sesión dispone de límites de intentos.',
                    'Estas medidas reducen riesgos, pero no eliminan todos los posibles incidentes. Esta página no afirma que existan auditorías externas, certificaciones de seguridad o una frecuencia de respaldos que no se haya verificado.',
                ]],
                ['title' => 'Pagos y enlaces a tiendas', 'paragraphs' => [
                    'SoloDiscos no solicita ni almacena números de tarjetas, claves bancarias o credenciales de pago para comprar discos. El pago se realiza en el sitio del vendedor.',
                    'Comprueba el dominio y las condiciones del vendedor antes de pagar. Aparecer en el comparador no constituye una certificación de seguridad de los sistemas de una tienda externa.',
                ]],
                ['title' => 'Uso responsable y comunicación de incidentes', 'paragraphs' => [
                    'No intentes acceder a cuentas ajenas, extraer credenciales, alterar datos ni interrumpir el servicio. Si detectas un problema, conserva solo la información mínima necesaria y evita difundir datos personales o detalles que faciliten un abuso.',
                    'El canal específico para comunicar vulnerabilidades está pendiente de incorporación. Nunca envíes contraseñas ni información de pago para demostrar un problema.',
                    'Las incidencias deberán evaluarse según su alcance para corregirlas y, cuando corresponda, comunicar lo exigido por la normativa aplicable. No anunciamos tiempos de respuesta que todavía no estén establecidos.',
                ]],
            ],
        ],
        'tiendas' => [
            'title' => 'Tiendas, contenidos y correcciones',
            'label' => 'Información para tiendas',
            'intro' => 'La información del comparador procede de publicaciones de las tiendas y puede requerir correcciones.',
            'sections' => [
                ['title' => 'Origen y atribución de la información', 'paragraphs' => [
                    'SoloDiscos consulta catálogos públicos para mostrar títulos, precios, formato, condición, imágenes y descripciones, junto con la tienda y el enlace de origen. La disponibilidad depende de lo que cada sitio permite identificar y del resultado de la última consulta.',
                    'Los nombres, logos, portadas y descripciones pertenecen a sus respectivos titulares. Su aparición identifica la fuente o el producto; no implica que exista una autorización, patrocinio o acuerdo comercial con SoloDiscos.',
                ]],
                ['title' => 'Qué puede necesitar una revisión', 'items' => [
                    'Un precio, disponibilidad, enlace, nombre o logo incorrecto.',
                    'La agrupación de álbumes distintos o la clasificación de CD y vinilo.',
                    'Una condición de nuevo o usado que no corresponda a la publicación.',
                    'Información sobre incorporación o retiro de una tienda y asuntos relacionados con los derechos sobre los contenidos.',
                ]],
                ['title' => 'Información útil para una corrección', 'paragraphs' => [
                    'Para revisar una discrepancia se necesita el enlace de SoloDiscos, el enlace de origen, el dato incorrecto y la información que permita contrastarlo. No es necesario aportar datos de compradores, números de tarjeta o claves de acceso.',
                    'El canal público para estas solicitudes está pendiente de incorporación. No ofrecemos todavía un formulario de recepción ni un plazo de respuesta. Los cambios del catálogo se reflejan cuando la publicación vuelve a consultarse o se revisa su clasificación.',
                ]],
                ['title' => 'Alcance de una recomendación', 'paragraphs' => [
                    'La posición en el ranking depende de los productos comparables y de los filtros, no del tamaño total del catálogo. Una muestra insuficiente no indica mala calidad ni falta de confianza en una tienda.',
                    'Las correcciones no deben confundirse con la contratación de publicidad. Los espacios promocionales que se incorporen deberán identificarse por separado de los resultados calculados.',
                ]],
            ],
        ],
    ],
];
