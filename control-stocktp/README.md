# Control de Stock en WEB (HTML + CSS + PHP + MySQL)

Página web para controlar el stock de un comercio: muestra **existencia, entrada (ingreso) y salida (egreso) día por día**.

## Cómo ponerlo a funcionar (XAMPP)

1. Copiá la carpeta `control-stock` dentro de `C:\xampp\htdocs\`.
2. Abrí XAMPP y encendé **Apache** y **MySQL**.
3. Entrá a `http://localhost/phpmyadmin`, creá una base llamada `control_stock`, elegila, andá a la pestaña **Importar** y subí `database.sql`.
   (Crea las tablas y carga 100 artículos, 7 días de movimientos, gastos, 3 empleados y asistencia de ejemplo.)
4. Abrí `http://localhost/control-stock/` en el navegador.

Si tu MySQL tiene otra contraseña, cambiala en `config.php`.

## En InfinityFree

1. Subí **todo** el contenido de la carpeta a `htdocs/` (con las carpetas `includes/` y `css/`).
2. Borrá de `htdocs/` el archivo de bienvenida que trae el hosting (`index2.html` o similar).
3. En phpMyAdmin elegí tu base `if0_...` y **Importar** `database.sql` (ya no trae `CREATE DATABASE`).
4. Los datos de `config.php` tienen que ser los de tu panel (host `sqlXXX.infinityfree.com`). Ese host solo funciona desde el hosting, no desde XAMPP.

## Requisitos del trabajo final

| Requisito | Dónde está |
|---|---|
| Manual de usuario sin explicación personal | `manual.html` (menú "Manual de usuario") |
| Tres módulos independientes con acceso a la información | B Producción (`index.php`, `articulos.php`, `movimientos.php`, `control.php`, `resumen.php`), A Administración (`administracion.php`), C Personal (`personal.php`) |
| Administración: costos (unitarios, totales, fijos, variables), ganancia (unitaria, total), valoración de mercadería, gestión de precios | `administracion.php` |
| Personal: liquidación de sueldo, licencias, presentismo, productividad, retenciones | `personal.php` |
| 3 usuarios con niveles (solo equipos de más de 3 integrantes) | No se implementa: el equipo es de hasta 2 integrantes |

## Archivos

| Archivo | Para qué sirve |
|---|---|
| `database.sql` | Base de datos: tablas `comercio`, `articulos`, `movimientos` + datos de ejemplo |
| `config.php` | Datos de conexión a MySQL |
| `includes/funciones.php` | Funciones comunes (fechas, existencias, seguridad, paginación) |
| `includes/cabecera.php` / `pie.php` | Menú lateral y barra superior de todas las páginas |
| `index.php` | Panel (dashboard) con totales, gráfico de 7 días y stock bajo |
| `articulos.php` | Alta, edición, baja y búsqueda de artículos |
| `movimientos.php` | Registro de entradas y salidas + historial |
| `control.php` | Control diario de un artículo (la tabla del pizarrón) |
| `resumen.php` | Todos los artículos en un día |
| `administracion.php` | Módulo A: costos, ganancia, valoración y precios |
| `personal.php` | Módulo C: empleados, asistencia, licencias y sueldos |
| `manual.html` | Manual de usuario |
| `index.html` | Entrada: redirige a `index.php` |
| `comercio.php` | Descripción del comercio y forma de ingreso de datos |
| `css/estilos.css` | Estilos del dashboard (rosa y gris claro) |

## Cómo se calcula la existencia

`existencia del día = existencia anterior + entrada - salida`

El sistema no deja registrar una salida si la existencia quedaría negativa en algún día.
