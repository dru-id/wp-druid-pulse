# DruID Pulse for WordPress

Plugin independiente para enviar a DruID Pulse actividad de consumidores autenticados del frontend de WordPress.

## Estado

Versión de desarrollo **0.2.0**. Permite instalar y activar el plugin, mantener sus metadatos de instalación y consultar su estado en la lista de plugins de WordPress.

La captura de eventos, las pantallas de configuración y el envío a Pulse **todavía no están disponibles**.

## Requisitos

- WordPress 6.9 o posterior.
- PHP 8.3 o posterior.

No requiere wp-druid, WooCommerce, Composer ni npm. Utiliza PHP y las APIs nativas de WordPress.

## Instalación

1. Utilizar una instalación de WordPress aislada, con versiones compatibles con las declaradas en la cabecera del plugin.
2. Copiar los archivos del plugin a `wp-content/plugins/wp-druid-pulse/`, con `wp-druid-pulse.php` en la raíz de esa carpeta.
3. Activar **DruID Pulse for WordPress** y comprobar el estado mostrado en la lista de plugins.

Si se dispone de un ZIP instalable, se puede subir desde **Plugins → Añadir nuevo plugin → Subir plugin**. Debe contener una única carpeta raíz `wp-druid-pulse/`. Los ZIP generados localmente no se incluyen en este repositorio.

## Ciclo de vida y datos propios

Esta versión guarda únicamente `wp_druid_pulse_schema_version`, con valor `1`, mediante Options API. Se carga automáticamente porque se consulta en cada petición. No crea tablas ni tareas y no envía peticiones a Pulse. La versión del esquema es independiente de la versión del paquete.

La activación inicializa el esquema y comprueba el resultado. La carga normal lo comprueba en `init`, también cuando se actualiza un plugin activo sin ejecutar otra activación. Pasar de 0.1.0 a 0.2.0 crea el metadato que antes no existía; cargar o reactivar un esquema válido no vuelve a escribirlo.

- **Desactivar/reactivar:** conserva los datos propios, sin reiniciarlos.
- **Eliminar desde WordPress:** `uninstall.php` borra únicamente la clave propia. Si el borrado falla, interrumpe la eliminación para permitir un reintento. Reinstalar comienza con datos nuevos.
- **Borrar archivos manualmente:** no ejecuta la desinstalación ni elimina la opción.

Una inicialización fallida impide completar la activación. Si el plugin ya estaba activo, conserva sus datos, mantiene sus funciones en pausa y muestra un aviso a usuarios con capacidad `activate_plugins`; vuelve a comprobarlo en peticiones posteriores. Un esquema más reciente o desconocido se conserva sin rebajarlo ni reiniciarlo: requiere restaurar una versión compatible o revisar los metadatos. Los avisos no muestran el valor almacenado ni detalles internos.

## Verificación

El código 0.2.0 se validó con WordPress 6.9.4, PHP 8.3.31 y MySQL 8.0.46: instalación, actualización desde 0.1.0, permisos, recuperación ante fallos de escritura y borrado, desactivación, desinstalación y reinstalación. Estas pruebas no acreditan todas las combinaciones de versiones ni cachés de objetos persistentes externas.

Comprobaciones para repetir la validación:

1. Instalar y activar el ZIP en un WordPress limpio: debe aparecer el plugin y su estado, sin errores PHP; el frontend debe seguir funcionando.
2. Actualizar una instalación activa de 0.1.0 subiendo el ZIP 0.2.0 y reemplazando el paquete: debe mantenerse activa y crear `wp_druid_pulse_schema_version=1` en la siguiente carga.
3. Desactivar/reactivar conserva esa opción. Eliminar desde WordPress la borra sin afectar a otros datos; reinstalar vuelve a crearla al activar. Comprobarla mediante Options API o una consulta de solo lectura en la base de datos de prueba.

Realizar estas comprobaciones en una instancia aislada de WordPress con datos de prueba.

## Desarrollo y distribución

Repositorio: [dru-id/wp-druid-pulse](https://github.com/dru-id/wp-druid-pulse), rama `main`.

Mantener los archivos PHP con UTF-8 sin BOM y convenciones de WordPress.

El ZIP 0.2.0 incluye únicamente el archivo principal, `src/Plugin.php`, `src/Lifecycle.php`, `uninstall.php` y `readme.txt`, bajo `wp-druid-pulse/`.

La cabecera `Update URI: false` evita que WordPress.org sustituya este plugin de desarrollo por otro que tenga el mismo nombre de carpeta. No se ha añadido un servidor ni un mecanismo propio de actualizaciones.
