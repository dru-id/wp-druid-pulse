=== DruID Pulse for WordPress ===
Requires at least: 6.9
Requires PHP: 8.3
Stable tag: 0.4.0

Base instalable del plugin independiente de actividad autenticada para DruID Pulse.

== Descripción ==

Versión de desarrollo 0.4.0.

Permite seleccionar y guardar contenidos para páginas vistas desde el menú
DruID Pulse > Tracking y configurar OAuth2 desde DruID Pulse > Connection.
La captura de eventos y la entrega mediante cola todavía no están disponibles.
La prueba de conexión solicita un token; no envía eventos ni crea identidades.

No requiere wp-druid ni WooCommerce. Guarda esquema, selección, UUID y conexión
mediante Options API, y un token temporal mediante Transients API. No crea tablas
ni programa tareas. Los eventos individuales usarán POST /identities.

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

== Conexión y origen ==

Connection permite guardar las URLs HTTPS de Pulse y del servidor de autorización,
cliente, secreto y método OAuth. Los scopes y X-Tenant son opcionales según el
destino. Test OAuth authentication prueba los ajustes guardados, sin enviar eventos.

El secreto queda sin cifrar en la base de datos y sus copias, pero no se devuelve
al formulario. Dejarlo vacío lo conserva; Remove saved secret lo elimina de forma
explícita. Las peticiones no siguen redirecciones con las credenciales.

El origen usa el título y la URL pública de WordPress. Su UUID persiste al cambiar
nombre o dominio; una copia destinada a otra web necesita un UUID diferente.
Los mapeadores y el cliente están preparados en código; todavía no capturan
actividad ni se ejecutan automáticamente desde el frontend.

== Desactivación y eliminación ==

Desactivar conserva los datos propios y reactivar no los reinicia.
Eliminar desde la pantalla de plugins de WordPress borra únicamente las opciones
wp_druid_pulse_view_selection, wp_druid_pulse_schema_version,
wp_druid_pulse_installation_id y wp_druid_pulse_connection, además del transient
wp_druid_pulse_oauth_token. Una reinstalación
comienza con datos nuevos. Si falla un borrado, la eliminación se interrumpe y
puede reintentarse para retirar los datos restantes. Borrar archivos manualmente
no ejecuta esta limpieza.

== Actualizaciones ==

Este paquete no se actualiza desde el directorio de plugins de WordPress.org.
Las siguientes versiones de desarrollo se distribuirán como paquetes ZIP.
La versión del esquema se comprueba en cada carga, también al actualizar un
plugin activo sin volver a activarlo. La actualización desde 0.1.0 o 0.2.0 crea
la selección vacía si no existe. La actualización a 0.4.0 inicializa UUID y
conexión y solo avanza al esquema 3 cuando los recursos se han comprobado.
Una selección existente se conserva, también al actualizar desde 0.3.0. Los errores de lectura o guardado se muestran
sin reiniciar los datos.

Si la inicialización falla, la activación se interrumpe. Si el plugin ya estaba
activo, conserva los datos y deja sus funciones en pausa; los usuarios con
capacidad para activar plugins reciben un aviso en administración. Se reintenta
en peticiones posteriores. Nunca se reemplaza automáticamente un esquema más
reciente o desconocido: hay que restaurar una versión compatible o revisar los
metadatos de instalación.

== Registro de cambios ==

= 0.4.0 =

* Conexión OAuth2 y prueba de autenticación protegida, sin emisión de eventos.
* UUID persistente y origen obtenido de WordPress.
* Resolución del consumidor y mapeadores ampliables para vistas y clics.
* Cliente individual de Identity y caché de tokens ligada a la configuración.
* Esquema 3 y limpieza de las opciones y el transient propios.

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
