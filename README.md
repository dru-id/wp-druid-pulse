# DruID Pulse for WordPress

Plugin independiente para enviar a DruID Pulse actividad de consumidores autenticados del frontend de WordPress.

## Estado

Versión de desarrollo **0.3.0**. Permite configurar y guardar la selección de contenidos para páginas vistas desde **DruID Pulse → Tracking**.

La captura de eventos, la selección de clics y el envío a Pulse **todavía no están disponibles**.

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

Esta versión guarda `wp_druid_pulse_schema_version=2` y `wp_druid_pulse_view_selection` mediante Options API. El esquema se carga automáticamente; la selección no utiliza autoload. No crea tablas ni tareas y no envía peticiones a Pulse. La versión del esquema es independiente de la versión del paquete.

La activación inicializa el esquema y comprueba el resultado. La carga normal lo comprueba en `init`, también cuando se actualiza un plugin activo sin ejecutar otra activación. Al actualizar desde 0.1.0 o 0.2.0, crea una selección vacía si no existe y avanza al esquema 2 después de comprobarla; no reinicia una selección existente. Cargar o reactivar un esquema válido no vuelve a escribirlo.

- **Desactivar/reactivar:** conserva los datos propios, sin reiniciarlos.
- **Eliminar desde WordPress:** `uninstall.php` borra únicamente ambas claves propias. Si un borrado falla, interrumpe la eliminación; un reintento limpia los datos restantes. Reinstalar comienza con datos nuevos.
- **Borrar archivos manualmente:** no ejecuta la desinstalación ni elimina las opciones.

Una inicialización fallida impide completar la activación. Si el plugin ya estaba activo, conserva sus datos, mantiene sus funciones en pausa y muestra un aviso a usuarios con capacidad `activate_plugins`; vuelve a comprobarlo en peticiones posteriores. Un esquema más reciente o desconocido se conserva sin rebajarlo ni reiniciarlo: requiere restaurar una versión compatible o revisar los metadatos. Los errores de lectura o guardado de la selección se muestran sin reiniciarla. Los avisos no muestran datos internos.

## Configurar vistas

La pantalla **DruID Pulse → Tracking** requiere `manage_options`, igual que los ajustes generales de WordPress. Los formularios comprueban además un nonce.

- La selección inicial está vacía. El catálogo incluye páginas, entradas y contenidos publicados de tipos públicos visibles en el frontend; excluye adjuntos, contenidos no publicados y otros archivos o rutas especiales.
- La portada estática y la página de entradas se identifican en su propia fila. La portada de últimas entradas tiene una fila específica. Cambiar la asignación de portada o blog no selecciona automáticamente otro contenido.
- La búsqueda por título, los filtros y la paginación permiten localizar contenidos. **Save selection** modifica únicamente las filas visibles y conserva las selecciones de otras páginas. Se avisa antes de abandonar cambios sin guardar.
- Las selecciones siguen el ID, por lo que sobreviven a cambios de título o URL. Los contenidos nuevos y los descendientes quedan desmarcados.
- **Unavailable selections** muestra las referencias seleccionadas que dejaron de estar disponibles y permite retirarlas. Si el contenido vuelve a estar disponible con el mismo ID, conserva la selección.

Este catálogo selecciona únicamente vistas; no determina dónde se podrán seleccionar clics. Guardar una selección no inicia captura ni envío de eventos.

## Verificación

La versión 0.3.0 se validó en WordPress 6.9.4, PHP 8.3.31 y MySQL 8.0.46 con datos sintéticos y un prefijo de tablas distinto de `wp_`. Se comprobaron la instalación del ZIP, actualización activa desde 0.2.0, catálogo y guardado por HTTP, permisos y nonces, conservación entre páginas, cambios de portada, referencias no disponibles y recuperación ante fallos de escritura y borrado. Desactivar conserva la selección; la limpieza del plugin se limita a sus dos opciones, sin borrar tablas ni tareas programadas.

La sintaxis PHP y JavaScript y la lógica del aviso de cambios pendientes pasaron sus comprobaciones. Queda pendiente la revisión visual en navegador. Estas pruebas no acreditan todas las combinaciones de versiones ni cachés de objetos persistentes externas.

Comprobaciones para repetir la validación:

1. Instalar y activar el ZIP en un WordPress limpio: debe aparecer el plugin y su estado, sin errores PHP; el frontend debe seguir funcionando.
2. Actualizar una instalación activa de 0.2.0 con el ZIP 0.3.0: debe mantenerse activa y crear la selección vacía junto al esquema 2. Guardar selecciones en varias páginas del listado y comprobar que se conservan al filtrar o cambiar de página.
3. Desactivar/reactivar conserva las opciones. Eliminar desde WordPress las borra sin afectar a otros datos; reinstalar comienza con una selección vacía.

Realizar estas comprobaciones en una instancia aislada de WordPress con datos de prueba.

## Desarrollo y distribución

Repositorio: [dru-id/wp-druid-pulse](https://github.com/dru-id/wp-druid-pulse), rama `main`.

Mantener los archivos PHP con UTF-8 sin BOM y convenciones de WordPress.

El ZIP 0.3.0 incluye el archivo principal, `src/`, `templates/`, `assets/admin-tracking.js`, `uninstall.php` y `readme.txt`, bajo `wp-druid-pulse/`.

La cabecera `Update URI: false` evita que WordPress.org sustituya este plugin de desarrollo por otro que tenga el mismo nombre de carpeta. No se ha añadido un servidor ni un mecanismo propio de actualizaciones.
