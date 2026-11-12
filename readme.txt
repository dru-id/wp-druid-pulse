=== DruID Pulse for WordPress ===
Requires at least: 6.9
Requires PHP: 8.3
Stable tag: 0.10.1

Base instalable del plugin independiente de actividad autenticada para DruID Pulse.

== Descripción ==

Versión de desarrollo 0.10.1.

Permite seleccionar y guardar contenidos para páginas vistas desde el menú
DruID Pulse > Page views y configurar OAuth2 desde DruID Pulse > Connection.
Incluye almacén de eventos, mantenimiento mediante Action Scheduler, receptor
autenticado y un SDK JavaScript independiente con adaptador WordPress para vistas
y clics seleccionados, con entrega automática a Pulse en segundo plano.
La prueba de conexión solicita un token; no envía eventos ni crea identidades.

No requiere wp-druid ni WooCommerce. Guarda esquema, selección, UUID y conexión
mediante Options API, y un token temporal mediante Transients API. Usa la tabla
<prefijo>druid_pulse_events y distribuye Action Scheduler 4.2.0 con sus licencias.
Los eventos individuales usan POST /identities con OAuth2 y externalId estable.

Requiere WordPress 6.9 y PHP 8.3 o posteriores.

== Instalación ==

1. Utilizar una instalación de WordPress de desarrollo compatible.
2. Subir el ZIP desde Plugins > Añadir nuevo plugin > Subir plugin.
3. Instalar y activar DruID Pulse for WordPress.
4. Comprobar el estado del plugin y que el frontend siga funcionando.
5. Abrir DruID Pulse > Page views y guardar la selección de vistas.

WordPress comprueba las versiones requeridas antes de permitir la activación.

== Selección de vistas ==

La selección empieza vacía. El catálogo muestra páginas, entradas y contenidos
publicados de tipos públicos visibles en el frontend. Incluye la portada y la
página de entradas, sin duplicar las páginas que desempeñan esas funciones.
No incluye adjuntos, contenidos no publicados ni otros archivos o rutas especiales.

Se puede buscar por título, filtrar por tipo y selección y navegar entre páginas.
Guardar modifica únicamente las filas mostradas y conserva las demás selecciones.
Los contenidos nuevos y los descendientes no se seleccionan automáticamente.

Las referencias se conservan por ID aunque cambien el título o la URL. Las que
dejan de estar disponibles se muestran en Unavailable selections, desde donde
se pueden retirar. Recuperan su selección si vuelven a estar disponibles con el
mismo ID. Cambiar la portada no selecciona automáticamente otra página.

Configurar y guardar requiere manage_options y un nonce válido. La selección
de clics es independiente del catálogo de vistas y se configura en Clicks.

== Selección y captura de clics ==

DruID Pulse > Clicks permite elegir todos los enlaces y botones, solo enlaces,
solo botones o un selector CSS propio, con prefijo de clase opcional desplegable.
Para seguir una página: Add rule > All links and buttons > Selected pages >
Choose pages. Buscar por título, marcar las páginas, aplicar con Use selected
pages y guardar con Save click rules. No es necesario introducir IDs.
Entire website aplica la regla a todo el frontend compatible. El editor muestra
20 reglas por página y guarda los cambios de todas ellas. El buscador también
pagina 20 contenidos y conserva la selección entre búsquedas.
Por ejemplo, #main-menu a[href] selecciona enlaces dentro de ese menú. Las
exclusiones prevalecen y reglas solapadas no duplican una misma interacción.
Inicialmente no hay reglas y no se capturan clics. Guardar requiere manage_options
más nonce. Las referencias de contenido funcionan también con enlaces simples.

El SDK 0.2.0 ofrece pages y clicks.include/exclude sin depender de WordPress.
getContext recibe type y element; emit recibe el evento. El SDK no envía HTTP.
Detecta a[href] y button, incluidos iconos interiores y elementos dinámicos.
Ratón, teclado nativo y toque cuentan una activación; el botón central solo en
enlaces. No registra clic derecho, eventos programáticos, teclas o formularios.
track() conserva la emisión explícita de cualquier tipo de evento.

El adaptador conserva página origen, etiqueta y destino HTTP(S), tel o mailto.
El origen usa el enlace permanente o una ruta sin parámetros de navegación.
Un botón sin destino conocido no inventa target. No se capturan HTML ni valores
de formularios. Las URLs y los campos de entrada se validan antes de encolar.
El servidor no puede acreditar que la interacción haya ocurrido en el DOM.
Enviar tel a un despliegue de Pulse requiere su validador compatible.

== Captura de vistas ==

Guardar la selección habilita vistas en las siguientes cargas del contenido para
usuarios autenticados de cualquier rol. Se captura una vez con HTML preparado y
página visible. Recarga y retorno por historial cuentan de nuevo; cambiar de
pestaña no. Sin JavaScript no se captura. Se excluyen previsualizaciones,
Personalizador, embeds, errores y pantallas con contraseña nativa pendiente.
No se observan cambios SPA/AJAX automáticamente.

WordPress fija título y URL al recibir la referencia. Usa el enlace permanente
base, conserva parámetros estructurales y no copia campañas/filtros/fragmentos de
navegación. La paginación cuenta otra vista del mismo contenido. Los cambios
posteriores no modifican eventos aceptados. El SDK genérico no impone esta política.

assets/tracker.js expone DruidTracker.create con id, getContext, emit y onError.
Sus instancias ofrecen start, stop y track(type, context). Contexto y consumidor
son configurables; admite tipos adicionales sin depender de WordPress o HTTP.
Inicio/parada e inicialización repetidos no duplican listeners ni vistas. El mismo
id conserva configuración y estado durante el documento. Stop no cancela envíos
ya invocados. El README del repositorio documenta el contrato y su ejemplo sin PHP.

== Conexión y origen ==

Connection permite guardar las URLs HTTPS de Pulse y del servidor de autorización,
cliente, secreto y método OAuth. Los scopes y X-Tenant son opcionales según el
destino. Test OAuth authentication prueba los ajustes guardados, sin enviar eventos.

El secreto queda sin cifrar en la base de datos y sus copias, pero no se devuelve
al formulario. Dejarlo vacío lo conserva; Remove saved secret lo elimina de forma
explícita. Las peticiones no siguen redirecciones con las credenciales.

El origen usa el título y la URL pública de WordPress. Su UUID persiste al cambiar
nombre o dominio; una copia destinada a otra web necesita un UUID diferente.
El trabajador entrega los eventos con la conexión actual. Los pendientes caducan
a los 7 días, incluso durante fallos o desactivación.

== Almacén y mantenimiento ==

El registro compacto guarda tipo, versión, UUID, fecha original en UTC, recepción,
caducidad, ID WordPress del usuario y contexto de página/enlace. No guarda copias
del perfil ni del payload final. Al preparar el envío consulta al usuario y la
aplicación actuales, sin necesitar una sesión activa. Si falta la identidad o el
formato no es compatible, devuelve un error. La fecha y el contexto del hecho se
conservan; cada intento de transporte utiliza la conexión vigente.

Caduca a los 7 días desde la aceptación, también mientras está desactivado.
Los vencidos nunca son elegibles. El mantenimiento horario los elimina en lotes
de hasta 500, según se ejecute WordPress; no garantiza una hora exacta de borrado.
Los límites son 10.000 eventos o 100 MiB de JSON de contexto, y 64 KiB por contexto;
no incluyen el espacio de filas e índices. Los vencidos cuentan hasta su purga.
La inserción rechaza explícitamente saturación, bloqueo ocupado o fallo de BD,
sin expulsar eventos aceptados, incluso bajo concurrencia.

Action Scheduler recibe tareas sin datos personales. El trabajador admite más
tipos mediante el registro común, procesa hasta 25 eventos por pasada y respeta
un presupuesto de tiempo. Comprueba pendientes cada minuto y continúa por tareas,
con un trabajador activo por instalación. Depende de WP-Cron o un ejecutor AS.

200/201 con cuerpo coherente confirma aceptación remota y retira el evento local.
Red, timeout, respuesta ambigua, 408, 429 y 5xx reintentan tras 1, 5, 15 y 60
minutos; luego cada hora, sin prolongar caducidad. Respeta Retry-After mayor.
Los fallos comunes pausan el conjunto de envíos. Un 401 renueva el token para
un nuevo intento; autorización/configuración persistentes esperan una hora.
Connection muestra un código controlado; guardar la conexión despeja esa pausa.

400/413 o contexto inválido quedan fallidos; 409 queda como conflicto sin
confirmar entrega. No reintentan automáticamente y cuentan para los límites
hasta caducar. Identidad no disponible, 422 o mapeador/formato desconocidos se
revisan cada hora. No se inventa identidad ni se guarda la respuesta remota.

El UUID local se envía como externalId estable. Una respuesta perdida puede
requerir reintento; no se garantiza procesamiento exactamente una vez. Un 409
puede corresponder a una reserva remota incompleta y se conserva para revisión.
No hay acción manual de reenvío en esta versión. Un proceso interrumpido deja
un intento recuperable tras un minuto. Una confirmación persistida pendiente
de borrado se limpia sin reenviar.

== Recepción autenticada ==

La ruta REST local POST /wp-druid-pulse/v1/events exige cookie de sesión válida y
nonce wp_rest en X-WP-Nonce. Acepta todos los roles autenticados, obtiene el usuario
en servidor y no admite contraseñas de aplicación ni CORS entre orígenes.
Recibe únicamente type, published (UTC, YYYY-MM-DDTHH:MM:SSZ) y context, con un
máximo de 16 KiB por cuerpo JSON. Cada tipo necesita un validador registrado.
page_view recibe únicamente context.page.key (post:ID o home:posts). WordPress
revalida selección, publicación y acceso nativo, y resuelve título/URL al recibir.
click recibe la página origen (key/url/title), la etiqueta del enlace y el destino
conocido opcional. Revalida el origen, las inclusiones aplicables y la disponibilidad
del contenido. Conserva ese contexto para la preparación posterior.

202 confirma almacenamiento local con un UUID; no confirma entrega a Pulse.
Los errores de validación, sesión, tamaño, capacidad o BD no se presentan como éxito.
La API window.wpDruidPulse.send(event) se carga en el frontend autenticado, sin
generar eventos. Devuelve accepted, rejected o unknown si no puede determinar
el resultado. Usa fetch con keepalive, sin esperar para navegar ni garantizar
entrega al cerrar la página. No hay reintentos ni almacenamiento sin conexión.

Un rechazo de sesión/nonce pausa el transporte hasta recargar. No se reutilizan
eventos antiguos con un nonce de otro usuario. Excluir las páginas autenticadas
de cachés compartidas; la respuesta del receptor es privada y no almacenable.
Estas protecciones no demuestran que una interacción haya ocurrido ni impiden
que un usuario autenticado reproduzca sus peticiones válidas.

== Desactivación y eliminación ==

Desactivar conserva los datos propios y cancela las tareas propias pendientes; una
ejecución iniciada puede terminar. Reactivar restablece mantenimiento y entrega sin
reiniciar datos ni recuperar eventos caducados para envío.
Eliminar desde la pantalla de plugins de WordPress borra únicamente las opciones
wp_druid_pulse_view_selection, wp_druid_pulse_schema_version,
wp_druid_pulse_installation_id, wp_druid_pulse_connection y
wp_druid_pulse_click_rules y wp_druid_pulse_delivery, además del transient
wp_druid_pulse_oauth_token, la tabla de eventos y las tareas/registros propios.
Conserva las tablas compartidas de Action Scheduler y las tareas ajenas.
Una reinstalación
comienza con datos nuevos. Si falla un borrado, la eliminación se interrumpe y
puede reintentarse para retirar los datos restantes. Borrar archivos manualmente
no ejecuta esta limpieza.

== Actualizaciones ==

Este paquete no se actualiza desde el directorio de plugins de WordPress.org.
Las siguientes versiones de desarrollo se distribuirán como paquetes ZIP.
La versión del esquema se comprueba en cada carga, también al actualizar un
plugin activo sin volver a activarlo. La actualización desde 0.1.0 o 0.2.0 crea
la selección vacía si no existe. La actualización a 0.5.0 añade la tabla y el
mantenimiento y solo avanza al esquema 4 tras comprobarlos. Conserva selección,
UUID y conexión existentes. Los errores de lectura o guardado se muestran sin
reiniciar los datos.
La versión 0.8.0 incorpora reglas de clics vacías y avanza al esquema 5,
conservando los ajustes, eventos y mantenimiento existentes.
La versión 0.9.0 avanza al esquema 6: añade metadatos de entrega a la tabla,
estado de pausa común y comprobación periódica. Conserva el contexto y la
caducidad de los pendientes, que pasan a enviarse con la conexión guardada.

Si la inicialización falla, la activación se interrumpe. Si el plugin ya estaba
activo, conserva los datos y deja sus funciones en pausa; los usuarios con
capacidad para activar plugins reciben un aviso en administración. Se reintenta
en peticiones posteriores. Nunca se reemplaza automáticamente un esquema más
reciente o desconocido: hay que restaurar una versión compatible o revisar los
metadatos de instalación.

== Diagnóstico y mantenimiento ==

DruID Pulse > Diagnostics muestra versiones/esquema, configuración, pausa común,
tareas y cola actual. Requiere manage_options. No comprueba la conexión remota ni
muestra usuario, contexto, URLs, títulos, credenciales o respuestas remotas.
Los contadores distinguen elegibles, esperas, fallos, conflictos, caducados y
aceptados pendientes de borrado; los caducados tienen precedencia.
El listado admite filtros y 25 registros por página, con fechas en la zona horaria
de WordPress. No crea historial: los datos desaparecen al entregar o limpiar.
Los logs de Action Scheduler tienen su retención propia. Completar una tarea no
acredita entrega; puede haberse programado otro intento.

Process pending programa trabajo elegible respetando pausas y fechas, sin HTTP
desde el formulario. Clean expired elimina caducados y hasta 500 aceptados por
pasada, sin borrar pendientes vigentes. Retry selected failed events devuelve a
pendientes solo fallidos vigentes, después de corregir la causa. Mantiene ID,
contexto, fechas e intentos acumulados; respeta la pausa común. No reintenta
conflictos 409 ni caducados, y protege frente a selecciones antiguas o simultáneas.
Las acciones comprueban POST, capacidad y nonce. La pantalla conserva acceso de
consulta si falla la inicialización; bloquea acciones ante recursos incompatibles.
No permite vaciar la cola ni editar eventos. Se mantiene el esquema 6 de 0.9.0.

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
