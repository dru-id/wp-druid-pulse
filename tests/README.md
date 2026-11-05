# Pruebas en WordPress

Estas pruebas modifican opciones y usuarios y ejecutan la desinstalación del plugin. Usar únicamente una instalación desechable, sin datos reales, con WordPress 6.9 o posterior y PHP 8.3 o posterior. No requieren un framework de pruebas ni acceso a Pulse.

Instalar y activar el plugin de este checkout en esa instalación. Definir en su `wp-config.php`:

```php
define( 'WP_DRUID_PULSE_TESTING', true );
define( 'DISABLE_WP_CRON', true );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
```

Ejecutar desde este repositorio, con acceso a la base de datos de prueba:

```sh
WP_TEST_BOOTSTRAP=/ruta/wordpress/wp-load.php php tests/integration.php
WP_TEST_BOOTSTRAP=/ruta/wordpress/wp-load.php php tests/lifecycle.php
```

`integration.php` intercepta las peticiones HTTP y comprueba los contratos del actor, mapeos, extensibilidad, OAuth, tokens, errores y migración. La API de `wp-druid` se representa con una implementación de prueba de su método de consulta: ejecutar esta suite sin `wp-druid` activo. No sustituye una prueba con el servicio real de Identity/Pulse.

`lifecycle.php` comprueba conservación, UUID, fallo de desinstalación, limpieza exacta y reinstalación. Al terminar deja el plugin activo con configuración vacía. La caché se vacía durante esta prueba para representar el reintento en una petición nueva.

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

El simulador responde únicamente a la URL ficticia de OAuth y bloquea las demás solicitudes de la HTTP API. Las credenciales del archivo son exclusivamente de prueba; no añadirlo al repositorio. Retirar el simulador, las credenciales y la instalación desechable al terminar. Estos archivos no se incluyen en el ZIP del plugin.
