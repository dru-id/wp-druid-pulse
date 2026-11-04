=== DruID Pulse for WordPress ===
Requires at least: 6.9
Requires PHP: 8.3
Stable tag: 0.3.0

Base instalable del plugin independiente de actividad autenticada para DruID Pulse.

== Descripción ==

Versión de desarrollo 0.3.0.

Permite seleccionar y guardar contenidos para páginas vistas desde el menú
DruID Pulse > Tracking. La captura de eventos y el envío a Pulse todavía no
están implementados.

No requiere wp-druid ni WooCommerce. Guarda la versión del esquema y la selección
de vistas mediante Options API; no crea tablas, programa tareas ni conecta a Pulse.

Requiere WordPress 6.9 y PHP 8.3 o posteriores.

== Instalación ==

1. Utilizar una instalación de WordPress de desarrollo compatible.
2. Subir el ZIP desde Plugins > Añadir nuevo plugin > Subir plugin.
3. Instalar y activar DruID Pulse for WordPress.
4. Comprobar el estado del plugin y que el frontend siga funcionando.
5. Abrir DruID Pulse > Tracking y guardar la selección de vistas.

WordPress comprueba las versiones requeridas antes de permitir la activación.

== Selección de vistas ==

La selección empieza vacía. El catálogo muestra páginas, entradas y contenidos
publicados de tipos públicos visibles en el frontend. Incluye la portada y la
página de entradas, sin duplicar las páginas que desempeñan esas funciones.
No incluye adjuntos, contenidos no publicados ni otros archivos o rutas especiales.

Se puede buscar por título, filtrar por tipo y selección y navegar entre páginas.
Guardar modifica únicamente las filas mostradas y conserva las demás selecciones.
Los contenidos nuevos y los descendientes no se seleccionan automáticamente.

Las referencias se conservan por ID aunque cambien el título o la URL. Las que
dejan de estar disponibles se muestran en Unavailable selections, desde donde
se pueden retirar. Recuperan su selección si vuelven a estar disponibles con el
mismo ID. Cambiar la portada no selecciona automáticamente otra página.

Configurar y guardar requiere manage_options y un nonce válido. La selección
de clics es independiente del catálogo de vistas y todavía no está disponible.

== Desactivación y eliminación ==

Desactivar conserva los datos propios y reactivar no los reinicia.
Eliminar desde la pantalla de plugins de WordPress borra únicamente las opciones
wp_druid_pulse_view_selection y wp_druid_pulse_schema_version. Una reinstalación
comienza con datos nuevos. Si falla un borrado, la eliminación se interrumpe y
puede reintentarse para retirar los datos restantes. Borrar archivos manualmente
no ejecuta esta limpieza.

== Actualizaciones ==

Este paquete no se actualiza desde el directorio de plugins de WordPress.org.
Las siguientes versiones de desarrollo se distribuirán como paquetes ZIP.
La versión del esquema se comprueba en cada carga, también al actualizar un
plugin activo sin volver a activarlo. La actualización desde 0.1.0 o 0.2.0 crea
la selección vacía si no existe y solo avanza al esquema 2 cuando se ha inicializado.
Una selección existente se conserva. Los errores de lectura o guardado se muestran
sin reiniciar los datos.

Si la inicialización falla, la activación se interrumpe. Si el plugin ya estaba
activo, conserva los datos y deja sus funciones en pausa; los usuarios con
capacidad para activar plugins reciben un aviso en administración. Se reintenta
en peticiones posteriores. Nunca se reemplaza automáticamente un esquema más
reciente o desconocido: hay que restaurar una versión compatible o revisar los
metadatos de instalación.

== Registro de cambios ==

= 0.3.0 =

* Catálogo de vistas con búsqueda, filtros, paginación y selección por ID.
* Portada, página de entradas y referencias no disponibles diferenciadas.
* Guardado protegido, aviso de cambios sin guardar y conservación entre páginas.
* Actualización del esquema y desinstalación de la configuración propia.

= 0.2.0 =

* Inicialización idempotente y comprobación de esquema al activar y actualizar.
* Conservación al desactivar y limpieza de datos propios al desinstalar.
* Fallos explícitos, aviso administrativo y reintento en cargas posteriores.

= 0.1.0 =

* Base instalable independiente y estado informativo en la lista de plugins.
