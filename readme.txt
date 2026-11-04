=== DruID Pulse for WordPress ===
Requires at least: 6.9
Requires PHP: 8.3
Stable tag: 0.2.0

Base instalable del plugin independiente de actividad autenticada para DruID Pulse.

== Descripción ==

Versión de desarrollo 0.2.0.

Permite instalar y activar la base del plugin y consultar su estado en la lista
de plugins de WordPress. La captura de eventos y el envío a Pulse todavía no
están implementados.

No requiere wp-druid ni WooCommerce. Guarda únicamente una opción con la versión
del esquema de instalación; no crea tablas, programa tareas ni conecta a Pulse.
La configuración funcional todavía no está disponible.

Los mínimos de compatibilidad acordados son WordPress 6.9 y PHP 8.3.

== Instalación ==

1. Utilizar una instalación de WordPress de desarrollo compatible.
2. Subir el ZIP desde Plugins > Añadir nuevo plugin > Subir plugin.
3. Instalar y activar DruID Pulse for WordPress.
4. Comprobar el estado del plugin y que el frontend siga funcionando.

WordPress comprueba las versiones requeridas antes de permitir la activación.

== Desactivación y eliminación ==

Desactivar conserva los datos propios y reactivar no los reinicia.
Eliminar desde la pantalla de plugins de WordPress borra la opción propia
wp_druid_pulse_schema_version. Una reinstalación comienza con datos nuevos.
Si no se puede borrar esa opción, la eliminación se interrumpe para poder
reintentarlo. Borrar archivos manualmente no ejecuta esta limpieza.

== Actualizaciones ==

Este paquete no se actualiza desde el directorio de plugins de WordPress.org.
Las siguientes versiones de desarrollo se distribuirán como paquetes ZIP.
La versión del esquema se comprueba en cada carga, también al actualizar un
plugin activo sin volver a activarlo. La actualización desde 0.1.0 crea la
opción propia sin reiniciar otros datos.

Si la inicialización falla, la activación se interrumpe. Si el plugin ya estaba
activo, conserva los datos y deja sus funciones en pausa; los usuarios con
capacidad para activar plugins reciben un aviso en administración. Se reintenta
en peticiones posteriores. Nunca se reemplaza automáticamente un esquema más
reciente o desconocido: hay que restaurar una versión compatible o revisar los
metadatos de instalación.

== Registro de cambios ==

= 0.2.0 =

* Inicialización idempotente y comprobación de esquema al activar y actualizar.
* Conservación al desactivar y limpieza de datos propios al desinstalar.
* Fallos explícitos, aviso administrativo y reintento en cargas posteriores.

= 0.1.0 =

* Base instalable independiente y estado informativo en la lista de plugins.
