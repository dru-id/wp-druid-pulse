# Referencia técnica de DruID Pulse for WordPress

Contratos para extender o mantener el plugin 0.10.1. Para instalarlo y configurar vistas y clics desde WordPress, consulta la [guía del integrador](../README.md).

## Ciclo de vida y datos propios

Esta versión usa el esquema 6 y conserva seis opciones propias:

- `wp_druid_pulse_schema_version`: versión del esquema, con autoload.
- `wp_druid_pulse_view_selection`: selección de vistas, sin autoload.
- `wp_druid_pulse_click_rules`: inclusiones/exclusiones de clics, inicialmente vacías y sin autoload.
- `wp_druid_pulse_installation_id`: UUID persistente, sin autoload.
- `wp_druid_pulse_connection`: conexión y secreto, sin autoload.
- `wp_druid_pulse_delivery`: pausa común, errores controlados y estado de recuperación de la conexión, sin autoload.

El token OAuth se conserva temporalmente en el transient `wp_druid_pulse_oauth_token`, ligado a las opciones de conexión y con caducidad. Cambiar los ajustes lo invalida; un token ausente o caducado se obtiene de nuevo cuando se solicita una operación. Los eventos se guardan en `<prefijo>druid_pulse_events`. Action Scheduler utiliza su infraestructura compartida y programa la entrega y el mantenimiento propios del plugin.

Activación y actualización inicializan únicamente los recursos que faltan y avanzan al esquema 6 después de comprobarlos. Desde 0.9.0 se añaden metadatos de entrega a la tabla existente, una opción de recuperación y la tarea periódica de entrega, conservando configuración, eventos y mantenimiento. Los eventos anteriores pasan a estar pendientes de envío con la conexión guardada. Las actualizaciones de versiones anteriores mantienen sus datos e inicializan los recursos incorporados después. La actualización de un plugin activo se comprueba en `init`, después de inicializar Action Scheduler, sin necesitar una reactivación. No se rebajan esquemas más recientes ni se reinician datos incompatibles.

- **Desactivar/reactivar:** conserva selección, UUID, credenciales y eventos. Desactivar cancela las tareas propias pendientes; una ejecución ya iniciada puede terminar. Reactivar restablece mantenimiento y entrega, conservando las esperas de reintento. La caducidad continúa durante la desactivación y los vencidos no se recuperan para envío.
- **Eliminar desde WordPress:** retira la tabla de eventos, las tareas y registros propios, las seis opciones y el transient. Conserva las tablas compartidas de Action Scheduler y las tareas de otros componentes. Un fallo de borrado interrumpe la desinstalación y permite reintentarlo.
- **Reinstalar tras eliminar:** comienza con configuración vacía y otro UUID.
- **Borrar archivos manualmente:** no ejecuta esta limpieza.

Un fallo de inicialización impide completar la activación. Si ocurre al actualizar un plugin activo, sus funciones se pausan y se muestra un aviso a usuarios con capacidad `activate_plugins`; se reintenta en peticiones posteriores. Los errores de configuración no muestran secretos ni respuestas del servidor.

### Clonar una instalación

El identificador se genera al instalar y persiste al cambiar el nombre o dominio de la misma web. Si copias su base de datos para crear una web independiente, también copias ese identificador: el plugin no distingue automáticamente una clonación de una migración.

En la **copia**, con el plugin desactivado, asigna un UUID nuevo. Si dispones de WP-CLI, ejecuta desde la instalación copiada:

```sh
wp eval 'update_option( "wp_druid_pulse_installation_id", wp_generate_uuid4(), false );'
```

La copia también puede contener credenciales y eventos pendientes del sitio original. Revisa esos datos antes de habilitar seguimiento y entrega: cambiar el UUID no elimina ni bloquea los pendientes, que usarán la conexión vigente al enviarse. Una migración de la misma web conserva el UUID y no requiere este cambio.

## Preparación del envío

Los servicios internos de resolución, mapeo y HTTP están separados. El registro de mapeadores permite añadir un tipo en PHP sin cambiar el cliente de transporte. Inicialmente incluye `watch` + `page` y `click` + `link`; en clics, `object.url` es la página de procedencia y el `target` opcional representa el destino conocido.

`EventMapper::register($type, $mapper, $validator = null)` reúne el mapeo y la validación de entrada de cada tipo. El validador recibe el contexto, devuelve un array con los campos permitidos y normalizados o un `WP_Error`; debe rechazar los campos no previstos. Un error puede indicar un `status` HTTP entero entre 400 y 599 en sus datos; por defecto se responde 400. El receptor no expone los demás datos del error. Sin validador, el tipo conserva su mapeo interno pero rechaza la recepción de contexto externo. El hook `wp_druid_pulse_register_event_types` recibe la instancia de `EventMapper` al terminar cada construcción, después de registrar los mapeadores incluidos. Permite que recepción y preparación carguen las mismas definiciones; se ejecuta por instancia y no captura ni envía eventos.

Con `wp-druid` activo se consulta su API canónica y se envía únicamente `actor.externalIds["druid:objectid"]`. Si falta esa API o el identificador, se devuelve un error sin recurrir al perfil de WordPress. Sin esa integración se utilizan correo real, nombre y apellidos disponibles; no se añade teléfono ni se utilizan los correos técnicos conocidos como identidad. La falta de identificación devuelve un error, sin sustituir al usuario por su ID numérico de WordPress.

El cliente envía un evento a `POST /identities` y reconoce 200/201. Un 401 invalida el token. El cliente HTTP no repite peticiones por sí solo: el trabajador aplica los reintentos descritos abajo. Los fallos de transporte o respuestas inesperadas no se presentan como éxito.

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

El receptor valida el contexto y atribuye el usuario antes de llamar al almacén. Los demás llamadores PHP deben cumplir también ese contrato. Cada registro contiene UUID local, versión del formato, tipo, fecha original en UTC, fecha de recepción/caducidad, ID WordPress del usuario y contexto específico del evento. Incluye estado de entrega, número de intentos de preparación/envío, próxima fecha de intento y último código controlado de error. Para vistas y clics, ese contexto conserva las referencias, URLs y títulos originales, incluido el destino conocido.

No se guarda el payload final de Pulse ni una copia del perfil, proveedor o conexión. Al preparar el envío se consulta al usuario por ID, sin necesitar sesión activa, y se reconstruyen el actor y la aplicación con los datos actuales. Un cambio de correo, nombre o modo de integración con `wp-druid` se refleja entonces. Si el usuario desaparece o deja de ser identificable, la preparación devuelve un error sin inventar una identidad. Un formato persistido desconocido también se rechaza.

Cada intento de transporte utiliza la conexión vigente, aunque hayan cambiado la URL de Pulse, tenant o cliente OAuth. Cambiar el UUID de instalación tampoco bloquea los pendientes. El contexto de página/enlace y la fecha del hecho se mantienen.

- Caducidad: **7 días desde la aceptación local**, también durante fallos o desactivación. Los vencidos dejan de ser elegibles antes de su eliminación física.
- Capacidad: **10.000 registros o 100 MiB de JSON de contexto**, lo que se alcance primero; **64 KiB por contexto**. No incluye el espacio adicional de filas e índices de la base de datos. Los registros vencidos cuentan hasta que se purgan.
- Una inserción que supera los topes, encuentra el almacén ocupado o falla en base de datos devuelve un error explícito. No expulsa registros aceptados. La comprobación de capacidad y la inserción se protegen con un bloqueo de MySQL por instalación, sin esperar si está ocupado.
- El mantenimiento se programa cada hora, con la primera ejecución disponible inmediatamente, y borra los vencidos en lotes de hasta 500. Depende de la ejecución de WordPress y no garantiza eliminación física puntual ni modifica copias de seguridad.

El hook interno `wp_druid_pulse_cleanup_events`, sin argumentos y en el grupo `wp-druid-pulse`, ejecuta esa limpieza. La recuperación nativa `action_scheduler_ensure_recurring_actions` restablece una tarea ausente. No se copian eventos ni datos personales a los argumentos de Action Scheduler ni se modifica su política global de retención.

## Entrega y recuperación

El trabajador consume cualquier tipo registrado mediante el mismo mapeador y cliente HTTP. No necesita la sesión del usuario que originó el evento. Envía un evento por `POST /identities`, con `externalId: "wp-druid-pulse:event:<UUID local>"` estable entre intentos. Utiliza siempre el actor, origen de instalación y conexión vigentes; el identificador del evento no cambia con ellos.

Action Scheduler comprueba la cola cada minuto y programa tareas sin argumentos ni datos personales. Hay un trabajador propio activo por instalación, separado del bloqueo de inserción. Cada pasada procesa hasta 25 eventos, primero los elegibles más antiguos; reserva tiempo para las peticiones de OAuth/Pulse, de hasta 10 segundos cada una, dentro de un presupuesto de 25 segundos. Una tarea completada programa la continuación si quedan elegibles. La cadencia depende de WP-Cron, tráfico, otros trabajos y recursos del servidor; no es una garantía de latencia ni capacidad. No se modifican límites globales de Action Scheduler.

- **Confirmación:** HTTP 200/201 con el campo JSON `status` coincidente confirma aceptación en Pulse y elimina el evento local. No acredita que haya terminado todo el procesamiento remoto. Si el borrado local falla después de registrar esa confirmación, el estado `accepted` permite volver a intentar el borrado sin reenviar.
- **Fallos temporales:** red, timeout, respuesta ambigua, HTTP 408/429/5xx y errores temporales OAuth esperan 1, 5, 15 y 60 minutos; después, cada hora. Un `Retry-After` válido con una espera mayor prevalece. El fallo común pausa los envíos de la instalación, evitando repetirlo con cada fila. Nunca prolonga los siete días originales.
- **Autorización/configuración:** ante 401 de Pulse, se invalida el token y se intenta de nuevo tras un minuto con token renovado. Si persiste, o hay rechazo de permisos/configuración, se comprueba cada hora. Una conexión incompleta no envía. Guardar la conexión, incluso sin cambiar sus valores, despeja la pausa común. Connection avisa del problema y enlaza con Diagnostics, que muestra una explicación controlada sin respuesta remota ni secretos.
- **Errores del evento:** HTTP 400/413 y contexto almacenado o mapeo inválidos quedan `failed`; HTTP 409 queda `conflict`. Se conservan sin reintento automático hasta caducar y no bloquean otros eventos. Los fallidos y conflictos siguen contando para los límites del almacén. Diagnostics permite devolver a pendientes los fallidos vigentes mediante **Retry selected failed events**, después de corregir la causa. Conserva su identidad, contexto, caducidad e intentos y respeta la pausa común. Los conflictos y caducados no se pueden reintentar manualmente.
- **Identidad o extensión no disponible:** usuario eliminado, identidad ausente, HTTP 422, tipo sin mapeador o versión de contexto desconocida se revisan cada hora. No se inventa identidad ni se reinterpreta el contexto. Los errores no reconocidos de un mapeador usan un código genérico y una espera de una hora.

Se persiste el intento antes de iniciar HTTP; si el proceso termina inesperadamente, el pendiente vuelve a ser elegible después de un minuto. El bloqueo se libera al cerrar la conexión de base de datos. Las respuestas ambiguas se reintentan con el mismo `externalId`: el índice único previsto por Pulse ayuda si está aplicado, pero un 409 también puede corresponder a una reserva incompleta y no se considera confirmación. No se garantiza procesamiento exactamente una vez; tampoco entre destinos distintos si cambia la conexión. Si no se puede persistir el resultado de un envío, puede volver a intentarse.

Los hooks internos, sin argumentos y en `wp-druid-pulse`, son `wp_druid_pulse_check_delivery` (comprobación periódica) y `wp_druid_pulse_deliver_events` (pasada de entrega), además del mantenimiento existente. Se recuperan tareas periódicas ausentes mediante el mecanismo nativo de Action Scheduler. La limpieza también retira confirmados pendientes de borrado. Desactivar cancela únicamente las tareas propias; una ejecución ya iniciada puede terminar.

## Contexto de páginas y clics

Para una vista, el navegador envía la referencia del contenido y la fecha del hecho. Al recibir, WordPress obtiene el título y la URL base mediante sus APIs; conserva parámetros estructurales como `?p=123`, sin copiar parámetros de navegación, campañas o fragmentos. La paginación cuenta como otra vista del mismo contenido y su URL base. Cambios de título/URL entre carga y recepción se reflejan al aceptar; cambios posteriores no alteran el evento guardado. Si el contenido deja de estar seleccionado, publicado o accesible antes de recibir, se rechaza el nuevo evento sin modificar los ya aceptados.

Para los clics, la página de origen se fija al generar el HTML: enlace permanente y título para contenido del catálogo, o ruta sin query/fragmento para otras páginas del frontend. En búsquedas se utiliza el título del sitio para no capturar el texto buscado. El receptor conserva ese contexto para la preparación posterior. El adaptador no observa navegación SPA.

La etiqueta del enlace o botón se obtiene de `aria-label`, texto o atributo `title`, en ese orden. No se inventan IDs de enlaces ni destinos de botones. El destino HTTP(S) se representa como `page`, y teléfono/correo como `link`. El plugin admite URLs de hasta 1024 bytes UTF-8; las validaciones rechazan espacios/control, credenciales en URL y escapes porcentuales incompletos. Un destino `tel:` requiere un despliegue de Pulse con validador compatible.

## SDK JavaScript independiente

[`assets/tracker.js`](../assets/tracker.js) expone `window.DruidTracker` (versión 0.2.0). No necesita PHP, WordPress ni dependencias. No lee identidad ni cookies, ni realiza peticiones. Consulta `location.pathname` al evaluar filtros declarativos de rutas; el consumidor proporciona contexto y destino. El [ejemplo independiente](../examples/tracker.html) muestra eventos en la página sin enviarlos a un servidor.

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

## Desarrollo y distribución

Repositorio: [dru-id/wp-druid-pulse](https://github.com/dru-id/wp-druid-pulse), rama `main`.

Mantener los archivos PHP con UTF-8 sin BOM y convenciones de WordPress. Las suites y sus requisitos se describen en [Pruebas en WordPress](../tests/README.md); modifican datos y solo deben ejecutarse en una instalación desechable.

El paquete instalable incluye el archivo principal, `src/`, `templates/`, `assets/`, `libraries/` con sus licencias, `uninstall.php` y `readme.txt`, bajo `wp-druid-pulse/`.

La cabecera `Update URI: false` evita que WordPress.org sustituya este plugin de desarrollo por otro que tenga el mismo nombre de carpeta. No se ha añadido un servidor ni un mecanismo propio de actualizaciones.

El repositorio contiene las bibliotecas necesarias; no hay pasos de Composer ni npm para instalar el plugin. Los archivos de pruebas, ejemplos, documentación del repositorio y metadatos de Git no forman parte del ZIP instalable.
