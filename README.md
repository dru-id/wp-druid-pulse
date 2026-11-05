# DruID Pulse for WordPress

Plugin independiente para enviar a DruID Pulse actividad de consumidores autenticados del frontend de WordPress.

## Estado

Versión de desarrollo **0.5.0**. Permite seleccionar contenidos para páginas vistas desde **DruID Pulse → Tracking** y configurar/probar la autenticación OAuth2 desde **DruID Pulse → Connection**.

Incluye un almacén interno de eventos y su mantenimiento mediante Action Scheduler. La captura de eventos, la selección de clics y la entrega automática **todavía no están disponibles**. La comprobación de conexión solicita un token OAuth; no crea identidades ni envía eventos.

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

Esta versión usa el esquema 4 y conserva cuatro opciones propias:

- `wp_druid_pulse_schema_version`: versión del esquema, con autoload.
- `wp_druid_pulse_view_selection`: selección de vistas, sin autoload.
- `wp_druid_pulse_installation_id`: UUID persistente, sin autoload.
- `wp_druid_pulse_connection`: conexión y secreto, sin autoload.

El token OAuth se conserva temporalmente en el transient `wp_druid_pulse_oauth_token`, ligado a las opciones de conexión y con caducidad. Cambiar los ajustes lo invalida; un token ausente o caducado se obtiene de nuevo cuando se solicita una operación. Los eventos se guardan en `<prefijo>druid_pulse_events`. Action Scheduler utiliza su infraestructura compartida y programa el mantenimiento propio del plugin.

Activación y actualización inicializan únicamente los recursos que faltan en esquemas anteriores y avanzan al esquema 4 después de comprobar la tabla y programar el mantenimiento. Actualizar desde 0.4.0 conserva selección, UUID y conexión; desde 0.3.0 conserva la selección; desde 0.1.0/0.2.0 también se inicializa la selección vacía si falta. Una actualización de un plugin activo se comprueba en `init`, después de inicializar Action Scheduler, sin necesitar una reactivación. No se rebajan esquemas más recientes ni se reinician datos incompatibles.

- **Desactivar/reactivar:** conserva selección, UUID, credenciales y eventos. Desactivar cancela las tareas propias pendientes; una ejecución ya iniciada puede terminar. Reactivar restablece el mantenimiento. La caducidad continúa durante la desactivación y los vencidos no se recuperan para envío.
- **Eliminar desde WordPress:** retira la tabla de eventos, las tareas y registros propios, las cuatro opciones y el transient. Conserva las tablas compartidas de Action Scheduler y las tareas de otros componentes. Un fallo de borrado interrumpe la desinstalación y permite reintentarlo.
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

Con `wp-druid` activo se consulta su API canónica y se envía únicamente `actor.externalIds["druid:objectid"]`. Si falta esa API o el identificador, se devuelve un error sin recurrir al perfil de WordPress. Sin esa integración se utilizan correo real, nombre y apellidos disponibles; no se añade teléfono ni se utilizan los correos técnicos conocidos como identidad. La falta de identificación devuelve un error, sin sustituir al usuario por su ID numérico de WordPress.

El cliente envía un evento a `POST /identities` y reconoce 200/201. Un 401 invalida el token; no se reenvía automáticamente el evento. Los fallos de transporte o respuestas inesperadas no se presentan como éxito. Estos servicios aún no se conectan a la navegación ni a un trabajador de entrega.

## Almacén de eventos

El almacén es interno: no existe todavía un endpoint que acepte eventos del navegador. Su llamador debe atribuir el usuario y validar el contexto en servidor. Cada registro contiene UUID local, versión del formato, tipo, fecha original en UTC, fecha de recepción/caducidad, ID WordPress del usuario y contexto específico del evento. Para vistas y clics, ese contexto conserva las referencias, URLs y títulos originales, incluido el destino conocido.

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

Este catálogo selecciona únicamente vistas; no determina dónde se podrán seleccionar clics. Guardar una selección no inicia captura ni envío de eventos.

## Verificación

La versión 0.5.0 se ha comprobado en un WordPress 6.9.4/PHP 8.3.31 aislado, con datos sintéticos y prefijo de tablas distinto de `wp_`. Se verificaron actualización activa desde 0.4.0, conservación de ajustes, preparación con usuario y conexión actuales, caducidad, topes reales con cuatro procesos concurrentes, fallos de persistencia/programación/retirada y convivencia con otra copia independiente de Action Scheduler 4.2.0. También se comprobaron los contratos de mapeo, OAuth con HTTP simulado y formularios por HTTP con permisos y nonces.

Las suites reproducibles están en [tests/README.md](tests/README.md). Las comprobaciones usan respuestas simuladas y una implementación de prueba de la consulta a `wp-druid`; no acreditan conectividad con un despliegue real de Pulse ni todas las combinaciones de plugins o cachés persistentes.

Queda pendiente la revisión visual de las pantallas en navegador. La versión 0.3.0 se comprobó funcionalmente para catálogo, paginación y selección; esta versión comprueba además que la pantalla de Tracking sigue accesible.

## Desarrollo y distribución

Repositorio: [dru-id/wp-druid-pulse](https://github.com/dru-id/wp-druid-pulse), rama `main`.

Mantener los archivos PHP con UTF-8 sin BOM y convenciones de WordPress.

El ZIP 0.5.0 incluye el archivo principal, `src/`, `templates/`, `assets/admin-tracking.js`, `libraries/` con sus licencias, `uninstall.php` y `readme.txt`, bajo `wp-druid-pulse/`.

La cabecera `Update URI: false` evita que WordPress.org sustituya este plugin de desarrollo por otro que tenga el mismo nombre de carpeta. No se ha añadido un servidor ni un mecanismo propio de actualizaciones.
