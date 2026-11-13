=== DruID Pulse for WordPress ===
Requires at least: 6.9
Requires PHP: 8.3
Stable tag: 0.10.1

Páginas vistas y clics de usuarios autenticados de WordPress enviados a DruID Pulse.

== Descripción ==

Versión de desarrollo 0.10.1.

Selecciona las páginas, enlaces y botones que quieres registrar desde el panel
DruID Pulse. Los eventos se envían en segundo plano. No se registran visitantes
anónimos y las selecciones de vistas y clics empiezan vacías.

Guía completa de instalación, configuración y comprobación para integradores:
https://github.com/dru-id/wp-druid-pulse#readme

Requiere WordPress 6.9 y PHP 8.3 o posteriores, una cuenta con permiso para
administrar ajustes y credenciales OAuth2 autorizadas para enviar a Pulse.
No requiere wp-druid, WooCommerce, Composer ni npm. Incluye Action Scheduler
con sus licencias. WP-Cron o un ejecutor de Action Scheduler deben funcionar
para entregar eventos y limpiar los caducados. Multisite no está soportado.

== Instalación y conexión ==

1. Subir el ZIP instalable desde Plugins > Añadir nuevo plugin > Subir plugin.
2. Instalar y activar DruID Pulse for WordPress.
3. Abrir DruID Pulse > Connection e introducir las URLs HTTPS base de Pulse y
   del servidor de autorización, el cliente OAuth y su secreto.
4. Elegir el método de autenticación habilitado para ese cliente. Rellenar
   scopes y tenant solo si la conexión los requiere.
5. Pulsar Save connection y después Test OAuth authentication.
6. Configurar Page views y Clicks, y comprobar la integración como se indica abajo.

La URL de Pulse se introduce sin /identities; la de autorización, sin
/oauth2/token. La prueba OAuth utiliza los ajustes guardados: solicita un token,
pero no envía eventos ni acredita que Pulse vaya a aceptarlos.

El secreto guardado no vuelve a mostrarse. Dejar el campo vacío lo conserva;
escribir otro lo sustituye. Para eliminarlo, marcar Remove saved secret y dejar
el campo vacío. Site shown in Pulse muestra el nombre y la dirección del sitio,
que se toman de Ajustes > Generales.

== Páginas vistas ==

En DruID Pulse > Page views, buscar por título, marcar los contenidos y pulsar
Save selection. El catálogo muestra 20 contenidos por página. Guardar antes
de cambiar de página o filtros: solo se modifican las filas visibles y se
conservan las selecciones ya guardadas en otras páginas.

Se incluyen páginas, entradas, contenidos publicados de tipos públicos, la
portada y la página de entradas. No se incluyen categorías, etiquetas,
búsquedas, otros archivos ni adjuntos. Los contenidos nuevos y descendientes
no se seleccionan automáticamente. Las selecciones sobreviven a cambios de
título o URL; Unavailable selections permite retirar las que ya no están disponibles.

Una página seleccionada genera una vista al estar preparada y visible para
un usuario con sesión iniciada. Recargar o volver mediante Atrás/Adelante
cuenta otra vista; cambiar de pestaña y regresar no.

== Clics ==

Para registrar todos los enlaces y botones de una página:

1. Abrir DruID Pulse > Clicks y pulsar Add rule.
2. Mantener Track y All links and buttons.
3. Elegir Selected pages > Choose pages, buscar por título y marcar las páginas.
4. Confirmar con Use selected pages y pulsar Save click rules.

No hace falta conocer IDs ni añadir clases. Entire website aplica la regla a
todo el frontend compatible. También se puede elegir All links, All buttons
o CSS selector. Por ejemplo, #main-menu a[href] selecciona enlaces de ese menú.
El selector debe coincidir con el enlace o botón, no solo con su contenedor.
Filter by class prefix permite limitar la regla a clases que empiecen por un texto.

Exclude omite elementos y prevalece sobre Track. Varias reglas coincidentes
no duplican una activación. Vistas y clics se configuran de forma independiente.

El editor muestra 20 reglas por página y conserva los cambios al paginar;
Save click rules guarda todas las reglas. El selector de páginas también
pagina y conserva las marcas entre búsquedas.

== Usuarios y comprobación ==

Se necesita una sesión WordPress, con cualquier rol, también administrador en
el frontend. Con wp-druid, el usuario debe estar asociado a una identidad DruID.
Sin wp-druid, necesita un correo real y válido en su perfil WordPress; se envían
también nombre y apellidos si están disponibles. Sin identificación no se envía.

Para comprobarlo, usar un usuario y contenidos de prueba del entorno configurado:

1. Guardar la conexión y probar la autenticación OAuth.
2. Seleccionar una página para vistas y una regla de clics para esa página.
3. Iniciar sesión y abrir la página publicada, fuera del editor o previsualización.
4. Activar un enlace o botón incluido en la regla.
5. Revisar DruID Pulse > Diagnostics y los posibles errores.
6. Comprobar en Pulse la vista y el clic del usuario, asociados al sitio correcto.

Esta prueba sí envía eventos. Los eventos pueden entregarse antes de abrir
Diagnostics: una cola vacía no demuestra por sí sola captura ni entrega.

== Diagnóstico y mantenimiento ==

Diagnostics muestra configuración, estado de entrega y eventos retenidos.
Permite filtrar por estado y tipo y muestra 25 registros por página, sin
perfiles ni contenido de los eventos. No es un histórico de enviados.

Process pending programa trabajo en segundo plano; respeta las pausas y
las fechas de reintento. Si Ready to send no avanza, comprobar el ejecutor de
tareas en Technical details and scheduled tasks. Una tarea completada no
acredita entrega: puede haber programado un reintento.

Waiting to retry indica una espera. Revisar el error y la fecha del próximo
intento. Guardar la conexión despeja la pausa común, pero respeta las esperas
de cada evento. Tras corregir la causa, Retry selected failed events permite
reintentar fallidos vigentes sin reiniciar su caducidad. Los conflictos no
se pueden reintentar manualmente. Clean expired retira caducados y registros
ya entregados pendientes de borrado, conservando pendientes vigentes.

== Datos y conservación ==

Los eventos caducan a los siete días desde su recepción local, también durante
fallos o desactivación. Los confirmados por Pulse se eliminan de la cola.
La cola admite 10.000 eventos o 100 MiB de contexto; al llenarse rechaza nuevas
entradas sin expulsar las anteriores. Si cambias la conexión, los pendientes
se enviarán al nuevo destino. El perfil del usuario se consulta al enviar.

No se capturan valores de formularios ni el teléfono del perfil. Las etiquetas
y destinos seleccionados pueden contener información personal. Las páginas
autenticadas deben quedar fuera de cachés compartidas; el plugin no incorpora
un gestor de consentimiento.

== Actualizar, desactivar o eliminar ==

Las actualizaciones compatibles conservan configuración y eventos. Este plugin
no tiene un servicio propio de actualizaciones automáticas ni se actualiza desde
WordPress.org. Para actualizar, instalar el nuevo paquete mediante WordPress.

Desactivar conserva ajustes, credenciales y eventos y cancela las tareas propias
pendientes; una ejecución iniciada puede terminar. Reactivar recupera las tareas
sin reiniciar la caducidad. Eliminar desde WordPress borra los datos y tareas
propios, conservando los recursos compartidos y las tareas de otros plugins.
Reinstalar después empieza con configuración vacía. Borrar archivos manualmente
no ejecuta la limpieza. Antes de activar una copia independiente del sitio,
seguir el procedimiento de clonación enlazado desde la guía del repositorio.

== Compatibilidad ==

La captura requiere JavaScript y no registra administración, previsualizaciones
ni contenido cuya contraseña nativa siga pendiente. No detecta navegación
SPA/AJAX ni interacciones dentro de iframes. El cierre del navegador y los errores
de conexión pueden provocar pérdidas; los reintentos pueden provocar duplicados.

== Registro de cambios ==

= 0.10.1 =

* Selector de páginas por título para clics, sin introducir IDs de WordPress.
* Opciones para todos los enlaces/botones y editor paginado a 20 reglas.
* Guardado completo entre páginas y conservación de referencias existentes.
* Pantallas y ayudas simplificadas; identificadores técnicos bajo Diagnostics.
* Sin cambios en el SDK, el formato de eventos ni el esquema persistido.

= 0.10.0 =

* Pantalla Diagnostics con estado de instalación, configuración, tareas y cola.
* Listado paginado y filtrable sin datos personales, contexto ni respuestas remotas.
* Programación manual de entrega, limpieza y reintento protegido de fallidos vigentes.
* Diagnóstico de solo lectura ante inicialización incompatible, sin cambiar el esquema 6.

= 0.9.0 =

* Entrega automática extensible por POST /identities con OAuth2.
* Reintentos espaciados, pausa común, conflictos y recuperación tras interrupción.
* UUID estable como externalId, sin promesa de procesamiento exactamente una vez.
* Esquema 6 con migración conservadora y retirada de todas las tareas propias.
* Aviso de conexión y pruebas de trabajadores concurrentes e instalación aislada.

= 0.8.0 =

* SDK 0.2.0 con filtros de páginas y reglas declarativas de clics.
* Captura de ratón, teclado y toque sin duplicados ni bloqueo de navegación.
* Pantalla Clicks con inclusiones/exclusiones y ámbito de contenido por ID.
* Receptor y mapeador de clics con origen, etiqueta y destinos tel/mailto.
* Esquema 5: opción de reglas vacía, preservación al desactivar y retirada exacta.
* La entrega automática a Pulse continúa pendiente.

= 0.7.0 =

* SDK JavaScript genérico con contexto y consumidor configurables, sin dependencias.
* Vistas al estar preparado y visible; recarga e historial cuentan de nuevo.
* Adaptador WordPress y recepción de referencias con título/URL fijados en servidor.
* Revalidación de selección y acceso; eventos aceptados conservan su contexto.
* Esquema 4 conservado; clics y entrega automática a Pulse aún pendientes.

= 0.6.0 =

* Receptor común con sesión WordPress, nonce y atribución en servidor.
* Tipos ampliables con mapeador y validador; límite HTTP de 16 KiB.
* Aceptación local confirmada después de guardar; errores explícitos.
* Transporte asíncrono con keepalive y pausa al invalidarse la sesión.
* Sin captura automática de vistas/clics ni entrega automática a Pulse.

= 0.5.0 =

* Almacén compacto y versionado, ampliable a otros tipos de evento.
* Preparación con usuario y conexión actuales y contexto histórico conservado.
* Caducidad de 7 días y límites de capacidad protegidos bajo concurrencia.
* Action Scheduler 4.2.0 incluido para mantenimiento, sin entrega automática.
* Esquema 4, actualización, conservación y limpieza de recursos propios.

= 0.4.0 =

* Conexión OAuth2 y prueba de autenticación protegida, sin emisión de eventos.
* UUID persistente y origen obtenido de WordPress.
* Resolución del consumidor y mapeadores ampliables para vistas y clics.
* Cliente individual de Identity y caché de tokens ligada a la configuración.
* Esquema 3 y limpieza de las opciones y el transient propios.

= 0.3.0 =

* Catálogo de vistas con búsqueda, filtros, paginación y selección por ID.
* Portada, página de entradas y referencias no disponibles diferenciadas.
* Guardado protegido, aviso de cambios sin guardar y conservación entre páginas.
* Actualización del esquema y desinstalación de la configuración propia.

= 0.2.0 =

* Inicialización idempotente y comprobación de esquema al activar y actualizar.
* Conservación al desactivar y limpieza de datos propios al desinstalar.
* Fallos explícitos, aviso administrativo y reintento en cargas posteriores.

= 0.1.0 =

* Base instalable independiente y estado informativo en la lista de plugins.
