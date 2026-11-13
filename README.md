# DruID Pulse for WordPress

Registra páginas vistas y clics de usuarios con sesión iniciada en WordPress y envía esos eventos a DruID Pulse. El integrador elige las páginas, enlaces y botones desde el panel de administración, sin añadir JavaScript al tema.

El navegador recoge la actividad y la entrega a WordPress. El plugin identifica al usuario y envía los eventos a Pulse en segundo plano con las credenciales de la aplicación. **No registra visitantes anónimos y empieza sin páginas ni clics seleccionados.**

Versión de desarrollo: **0.10.1**.

[Instalación](#instalación) · [Conexión](#conectar-con-pulse) · [Páginas vistas](#seleccionar-páginas-vistas) · [Clics](#seleccionar-clics) · [Comprobación](#comprobar-la-integración) · [Diagnóstico](#diagnóstico-y-resolución-de-problemas)

## Antes de empezar

Necesitas:

- **WordPress 6.9 o posterior y PHP 8.3 o posterior**, en una instalación individual. Multisite no está soportado.
- Una cuenta con permiso para administrar los ajustes de WordPress.
- Las URLs HTTPS de Pulse y del servidor de autorización, y un **cliente OAuth2 autorizado para enviar eventos** mediante `client_credentials`.
- Usuarios que inicien sesión en WordPress. El plugin puede funcionar con `wp-druid` o con los perfiles de usuario de WordPress.
- WP-Cron o un ejecutor de Action Scheduler funcionando para entregar eventos y limpiar los caducados, y conectividad HTTPS desde el servidor hacia los servicios configurados.

No requiere instalar `wp-druid`, WooCommerce, Composer ni npm. Incluye [Action Scheduler y sus licencias](libraries/README.md).

## Instalación

1. Si dispones del ZIP instalable, abre **Plugins → Añadir nuevo plugin → Subir plugin** y súbelo.
2. Instala y activa **DruID Pulse for WordPress**.
3. Abre **DruID Pulse → Connection** para configurar la conexión antes de seleccionar qué quieres registrar.

Si partes del código del repositorio, crea la carpeta `wp-content/plugins/wp-druid-pulse/` y copia en ella `wp-druid-pulse.php`, `uninstall.php`, `readme.txt` y los directorios `src/`, `templates/`, `assets/` y `libraries/`. El archivo `wp-druid-pulse.php` debe quedar directamente dentro de esa carpeta. Después, activa el plugin desde WordPress; no hay pasos de compilación. Conserva las licencias incluidas en `libraries/`.

## Conectar con Pulse

En **DruID Pulse → Connection**, completa los datos facilitados para tu entorno:

| Campo | Qué introducir |
| --- | --- |
| **Pulse API base URL** | URL HTTPS base de la API, sin añadir `/identities`. |
| **Authorization server base URL** | URL HTTPS base del servidor de autorización, sin añadir `/oauth2/token`. |
| **Client ID** | Identificador del cliente OAuth de la aplicación. |
| **Client secret** | Secreto de ese cliente. |
| **Client authentication** | `client_secret_basic` o `client_secret_post`, según el método habilitado para el cliente. |
| **OAuth scopes (optional)** | Los scopes indicados para ese cliente. Déjalo vacío si no se requieren. |
| **Pulse tenant (optional)** | El tenant del destino, solo si tu conexión lo requiere. |

Pulsa **Save connection** y después **Test OAuth authentication**. La prueba utiliza los ajustes guardados y solicita un token: **no envía eventos ni confirma que Pulse los vaya a aceptar**.

El secreto guardado no se muestra de nuevo. Dejar su campo vacío lo conserva; escribir otro lo sustituye. Para eliminarlo, marca **Remove saved secret** y deja el campo vacío. Las credenciales y los tokens de Pulse permanecen en el servidor WordPress.

**Site shown in Pulse** muestra el nombre y la dirección del sitio usados para identificar la aplicación. Se toman de **Ajustes → Generales**.

## Seleccionar páginas vistas

1. Abre **DruID Pulse → Page views**.
2. Busca el contenido por título o utiliza los filtros de tipo y selección.
3. Marca las filas que quieras registrar y pulsa **Save selection**.

El catálogo muestra **20 contenidos por página**. Guarda antes de cambiar de página o filtros: **Save selection** modifica únicamente las filas visibles y conserva lo que ya habías guardado en otras páginas.

Puedes seleccionar páginas, entradas y contenidos publicados de tipos públicos, incluidos productos si existen en la instalación. También aparecen la portada y la página de entradas. No se incluyen categorías, etiquetas, búsquedas, otros archivos ni adjuntos.

- Las selecciones siguen asociadas al contenido aunque cambie su título o URL.
- Los contenidos nuevos y las páginas descendientes no se seleccionan automáticamente. Cambiar la página asignada como portada tampoco selecciona la nueva.
- **Unavailable selections** permite retirar referencias a contenidos que ya no están disponibles. Si vuelven a estarlo con el mismo ID, recuperan su selección.

La captura empieza en la siguiente carga de una página seleccionada con sesión iniciada. Cuenta una vista cuando el documento está preparado y visible. Recargar o volver mediante Atrás/Adelante cuenta otra vista; cambiar de pestaña y regresar no.

**La selección de vistas y las reglas de clics son independientes.** Puedes registrar clics en una página sin registrar sus vistas, y viceversa.

## Seleccionar clics

### Todos los enlaces y botones de una página

1. Abre **DruID Pulse → Clicks** y pulsa **Add rule**.
2. Mantén **Track** y **All links and buttons**.
3. En **Selected pages**, pulsa **Choose pages…**, busca por título y marca las páginas. Confirma con **Use selected pages**.
4. Pulsa **Save click rules** y recarga la página del sitio donde vayas a probarlo.

No necesitas conocer IDs ni añadir una clase CSS. El selector muestra el título, el tipo y la URL del contenido. Para aplicar una regla a todo el frontend compatible, elige **Entire website** en lugar de **Selected pages**.

### Enlaces o botones concretos

Puedes elegir **All links**, **All buttons** o **CSS selector**. Estos son ejemplos de selectores que puedes adaptar al HTML de tu web:

| Qué quieres registrar | Selector CSS |
| --- | --- |
| Los enlaces de un menú con ID `main-menu` | `#main-menu a[href]` |
| Los enlaces y botones dentro de un bloque con clase `promociones` | `.promociones a[href], .promociones button` |
| Un enlace o botón con ID `comprar` | `a#comprar, button#comprar` |
| Enlaces o botones con clase `cta` | `a.cta, button.cta` |

El selector debe coincidir con el **enlace o botón**, no solo con el contenedor. Un elemento que visualmente parezca un botón debe ser un `<button>` o un enlace `<a href="…">` para esta captura automática.

**Filter by class prefix** es opcional. Por ejemplo, con **All links and buttons** y el prefijo `promo-`, coinciden elementos con clases como `promo-verano` o `promo-compra`. El prefijo se comprueba en las clases del propio enlace o botón.

### Exclusiones y guardado

Añade una regla **Exclude** para omitir elementos, por ejemplo con el selector `.sin-tracking` cuando esa clase esté en los enlaces o botones que quieras excluir. Las exclusiones prevalecen sobre **Track**. Varias reglas que coincidan con la misma activación no generan varios eventos.

El editor muestra **20 reglas por página** y conserva los cambios al paginar; **Save click rules** guarda el conjunto completo. El selector de contenidos también pagina de 20 en 20 y conserva las marcas entre búsquedas. Las referencias no disponibles se mantienen visibles para que puedas retirarlas.

Se capturan activaciones reales de ratón, teclado nativo y pantalla táctil, incluidos elementos añadidos después de cargar la página. El botón central cuenta en enlaces. No se registra clic derecho ni activaciones generadas por código, ni se bloquea la navegación.

## Usuarios y datos registrados

Se registra actividad de usuarios con sesión iniciada en WordPress, incluidos administradores cuando navegan por el sitio. El plugin no inicia sesión por ellos ni registra visitantes anónimos.

- **Con `wp-druid`:** el usuario debe estar asociado a una identidad DruID válida.
- **Sin `wp-druid`:** el usuario debe tener un correo real y válido en su perfil de WordPress. Se envían también su nombre y apellidos si están disponibles.

Si no se puede identificar al usuario, sus eventos no se envían a Pulse. Diagnostics permite detectar estos casos y revisar la asociación de identidad o el perfil.

Se registran las páginas seleccionadas, las etiquetas de los enlaces y botones incluidos en las reglas y los destinos de los enlaces cuando están disponibles. No se capturan valores de formularios ni el teléfono del perfil. Ten en cuenta que las etiquetas y las URLs seleccionadas pueden contener información personal, como correos o teléfonos.

## Comprobar la integración

Haz la primera comprobación con un usuario y contenidos de prueba en el entorno de Pulse que hayas configurado: este recorrido **sí envía eventos**.

1. Guarda la conexión y comprueba **Test OAuth authentication**.
2. Selecciona una página para vistas y añade una regla de clics para esa página.
3. Inicia sesión en WordPress con el usuario de prueba. Si usas `wp-druid`, comprueba que esté asociado a una identidad DruID.
4. Abre la página publicada del sitio, fuera del editor o la previsualización, y activa un enlace o botón incluido en la regla.
5. Abre **DruID Pulse → Diagnostics** y revisa la cola y los posibles errores. **Process pending** programa el envío de los eventos elegibles, respetando las esperas de reintento.
6. Comprueba en Pulse que aparecen la vista y el clic del usuario de prueba, asociados al sitio correcto.

Los eventos pueden entregarse antes de que abras Diagnostics. **Una cola vacía no demuestra por sí sola que se hayan capturado o entregado eventos**, porque tampoco es un histórico. Del mismo modo, una tarea completada en Action Scheduler puede haber programado un reintento.

## Diagnóstico y resolución de problemas

**DruID Pulse → Diagnostics** muestra configuración, estado de entrega y eventos retenidos. El listado se filtra por estado y tipo, con **25 registros por página**. No muestra perfiles de usuario ni el contenido de los eventos.

| Situación | Qué revisar o hacer |
| --- | --- |
| No se capturan vistas o clics | Sesión WordPress iniciada, JavaScript habilitado, selección o reglas guardadas y página recargada. Usa el sitio publicado, no el editor ni una previsualización. |
| Un clic no se registra | Comprueba que el selector coincida con un enlace o botón real, el ámbito de páginas y las exclusiones. |
| El seguimiento deja de funcionar en una pestaña antigua | Recarga e inicia sesión de nuevo si es necesario. Comprueba también que la caché no comparta páginas de usuarios autenticados. |
| **Ready to send** permanece sin procesarse | Revisa las tareas y su ejecutor en **Technical details and scheduled tasks**. No basta con que existan tareas programadas: WP-Cron o el ejecutor de Action Scheduler deben ejecutarlas. |
| **Waiting to retry** o entrega pausada | Consulta el último error y la fecha de próximo intento. Corrige conexión, permisos o identidad según el mensaje; los reintentos automáticos respetan esa espera. |
| **Failed** | Corrige la causa y utiliza **Retry selected failed events** sobre los fallidos seleccionados que no hayan caducado. |
| **Conflict · 409** | Revisa el conflicto en Pulse. No confirma aceptación y no se puede reintentar manualmente desde el plugin. |
| **Expired** | Han pasado siete días desde la recepción local. Ya no se enviará; **Clean expired** permite retirarlo. |
| **Delivered · awaiting cleanup** | Pulse confirmó aceptación; falta el borrado local. El mantenimiento lo retira sin reenviar. |

**Process pending** no fuerza eventos en espera ni evita una pausa. **Clean expired** conserva pendientes vigentes. El reintento manual mantiene la fecha de caducidad original. Si corriges la conexión, pulsa **Save connection**: guardar despeja la pausa común, pero se siguen respetando las fechas de reintento de cada evento.

Si pides soporte, indica la versión, el estado y el mensaje de error de Diagnostics. No compartas secretos, tokens ni datos personales de los eventos.

## Entrega, conservación y privacidad

- El envío es asíncrono. Action Scheduler comprueba pendientes cada minuto, sujeto a la ejecución de tareas del servidor; no implica entrega inmediata.
- Los errores temporales se reintentan tras 1, 5, 15 y 60 minutos y después cada hora. Una espera mayor indicada por el servicio se respeta.
- Los eventos caducan a los **7 días desde su recepción en WordPress**, también si el plugin está desactivado. Los confirmados por Pulse se eliminan de la cola local.
- La cola admite **10.000 eventos o 100 MiB de datos de contexto**, lo que se alcance antes. Cuando está llena, rechaza nuevos eventos sin expulsar los ya guardados. La limpieza periódica retira los caducados.
- Si cambias la conexión mientras hay eventos pendientes, estos se enviarán al nuevo destino. Los cambios en el perfil del usuario también se reflejan al enviar.
- El secreto OAuth se guarda **sin cifrar en la base de datos y sus copias de seguridad**. El plugin no lo devuelve en los formularios ni lo entrega al navegador.
- Las páginas autenticadas y su configuración deben quedar fuera de cachés compartidas. El plugin no añade un gestor de consentimiento; configura el seguimiento conforme a la política de datos de tu sitio.

El cierre del navegador o una pérdida de conexión pueden impedir que se registre una interacción. Ante errores de comunicación con Pulse también pueden producirse duplicados.

## Actualizar, desactivar o eliminar

- **Actualizar:** sustituye el plugin por el nuevo paquete mediante WordPress. Las actualizaciones compatibles conservan ajustes y eventos. Este plugin no tiene un servicio propio de actualizaciones automáticas ni se actualiza desde WordPress.org.
- **Desactivar:** conserva ajustes, credenciales y eventos, y cancela sus tareas pendientes. Una ejecución ya iniciada puede terminar. Al reactivar se recuperan las tareas, sin reiniciar la caducidad de los eventos.
- **Eliminar desde WordPress:** borra la configuración, credenciales, cola y tareas propias. Conserva los recursos compartidos y las tareas de otros plugins. Reinstalar después empieza con configuración vacía.

Borrar los archivos manualmente no ejecuta la limpieza de datos. Si duplicas el sitio para crear una web independiente, revisa la conexión y sigue el [procedimiento de clonación](docs/technical-reference.md#clonar-una-instalación) antes de activar el seguimiento en la copia.

## Compatibilidad y documentación adicional

La captura automática requiere una sesión WordPress y JavaScript. No registra actividad en administración, previsualizaciones ni contenido cuya contraseña nativa siga pendiente. No detecta cambios de ruta SPA/AJAX ni interacciones dentro de iframes; la configuración de un gestor de acceso externo puede necesitar comprobaciones adicionales.

La versión 0.10.1 se ha validado en un entorno aislado con WordPress 6.9.4, PHP 8.3.31, MySQL 8.0 y Chrome 150. **La entrega se ha probado con respuestas HTTP simuladas, no contra un despliegue real de Pulse.** Comprueba el recorrido completo con tu destino y con el tema, caché y plugins de tu instalación antes de darla por validada.

- [Referencia para desarrollo](docs/technical-reference.md): extensiones y contratos técnicos, si necesitas ampliar o mantener el plugin.
- [Ejemplo del SDK independiente](examples/tracker.html): captura en el navegador sin WordPress ni envío a Pulse. El plugin ya carga y configura el SDK; no necesitas añadir este ejemplo a tu web.
- [Pruebas en WordPress](tests/README.md): requisitos y ejecución en entornos desechables.
- [Bibliotecas incluidas](libraries/README.md) y [registro de cambios](readme.txt).
