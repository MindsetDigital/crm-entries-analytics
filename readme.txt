=== CRM Entries Analytics ===
Contributors: mindsetdigital
Tags: crm, contact form entries, analytics, leads, dashboard
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 1.5.1
Requires Plugins: contact-form-entries
License: GPLv2 or later

Dashboard de analítica para Contact Form Entries de CRM Perks.

== Descripción ==

Añade una pantalla "Analítica" dentro del menú CRM Entries con:

* Filtro por rango de fechas.
* Filtro por formulario.
* Filtro de leídos/no leídos.
* Cards de contactos totales, leídos y no leídos.
* Evolución temporal con una serie por formulario, total agregado y línea de tendencia opcional.
* Comparativa por formulario con el periodo anterior equivalente.
* Mapa de calor por día de la semana y hora.
* Tabla global con todos los leads.
* Buscador por nombre, email o cualquier valor guardado.
* Vista de detalle y acceso a la entrada original.
* Informe PDF corporativo sin datos personales.
* Migración de registros mediante paquetes JSON/ZIP versionados.
* Importación con previsualización, asociación de formularios y procesamiento por lotes.
* Modos de sustitución, actualización o creación exclusiva de nuevos registros.
* Copia automática antes de sustituir datos y exportación CSV para consulta.

La pantalla de analítica es de solo lectura. La sección Migración de datos solo modifica registros después de una confirmación explícita y está restringida a administradores.

== Instalación ==

1. Instala y activa "Database for Contact Form 7, WPForms, Elementor Forms" de CRM Perks.
2. Sube la carpeta `crm-entries-analytics` a `/wp-content/plugins/` o instala el ZIP desde WordPress.
3. Activa "CRM Entries Analytics".
4. Accede a CRM Entries > Analítica.

== Notas ==

* Contact Form Entries guarda las fechas en UTC; el dashboard las convierte a la zona horaria configurada en WordPress.
* El nombre, email y teléfono se detectan automáticamente a partir de los nombres y valores de los campos.
* Solo pueden acceder usuarios con permiso para ver CRM Entries o administradores.
* Se recomienda mantener Contact Form Entries actualizado. La migración comprueba la estructura real de sus tablas en lugar de exigir una versión concreta.

== Changelog ==

= 1.5.1 =

* Elimina el requisito rígido de CRM Entries 1.5.5 durante la importación.
* Basa la compatibilidad en las tablas y columnas disponibles en cada instalación.

= 1.5.0 =

* Añade la sección Migración de datos con pestañas de importación y exportación.
* Incorpora paquetes `.cea.zip` con JSON/NDJSON, comprobación SHA-256 y adjuntos opcionales.
* Añade previsualización, asociación de formularios y detección estable de coincidencias.
* Permite actualizar y crear, crear solamente nuevos o sustituir datos con copia automática.
* Procesa las importaciones por lotes y ofrece un resumen final auditable.
* Añade una capacidad administrativa independiente y exportación CSV protegida contra fórmulas.

= 1.4.0 =

* Sustituye la gráfica artesanal por Apache ECharts 6.1.0.
* Añade líneas suavizadas, área del total, tooltips y una leyenda interactiva.
* Reutiliza una exportación de alta resolución de ECharts dentro del informe PDF.
* Mantiene la gráfica PHP anterior como respaldo para la generación de informes.

= 1.3.2 =

* Mejora la jerarquía tipográfica, el espaciado y los saltos de línea del informe PDF.
* Evita que los títulos de sección queden separados de las cards comparativas.

= 1.3.1 =

* Muestra únicamente el nombre del formulario en gráficas, cards, filtros e informes PDF.

= 1.3.0 =

* Añade informes PDF descargables con la identidad visual de Mindset Digital.
* Incluye estadísticas, evolución, tendencia opcional, comparativas y mapa de calor.
* Los informes contienen únicamente datos agregados, sin información personal de los leads.

= 1.2.2 =

* Añade presets para los últimos 7, 30, 90 y 365 días.
* Aplica automáticamente el rango seleccionado y mantiene la edición manual como rango personalizado.

= 1.2.1 =

* Normaliza las locales de WordPress al formato aceptado por la API Intl del navegador.
* Añade un fallback para impedir que una locale no válida bloquee la carga del dashboard.

= 1.2.0 =

* Añade un gráfico de líneas con una serie por formulario y otra para el total agregado.
* Añade un control para mostrar u ocultar la línea de tendencia del total.
* Sustituye la distribución por formulario por cards comparativas en una grid de dos columnas.
* Compara cada formulario con el periodo inmediatamente anterior de la misma duración.

= 1.1.0 =

* Mejora la compatibilidad con los formularios y etiquetas de campos de CRM Entries.
* Evita la instanciación de objetos PHP al leer valores serializados enviados desde formularios.
* Valida fechas reales y limita rangos excesivos antes de construir las consultas REST.
* Ajusta la asignación del permiso de analítica al permiso específico de lectura de entradas.

= 1.0.0 =

* Primera versión.
