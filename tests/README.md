# Pruebas en WordPress

Estas pruebas modifican opciones, usuarios, eventos y tareas, vacían la cola y ejecutan la desinstalación del plugin. Usar únicamente una instalación desechable, sin datos reales, con WordPress 6.9 o posterior y PHP 8.3 o posterior. No requieren un framework de pruebas ni acceso a Pulse. No ejecutar las suites en paralelo sobre la misma base de datos.

Instalar y activar el plugin de este checkout en esa instalación. Definir en su `wp-config.php`:

```php
define( 'WP_DRUID_PULSE_TESTING', true );
define( 'DISABLE_WP_CRON', true );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
```

Ejecutar desde este repositorio, con acceso a la base de datos de prueba:

```sh
WP_TEST_BOOTSTRAP=/ruta/wordpress/wp-load.php php tests/integration.php
WP_TEST_BOOTSTRAP=/ruta/wordpress/wp-load.php php tests/queue.php
WP_TEST_BOOTSTRAP=/ruta/wordpress/wp-load.php php tests/delivery.php
WP_TEST_BOOTSTRAP=/ruta/wordpress/wp-load.php php tests/queue-capacity.php
WP_TEST_BOOTSTRAP=/ruta/wordpress/wp-load.php php tests/lifecycle.php
```

`integration.php` intercepta las peticiones HTTP y comprueba los contratos del actor, mapeos, extensibilidad, OAuth, tokens, errores y migración. La API de `wp-druid` se representa con una implementación de prueba de su método de consulta: ejecutar esta suite sin `wp-druid` activo. No sustituye una prueba con el servicio real de Identity/Pulse.

`queue.php` comprueba contexto/fecha originales, resolución actual del actor sin sesión, cambios de conexión e instalación, extensibilidad, errores, bloqueo de inserción, caducidad y ejecución real del mantenimiento mediante Action Scheduler. El HTTP a Pulse se simula. Ejecutar sin `wp-druid` activo: incluye la misma implementación de prueba de su consulta.

`delivery.php` recorre el trabajador real y Action Scheduler con HTTP simulado: aceptación/borrado, identidad actual, backoff, Retry-After, 401, conflictos, respuestas ambiguas, cambio concurrente de conexión, fallo de persistencia, caducidad y continuación. Usa `proc_open` y la fixture CLI `fixtures/delivery-worker.php` para interrumpir realmente un proceso y ejecutar cuatro trabajadores concurrentes. La fixture solo utiliza datos sintéticos; no se copia a MU plugins.

`queue-capacity.php` llena la tabla hasta **10.000 registros y 100 MiB de JSON**, con datos sintéticos y cuatro procesos PHP simultáneos. Requiere `proc_open` disponible y espacio adicional para índices y registros de MySQL. No reduce los topes para probarlos. Al terminar elimina sus datos y usuario de prueba.

`lifecycle.php` comprueba conservación, UUID, cancelación/reactivación, tabla ausente, fallos de creación/programación/borrado, limpieza exacta y reinstalación. Incluye tareas ajenas con el mismo hook u otro hook dentro del mismo grupo para verificar que se conservan. Al terminar deja el plugin activo con configuración vacía. La caché se vacía durante esta prueba para representar el reintento en una petición nueva.

Para verificar la convivencia del cargador, instalar adicionalmente el ZIP oficial de Action Scheduler 4.2.0 como plugin independiente. Cargar WordPress en una petición nueva con ambos activos, comprobar una única inicialización de AS y una única tarea de mantenimiento de DruID Pulse, y repetir desactivación/desinstalación conservando una tarea y sus registros del otro componente. Conservar sus tablas compartidas. Retirar la copia adicional y comprobar en otra petición que carga la biblioteca incluida. El caso de desinstalación sin ninguna otra copia debe ejecutarse también en una petición nueva con DruID Pulse ya desactivado.

Para comprobar formularios y permisos por HTTP, crear en el WordPress de prueba un administrador y un suscriptor, copiar `fixtures/http.php` a su directorio `wp-content/mu-plugins/` y preparar un archivo JSON local:

```json
{
  "admin_user": "administrador_de_prueba",
  "admin_pass": "contraseña_de_prueba",
  "subscriber_user": "suscriptor_de_prueba",
  "subscriber_pass": "otra_contraseña_de_prueba"
}
```

Crear también una página publicada titulada `Selected before upgrade`, usada para comprobar que sigue disponible el catálogo existente. La URL pública del WordPress de prueba debe ser accesible desde el proceso PHP. La prueba necesita las extensiones cURL y DOM.

```sh
WP_TEST_BOOTSTRAP=/ruta/wordpress/wp-load.php WP_TEST_CREDENTIALS=/ruta/credenciales-de-prueba.json php tests/admin-http.php
```

Para el receptor, copiar además `fixtures/receiver.php` y `fixtures/views.php` al directorio de MU plugins y ejecutar:

```sh
WP_TEST_BOOTSTRAP=/ruta/wordpress/wp-load.php WP_TEST_CREDENTIALS=/ruta/credenciales-de-prueba.json php tests/receiver-http.php
node tests/frontend-transport.cjs
node tests/tracker.cjs
node tests/clicks-browser.cjs
```

`receiver-http.php` usa sesiones HTTP reales de administrador y suscriptor, extrae el nonce del frontend y recorre REST → almacén → preparación para un tipo sintético. Comprueba identidad en servidor, cambios/caducidad de sesión, rechazo de contraseñas de aplicación, CORS limitado a esta ruta, campos/tipos/fechas/tamaños inválidos, error de escritura, saturación simulada y bloqueo real de la cola. Comprueba también que frontend anónimo y administración no cargan el transporte y que una petición HTML sin ejecutar JavaScript no genera eventos. La fixture registra únicamente tipos de prueba, habilita contraseñas de aplicación en el entorno local e inyecta errores controlados de SQL; no usarla fuera de la instalación desechable. La suite retira sus eventos, contraseña de aplicación y archivos de cookies al terminar.

La misma suite ejecuta `page-views-http.php` para comprobar selección vacía/activa, portada/blog y su paginación, tipos públicos, exclusiones, contraseña nativa, enlaces simples y permanentes, campos manipulados y cambios antes/después de recepción. La fixture de vistas registra tipos de contenido sintéticos y permite una respuesta HTTP 503 de prueba. Crea y elimina sus contenidos y eventos y restaura las opciones originales.

La suite HTTP incluye `clicks-http.php`: pantalla y guardado por administrador, rechazo a suscriptores/nonce inválido/formularios incompletos, ámbito por ID con enlaces simples, independencia de vistas, destinos HTTP/tel/mailto, campos no admitidos, fallo de escritura, contexto conservado y preparación del evento.

`clicks-browser.cjs` requiere Chrome local (`CHROME=/ruta/al/binario` si no está en `/usr/bin/google-chrome`). Usa un perfil temporal propio, un servidor local y entrada real del navegador; comprueba ratón, teclado, toque, botón central, reglas solapadas, exclusiones, elementos dinámicos, inicio/parada y ausencia de peticiones del SDK. Retira su perfil al terminar. Opcionalmente, `WP_TEST_URL`, `WP_TEST_CREDENTIALS` y `WP_TEST_PAGE` permiten recorrer el editor y la captura del adaptador en el WordPress desechable; esto reemplaza sus reglas de clics. La página sintética debe contener `#phase8-link` (enlace a `/destination` con un `span`), `#phase8-button`, `#phase8-phone` (teléfono) y `#phase8-mail` (correo). `WP_TEST_SCREENSHOT` y `WP_TEST_RECEIPTS` guardan evidencias locales; contrastar los IDs aceptados con las filas y el actor del usuario de prueba. No usar estas variables con una web real.

`tracker.cjs` ejecuta el SDK y el adaptador con módulos nativos de Node.js y superficies de navegador simuladas. Comprueba visibilidad/preparación, historial, prerender, filtros declarativos de páginas (rutas exactas, exclusiones, listas vacías y configuración inválida), inicio/parada, carga repetida, eventos adicionales, errores y conservación de la pausa de sesión. Abrir `examples/tracker.html` permite comprobar el consumidor independiente en un navegador real, sin PHP. Verificar además una pestaña abierta en segundo plano, cambios de pestaña, recarga y retorno real desde BFCache; una notificación simulada no demuestra que el navegador haya restaurado su caché. Para WordPress, verificar el recorrido completo hasta la fila persistida con sesiones de administrador/suscriptor.

`frontend-transport.cjs` usa únicamente módulos nativos de Node.js, sin npm ni framework. Comprueba la petición, límites UTF-8, confirmaciones, resultados ambiguos, ausencia de reintentos, pausa de sesión y respuestas concurrentes. El entorno JavaScript simulado no demuestra comportamiento real al navegar: comprobar también con un navegador que una navegación prosigue mientras queda pendiente una respuesta y que el receptor recibe la petición con cookie/nonce. La pérdida de conexión o el cierre del navegador siguen pudiendo perder eventos; `keepalive` no garantiza entrega.

El simulador responde únicamente a la URL ficticia de OAuth, bloquea las demás solicitudes de la HTTP API y deshabilita el ejecutor asíncrono de Action Scheduler para controlar las ejecuciones en las pruebas. Copiar el archivo al directorio de MU plugins, con permisos de lectura para PHP. Las credenciales del archivo son exclusivamente de prueba; no añadirlo al repositorio. Retirar el simulador, las credenciales y la instalación desechable al terminar. Estos archivos no se incluyen en el ZIP del plugin.

`diagnostics.php` necesita las mismas credenciales y fixture HTTP que `admin-http.php`. Ejecutar con `WP_TEST_BOOTSTRAP` y, si procede, `WP_TEST_CREDENTIALS`. Comprueba metadatos y privacidad, contadores globales/pausa, filtros y paginación, permisos/nonces/métodos, programación sin envío, limpieza, reintentos con conservación de datos, selecciones antiguas y dos POST simultáneos. Simula esquema incompatible, estado corrupto y tabla ausente para verificar consulta y bloqueo de acciones, además de fallos SQL, pérdida de bloqueo y reencolado correcto con fallo posterior de programación. No ejecutar sobre datos reales: vacía la cola y modifica temporalmente el esquema de pruebas.

`admin-ux.php` usa las mismas credenciales para comprobar el buscador privado de páginas, permisos/nonces, paginación, referencias no disponibles y el guardado JSON de 220 reglas sin truncamiento por `max_input_vars`. Restaura las reglas originales y elimina sus contenidos al terminar.

`admin-ux-browser.cjs` comprueba el editor con Chrome: búsqueda y selección entre páginas, cancelación, presets, errores en filas ocultas, guardado desde la última página sin perder cambios, tamaño móvil y captura real del clic configurado. Requiere las fixtures HTTP anteriores y una instalación **desechable** con `WP_DRUID_PULSE_TESTING=true`. Preparar 25 páginas y 22 reglas sintéticas, ejecutar el navegador, contrastar lo guardado y restaurar la fixture, también si falla la prueba:

```sh
WP_TEST_BOOTSTRAP=/ruta/wordpress/wp-load.php php tests/fixtures/admin-ux.php seed
WP_TEST_URL=http://127.0.0.1:18841 WP_TEST_CREDENTIALS=/ruta/credenciales-de-prueba.json node tests/admin-ux-browser.cjs
WP_TEST_BOOTSTRAP=/ruta/wordpress/wp-load.php php tests/fixtures/admin-ux.php verify
WP_TEST_BOOTSTRAP=/ruta/wordpress/wp-load.php php tests/fixtures/admin-ux.php restore
```

`WP_TEST_SCREENSHOT_DIR` permite guardar capturas en un directorio local existente. La fixture `admin-ux.php` es un script CLI; no se copia a MU plugins. Las pruebas no acceden a Pulse ni deben ejecutarse sobre la demo manual o un sitio real.
