# DruID Pulse for WordPress

Plugin independiente para enviar a DruID Pulse actividad de consumidores autenticados del frontend de WordPress.

## Estado

Versión de desarrollo **0.8.0**. Permite seleccionar contenidos para páginas vistas desde **DruID Pulse → Tracking** y configurar/probar la autenticación OAuth2 desde **DruID Pulse → Connection**.

Captura vistas del contenido seleccionado para visitantes autenticados mediante un SDK JavaScript independiente y un adaptador WordPress. Incluye almacén de eventos, mantenimiento mediante Action Scheduler, receptor autenticado y transporte desde el navegador. Captura también enlaces y botones seleccionados desde **DruID Pulse → Clicks**. La entrega automática a Pulse **todavía no está disponible**. Los eventos se guardan localmente y caducan a los 7 días. La comprobación de conexión solicita un token OAuth; no crea identidades ni envía eventos.

## Requisitos

- WordPress 6.9 o posterior.
- PHP 8.3 o posterior.

No requiere wp-druid, WooCommerce, Composer ni npm. Incluye [Action Scheduler 4.2.0](libraries/README.md) y utiliza las APIs nativas de WordPress. El mantenimiento necesita que se ejecute WP-Cron o el ejecutor de Action Scheduler de la instalación.

## Instalación

1. Utilizar una instalación de WordPress aislada, con versiones compatibles con las declaradas en la cabecera del plugin.
2. Copiar los archivos del plugin a `wp-content/plugins/wp-druid-pulse/`, con `wp-druid-pulse.php` en la raíz de esa carpeta.
3. Activar **DruID Pulse for WordPress** y comprobar el estado mostrado en la lista de plugins.

Si se dispone de un ZIP instalable, se puede subir desde **Plugins → Añadir nuevo plugin → Subir plugin**. Debe contener una única carpeta raíz `wp-druid-pulse/`. Los ZIP generados localmente no se incluyen en este repositorio.

## Ciclo de vida y datos propios

Esta versión usa el esquema 5 y conserva cinco opciones propias:

- `wp_druid_pulse_schema_version`: versión del esquema, con autoload.
- `wp_druid_pulse_view_selection`: selección de vistas, sin autoload.
- `wp_druid_pulse_click_rules`: inclusiones/exclusiones de clics, inicialmente vacías y sin autoload.
- `wp_druid_pulse_installation_id`: UUID persistente, sin autoload.
- `wp_druid_pulse_connection`: conexión y secreto, sin autoload.

El token OAuth se conserva temporalmente en el transient `wp_druid_pulse_oauth_token`, ligado a las opciones de conexión y con caducidad. Cambiar los ajustes lo invalida; un token ausente o caducado se obtiene de nuevo cuando se solicita una operación. Los eventos se guardan en `<prefijo>druid_pulse_events`. Action Scheduler utiliza su infraestructura compartida y programa el mantenimiento propio del plugin.

Activación y actualización inicializan únicamente los recursos que faltan y avanzan al esquema 5 después de comprobarlos. Desde 0.7.0 se añade la opción vacía de clics, conservando selección, UUID, conexión, eventos y mantenimiento. Las actualizaciones de versiones anteriores mantienen sus datos e inicializan los recursos incorporados después. La actualización de un plugin activo se comprueba en `init`, después de inicializar Action Scheduler, sin necesitar una reactivación. No se rebajan esquemas más recientes ni se reinician datos incompatibles.

- **Desactivar/reactivar:** conserva selección, UUID, credenciales y eventos. Desactivar cancela las tareas propias pendientes; una ejecución ya iniciada puede terminar. Reactivar restablece el mantenimiento. La caducidad continúa durante la desactivación y los vencidos no se recuperan para envío.
- **Eliminar desde WordPress:** retira la tabla de eventos, las tareas y registros propios, las cinco opciones y el transient. Conserva las tablas compartidas de Action Scheduler y las tareas de otros componentes. Un fallo de borrado interrumpe la desinstalación y permite reintentarlo.
- **Reinstalar tras eliminar:** comienza con configuración vacía y otro UUID.
- **Borrar archivos manualmente:** no ejecuta esta limpieza.

Un fallo de inicialización impide completar la activación. Si ocurre al actualizar un plugin activo, sus funciones se pausan y se muestra un aviso a usuarios con capacidad `activate_plugins`; se reintenta en peticiones posteriores. Los errores de configuración no muestran secretos ni respuestas del servidor.

## Configurar la conexión

En **DruID Pulse → Connection**, con `manage_options`:

1. Introducir la URL HTTPS base de la API de Pulse, sin `/identities`, y la del servidor de autorización, sin `/oauth2/token`.
2. Indicar el cliente OAuth y su secreto. Elegir `client_secret_basic` o `client_secret_post` según el registro del cliente. Se utiliza `client_credentials`.
3. Indicar scopes solo cuando corresponda al cliente; si quedan vacíos no se envía ese parámetro. El tenant es opcional y se envía como `X-Tenant` cuando el destino lo requiere.
4. Guardar y pulsar **Test OAuth authentication**. Se comprueba la autenticación con los ajustes guardados; un resultado correcto no acredita entrega de eventos a Pulse.

Los formularios comprueban capacidad, método POST y nonce. El secreto se guarda **sin cifrar en la base de datos y sus copias**; no se devuelve en el HTML. Dejarlo vacío conserva el existente; para borrarlo, marcar **Remove saved secret** y dejar el campo vacío. Las peticiones usan la HTTP API segura de WordPress, HTTPS y verificación TLS; no siguen redirecciones con las credenciales.

El origen toma automáticamente el título del sitio y su URL pública de **Ajustes → Generales**. El UUID se mantiene al cambiar nombre o dominio. Al clonar la base de datos para otra web independiente, es necesario asignar a `wp_druid_pulse_installation_id` un nuevo UUID antes de habilitar seguimiento; una migración del mismo sitio conserva el UUID. Esta versión no detecta automáticamente si una copia es un clon o una migración.

## Preparación del envío

Los servicios internos de resolución, mapeo y HTTP están separados. El registro de mapeadores permite añadir un tipo en PHP sin cambiar el cliente de transporte. Inicialmente incluye `watch` + `page` y `click` + `link`; en clics, `object.url` es la página de procedencia y el `target` opcional representa el destino conocido.

`EventMapper::register($type, $mapper, $validator = null)` reúne el mapeo y la validación de entrada de cada tipo. El validador recibe el contexto, devuelve un array con los campos permitidos y normalizados o un `WP_Error`; debe rechazar los campos no previstos. Un error puede indicar un `status` HTTP entero entre 400 y 599 en sus datos; por defecto se responde 400. El receptor no expone los demás datos del error. Sin validador, el tipo conserva su mapeo interno pero rechaza la recepción de contexto externo. El hook `wp_druid_pulse_register_event_types` recibe la instancia de `EventMapper` al terminar cada construcción, después de registrar los mapeadores incluidos. Permite que recepción y preparación carguen las mismas definiciones; se ejecuta por instancia y no captura ni envía eventos.

Con `wp-druid` activo se consulta su API canónica y se envía únicamente `actor.externalIds["druid:objectid"]`. Si falta esa API o el identificador, se devuelve un error sin recurrir al perfil de WordPress. Sin esa integración se utilizan correo real, nombre y apellidos disponibles; no se añade teléfono ni se utilizan los correos técnicos conocidos como identidad. La falta de identificación devuelve un error, sin sustituir al usuario por su ID numérico de WordPress.

El cliente envía un evento a `POST /identities` y reconoce 200/201. Un 401 invalida el token; no se reenvía automáticamente el evento. Los fallos de transporte o respuestas inesperadas no se presentan como éxito. Estos servicios aún no se conectan a un trabajador de entrega.

## Recepción desde el navegador

La ruta local `POST /wp-druid-pulse/v1/events`, dentro de la REST API de WordPress, requiere una **cookie de sesión válida y un nonce `wp_rest` en `X-WP-Nonce`**. Admite cualquier rol autenticado. No admite anónimos, autenticación exclusivamente mediante contraseñas de aplicación ni acceso CORS desde otros orígenes. Un `Origin` presente debe coincidir con el de la URL pública del sitio. El ID del usuario se obtiene en servidor, nunca del JSON ni de parámetros de la URL. Las credenciales y tokens de Pulse no llegan al navegador.

El cuerpo debe ser JSON y contener únicamente `type`, `published` y `context`. `type` identifica un tipo registrado con validador de entrada; `published` es la fecha original declarada por el navegador, en UTC y con precisión de segundos (`YYYY-MM-DDTHH:MM:SSZ`); `context` contiene los campos específicos permitidos por ese validador. No se admiten fechas inválidas ni se sustituyen por la fecha de recepción. La retención se calcula desde la aceptación local, independientemente de esa fecha declarada.

`page_view` admite únicamente `context: {"page":{"key":"post:123"}}`, o la referencia `home:posts` para la portada de últimas entradas. WordPress revalida selección, publicación y contraseña nativa y completa título/URL antes de guardar. Rechaza campos adicionales, incluidas URLs o títulos aportados por el navegador. `click` admite `page: {key, url, title}`, `link: {title}` y, opcionalmente, `target: {objectType, url}`. El receptor revalida que haya inclusiones aplicables a esa referencia, la disponibilidad del contenido y el origen de la URL; rechaza otros campos, HTML de formulario e identidad aportada por el cliente. Sanea los títulos. El servidor no puede demostrar que un selector coincidiese en el DOM ni que una interacción declarada haya ocurrido. Una extensión PHP puede registrar otro tipo mediante `wp_druid_pulse_register_event_types`, con su mapeador y validador. La aceptación de tipos adicionales no requiere cambiar el receptor ni el transporte.

- Límite: **16 KiB del cuerpo HTTP completo en UTF-8**, comprobado antes de decodificar JSON. Es independiente del máximo interno de 64 KiB por contexto.
- Éxito: `202` con `{"accepted":true,"event_id":"<UUID local>"}`, solo después de persistir. Confirma aceptación en WordPress, no entrega a Pulse.
- Errores: `400` por JSON/contrato/tipo/contexto inválido, `401`/`403` por sesión o nonce, `403` por origen ajeno, `413` por tamaño, `415` por contenido no JSON, `503` por cola llena u ocupada o imposibilidad de resolver la configuración/contexto y `500` por fallo de almacenamiento. Conservan el formato de error REST de WordPress. Si la inicialización del plugin falla, la ruta no se registra.

El script `wp-druid-pulse-transport` se carga únicamente en el frontend autenticado. Tras su carga, `window.wpDruidPulse.send({ type, published, context })` devuelve una promesa con uno de estos resultados:

- `{ status: 'accepted', eventId }`: confirmación válida de almacenamiento local.
- `{ status: 'rejected', code, httpStatus? }`: rechazo local o respuesta de error del receptor.
- `{ status: 'unknown', code, httpStatus? }`: error de red o respuesta que no permite determinar la aceptación. El evento podría haberse guardado.

Usa `fetch` con `keepalive`, cookies y nonce del mismo origen. El llamador no debe esperar la promesa para permitir una navegación. No hay reintentos, `sendBeacon`, almacenamiento del navegador ni cola sin conexión. Los navegadores pueden limitar el total de cuerpos `keepalive` pendientes; no se garantiza entrega al navegar, cerrar la pestaña o perder conexión.

El nonce solo se renueva a partir de una confirmación válida de WordPress. Un rechazo de sesión/nonce pausa nuevos envíos hasta recargar la página; no se recupera otro nonce para reenviar eventos antiguos bajo un nuevo usuario. **Las páginas autenticadas y su configuración deben quedar fuera de cachés compartidas**, y el endpoint responde con `Cache-Control: no-store, private`. Un usuario autenticado puede reproducir peticiones válidas: sesión y nonce no demuestran que una interacción haya ocurrido ni deduplican eventos.

## Almacén de eventos

El receptor valida el contexto y atribuye el usuario antes de llamar al almacén. Los demás llamadores PHP deben cumplir también ese contrato. Cada registro contiene UUID local, versión del formato, tipo, fecha original en UTC, fecha de recepción/caducidad, ID WordPress del usuario y contexto específico del evento. Para vistas y clics, ese contexto conserva las referencias, URLs y títulos originales, incluido el destino conocido.

No se guarda el payload final de Pulse ni una copia del perfil, proveedor o conexión. Al preparar el envío se consulta al usuario por ID, sin necesitar sesión activa, y se reconstruyen el actor y la aplicación con los datos actuales. Un cambio de correo, nombre o modo de integración con `wp-druid` se refleja entonces. Si el usuario desaparece o deja de ser identificable, la preparación devuelve un error sin inventar una identidad. Un formato persistido desconocido también se rechaza.

Cada intento de transporte utiliza la conexión vigente, aunque hayan cambiado la URL de Pulse, tenant o cliente OAuth. Cambiar el UUID de instalación tampoco bloquea los pendientes. El contexto de página/enlace y la fecha del hecho se mantienen.

- Caducidad: **7 días desde la aceptación local**, también durante fallos o desactivación. Los vencidos dejan de ser elegibles antes de su eliminación física.
- Capacidad: **10.000 registros o 100 MiB de JSON de contexto**, lo que se alcance primero; **64 KiB por contexto**. No incluye el espacio adicional de filas e índices de la base de datos. Los registros vencidos cuentan hasta que se purgan.
- Una inserción que supera los topes, encuentra el almacén ocupado o falla en base de datos devuelve un error explícito. No expulsa registros aceptados. La comprobación de capacidad y la inserción se protegen con un bloqueo de MySQL por instalación, sin esperar si está ocupado.
- El mantenimiento se programa cada hora, con la primera ejecución disponible inmediatamente, y borra los vencidos en lotes de hasta 500. Depende de la ejecución de WordPress y no garantiza eliminación física puntual ni modifica copias de seguridad.

El hook interno `wp_druid_pulse_cleanup_events`, sin argumentos y en el grupo `wp-druid-pulse`, ejecuta esa limpieza. La recuperación nativa `action_scheduler_ensure_recurring_actions` restablece una tarea ausente. No se copian eventos ni datos personales a los argumentos de Action Scheduler ni se modifica su política global de retención.

El almacén admite tipos adicionales mediante el registro de mapeadores. La preparación y el cliente HTTP no retiran registros por sí solos: la entrega automática, la eliminación tras aceptación y las políticas de reintento se conectarán con el trabajador de entrega.

## Configurar vistas

La pantalla **DruID Pulse → Tracking** requiere `manage_options`, igual que los ajustes generales de WordPress. Los formularios comprueban además un nonce.

- La selección inicial está vacía. El catálogo incluye páginas, entradas y contenidos publicados de tipos públicos visibles en el frontend; excluye adjuntos, contenidos no publicados y otros archivos o rutas especiales.
- La portada estática y la página de entradas se identifican en su propia fila. La portada de últimas entradas tiene una fila específica. Cambiar la asignación de portada o blog no selecciona automáticamente otro contenido.
- La búsqueda por título, los filtros y la paginación permiten localizar contenidos. **Save selection** modifica únicamente las filas visibles y conserva las selecciones de otras páginas. Se avisa antes de abandonar cambios sin guardar.
- Las selecciones siguen el ID, por lo que sobreviven a cambios de título o URL. Los contenidos nuevos y los descendientes quedan desmarcados.
- **Unavailable selections** muestra las referencias seleccionadas que dejaron de estar disponibles y permite retirarlas. Si el contenido vuelve a estar disponible con el mismo ID, conserva la selección.

Guardar la selección habilita vistas en las siguientes cargas de ese contenido para cualquier usuario autenticado, incluidos administradores. El transporte común sigue disponible en el resto del frontend autenticado para otros tipos registrados. La selección de vistas no determina dónde se podrán seleccionar clics.

Una vista se genera cuando el HTML está preparado y el documento se hace visible. Recargar o volver por Atrás/Adelante cuenta otra vista, incluida la restauración desde la caché del historial; cambiar de pestaña y regresar no. No se captura sin JavaScript, en administración, previsualizaciones, Personalizador, embeds, feeds, archivos, búsquedas, errores ni mientras siga pendiente la contraseña nativa del contenido. Tras desbloquearlo, el contenido seleccionado puede generar vistas. No hay detección automática de cambios de rutas SPA/AJAX.

El navegador envía referencia y fecha del hecho. Al recibir, WordPress obtiene el título y la URL base mediante sus APIs; conserva parámetros estructurales como `?p=123`, sin copiar parámetros de navegación, campañas o fragmentos. La paginación cuenta como otra vista del mismo contenido y su URL base. Cambios de título/URL entre carga y recepción se reflejan al aceptar; cambios posteriores no alteran el evento guardado. Si el contenido deja de estar seleccionado, publicado o accesible antes de recibir, se rechaza el nuevo evento sin modificar los ya aceptados.

## Configurar clics

**DruID Pulse → Clicks** requiere `manage_options` y nonce. Añadir filas de inclusión/exclusión, un selector CSS y, si interesa, un prefijo de clase. Elegir todas las páginas del frontend o IDs específicos del catálogo; `home:posts` representa la portada de últimas entradas. Se admiten varios IDs separados por comas. La elección no depende de qué vistas estén seleccionadas. Las referencias se resuelven por ID en PHP antes de configurar el SDK; los enlaces simples `?p=123` no amplían una regla a otros contenidos.

Se recoge la etiqueta (`aria-label`, texto o atributo `title`) y el destino conocido del enlace. No se inventan IDs de enlaces ni destinos de botones. Se admiten destinos HTTP(S), `tel:` y `mailto:`; otros protocolos no se añaden como destino. El objeto conserva la URL de origen; el destino HTTP(S) se representa como `page`, y teléfono/correo como `link`. El plugin admite URLs de hasta 1024 bytes UTF-8, dentro del máximo de longitud de Pulse; las validaciones también rechazan espacios/control, credenciales en URL y escapes porcentuales incompletos. Aceptar `tel:` en un despliegue de Pulse requiere su validador compatible; esta versión no despliega cambios en Pulse.

La página de origen se fija al generar el HTML: enlace permanente y título para contenido del catálogo, o ruta sin query/fragmento para otras páginas del frontend. Se conservan parámetros estructurales del enlace permanente, como `?p=123`. En búsquedas se utiliza el título del sitio para no capturar el texto buscado. El adaptador no observa navegación SPA. El contenido no publicado/no disponible, las contraseñas nativas pendientes, las previsualizaciones y el Personalizador no habilitan captura automática. El receptor guarda ese contexto del clic sin reconstruirlo al preparar el envío.

## SDK JavaScript independiente

[`assets/tracker.js`](assets/tracker.js) expone `window.DruidTracker` (versión 0.2.0). No necesita PHP, WordPress ni dependencias. No lee identidad ni cookies, ni realiza peticiones. Consulta `location.pathname` al evaluar filtros declarativos de rutas; el consumidor proporciona contexto y destino. El [ejemplo independiente](examples/tracker.html) muestra eventos en la página sin enviarlos a un servidor.

```js
const tracker = DruidTracker.create({
    id: 'my-integration',
    getContext: () => ({ page: { name: 'Catalog' } }),
    emit: event => myConsumer(event),
    onError: error => showCaptureError(error.code)
});
tracker.start();
tracker.track('product_selected', { product: 'example' }).catch(handleError);
```

El consumidor recibe `{ type, published, context }`, con fecha UTC en segundos. `getContext()` es síncrono, recibe `{ type: 'page_view', element: null }` para vistas o `{ type: 'click', element }` para clics, con el enlace o botón resuelto y devuelve un objeto o `null` para omitir esa activación. Los callbacks existentes pueden seguir ignorando ese argumento. `emit()` puede ser síncrono o devolver una promesa; el SDK no interpreta su resultado. `track(type, context)` devuelve ese resultado en una promesa, o `null` si está detenido. Los tipos explícitos no se deduplican por nombre, URL o tiempo.

La opción `pages` selecciona las vistas automáticas: `true` (también el valor predeterminado), `false` o `{ include: ['/catalogo/'], exclude: ['/privado/'] }`. Las listas se comparan exactamente con `location.pathname`, sin query ni fragmento, sin normalizar mayúsculas o barras finales ni interpretar comodines. Usar rutas tal como aparecen en `pathname`, incluidos sus caracteres codificados. Omitir `include` permite todas las rutas salvo las excluidas; `include: []` no permite ninguna. La exclusión prevalece. Las reglas no modifican el contexto ni restringen `track()`; una vista excluida no consulta `getContext()`. La configuración se conserva al crear la instancia: modificar los arrays originales no cambia sus reglas.

`clicks` está desactivado al omitirlo o indicar `false`. Para activarlo:

```js
clicks: {
    include: [
        { pages: ['/ofertas/'], selector: 'a[href]' },
        { selector: '#menu-principal a[href]' },
        { selector: 'a[href], button', classPrefix: 'promo-' }
    ],
    exclude: [{ selector: '.sin-tracking' }]
}
```

Cada regla requiere un selector CSS que coincida con el enlace o botón, no solo con su contenedor. `pages` es una lista opcional de rutas exactas de origen; su ausencia permite cualquier ruta. `classPrefix` comprueba el principio de cada clase individual. Dentro de una regla se cumplen todas las condiciones; basta una inclusión y las exclusiones prevalecen. Sin inclusiones no hay captura. Estas rutas son independientes de la opción superior `pages`: desactivar vistas no desactiva clics.

Se capturan enlaces `a[href]` y botones HTML `button`, incluidos hijos como iconos y elementos añadidos posteriormente al DOM. Una activación real por clic principal, teclado nativo o toque produce un evento `click`; el botón central solo cuenta en enlaces. Se excluyen clic derecho, botones deshabilitados y eventos sintéticos (`.click()`/`dispatchEvent`); `track()` sigue disponible. No se registran teclas, foco, movimientos ni valores de formulario. No se cancela la navegación ni se espera al consumidor. No se recorren iframes ni Shadow DOM cerrado. Un selector inválido rechaza la configuración con `invalid_configuration`.

`start()` y `stop()` son idempotentes. Detener retira los listeners y conserva el estado de la vista; no cancela consumidores ya invocados ni reproduce activaciones perdidas mientras estaba parado. Reiniciar no repite una vista ya observada. Cada `id` identifica una instancia durante la vida del documento: recrearlo devuelve la misma configuración/estado, y cargar dos veces la misma versión conserva ese registro. IDs distintos permiten consumidores independientes. No hay identificadores persistentes, cola, reintentos ni distribución npm en esta versión.

La configuración inválida lanza `invalid_configuration`. Los errores de captura automática llegan a `onError(error, {type})` con códigos `context_failed`, `invalid_event` o `consumer_failed`; los envíos explícitos rechazan su promesa y deben manejarse en el llamador. Los errores no incluyen el contexto ni la excepción original del consumidor. Si el propio `onError` falla, se notifica un error global `error_handler_failed`. Un fallo no provoca reintentos ni bloquea navegación.

WordPress conecta este SDK con `wpDruidPulse.send` mediante `assets/frontend-views.js`, cuando hay vistas o inclusiones de clics aplicables. Conserva el handle existente `wp-druid-pulse-views` para este adaptador común. `wpDruidPulse.tracker` permite acceder a esa instancia. El adaptador emite el evento de ventana `wp-druid-pulse:tracking-result` tras cada resultado del transporte o fallo automático, con `detail: { type, result }` y sin contexto ni actor. Los resultados conservan `accepted`, `rejected` o `unknown`; confirmar aceptación local no acredita entrega a Pulse. La prevención de duplicados del ciclo de vida no deduplica peticiones repetidas al receptor.

## Verificación

La versión 0.8.0 se ha comprobado en WordPress 6.9.4/PHP 8.3.31/MySQL 8.0 aislados, con datos sintéticos y prefijo distinto de `wp_`: 190 comprobaciones HTTP de receptor/vistas/clics, 93 del SDK/adaptador, 30 del transporte, 88 de integración, 45 de cola, 26 de administración y 42 de ciclo de vida. Incluyen permisos, nonce, fallo de escritura, fallo/recuperación de migración y retirada de recursos propios.

La actualización activa nativa desde 0.7.0 conserva ajustes, evento pendiente y la misma tarea de mantenimiento, avanzando al esquema 5 con reglas vacías. El ZIP final se instaló y activó mediante las APIs nativas tras desinstalar en frío; sus 148 archivos coinciden por hash. Action Scheduler permanece idéntico a 0.7.0.

Chrome 150 pasa 33 comprobaciones de clics con el SDK y el ZIP instalado en WordPress, más 11 del filtro de páginas con retorno real desde BFCache. Se revisó visualmente la pantalla Clicks y se contrastaron tres confirmaciones del navegador y el clic de navegación con la cola y el mapeador. Ratón, teclado, toque y botón central se prueban con entrada real del navegador. Los topes y concurrencia del almacén, sin cambios, se verificaron en 0.5.0.

Las suites y sus condiciones están en [tests/README.md](tests/README.md). No acreditan todos los navegadores, constructores, plugins de acceso, cachés compartidas ni conexión con un despliegue real de Pulse. La entrega automática continúa pendiente.

## Desarrollo y distribución

Repositorio: [dru-id/wp-druid-pulse](https://github.com/dru-id/wp-druid-pulse), rama `main`.

Mantener los archivos PHP con UTF-8 sin BOM y convenciones de WordPress.

El ZIP 0.8.0 incluye el archivo principal, `src/`, `templates/`, `assets/`, `libraries/` con sus licencias, `uninstall.php` y `readme.txt`, bajo `wp-druid-pulse/`.

La cabecera `Update URI: false` evita que WordPress.org sustituya este plugin de desarrollo por otro que tenga el mismo nombre de carpeta. No se ha añadido un servidor ni un mecanismo propio de actualizaciones.
