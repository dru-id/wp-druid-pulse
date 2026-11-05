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
WP_TEST_BOOTSTRAP=/ruta/wordpress/wp-load.php php tests/queue-capacity.php
WP_TEST_BOOTSTRAP=/ruta/wordpress/wp-load.php php tests/lifecycle.php
```

`integration.php` intercepta las peticiones HTTP y comprueba los contratos del actor, mapeos, extensibilidad, OAuth, tokens, errores y migración. La API de `wp-druid` se representa con una implementación de prueba de su método de consulta: ejecutar esta suite sin `wp-druid` activo. No sustituye una prueba con el servicio real de Identity/Pulse.

`queue.php` comprueba contexto/fecha originales, resolución actual del actor sin sesión, cambios de conexión e instalación, extensibilidad, errores, bloqueo de inserción, caducidad y ejecución real del mantenimiento mediante Action Scheduler. El HTTP a Pulse se simula. Ejecutar sin `wp-druid` activo: incluye la misma implementación de prueba de su consulta.

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

Para el receptor, copiar además `fixtures/receiver.php` al directorio de MU plugins y ejecutar:

```sh
WP_TEST_BOOTSTRAP=/ruta/wordpress/wp-load.php WP_TEST_CREDENTIALS=/ruta/credenciales-de-prueba.json php tests/receiver-http.php
node tests/frontend-transport.cjs
```

`receiver-http.php` usa sesiones HTTP reales de administrador y suscriptor, extrae el nonce del frontend y recorre REST → almacén → preparación para un tipo sintético. Comprueba identidad en servidor, cambios/caducidad de sesión, rechazo de contraseñas de aplicación, CORS limitado a esta ruta, campos/tipos/fechas/tamaños inválidos, error de escritura, saturación simulada y bloqueo real de la cola. Comprueba también que frontend anónimo y administración no cargan el transporte y que cargar una página no genera eventos. La fixture registra únicamente tipos de prueba, habilita contraseñas de aplicación en el entorno local e inyecta errores controlados de SQL; no usarla fuera de la instalación desechable. La suite retira sus eventos, contraseña de aplicación y archivos de cookies al terminar.

`frontend-transport.cjs` usa únicamente módulos nativos de Node.js, sin npm ni framework. Comprueba la petición, límites UTF-8, confirmaciones, resultados ambiguos, ausencia de reintentos, pausa de sesión y respuestas concurrentes. El entorno JavaScript simulado no demuestra comportamiento real al navegar: comprobar también con un navegador que una navegación prosigue mientras queda pendiente una respuesta y que el receptor recibe la petición con cookie/nonce. La pérdida de conexión o el cierre del navegador siguen pudiendo perder eventos; `keepalive` no garantiza entrega.

El simulador responde únicamente a la URL ficticia de OAuth, bloquea las demás solicitudes de la HTTP API y deshabilita el ejecutor asíncrono de Action Scheduler para controlar las ejecuciones en las pruebas. Copiar el archivo al directorio de MU plugins, con permisos de lectura para PHP. Las credenciales del archivo son exclusivamente de prueba; no añadirlo al repositorio. Retirar el simulador, las credenciales y la instalación desechable al terminar. Estos archivos no se incluyen en el ZIP del plugin.
