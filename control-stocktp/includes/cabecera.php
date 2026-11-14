<?php
/* ==========================================================
   CABECERA COMÚN: abre la base de datos, arma el menú lateral
   y la barra superior. Cada página define antes:
      $titulo  -> título de la página
      $activo  -> archivo del menú que se resalta
   ========================================================== */
require_once __DIR__ . '/funciones.php';

try {
    $pdo      = db();
    $comercio = datos_comercio();
} catch (PDOException $ex) {
    http_response_code(500);
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Error de conexión</title>
    <link rel="stylesheet" href="css/estilos.css">
</head>
<body class="pagina-error">
    <div class="tarjeta error-db">
        <h1>No se pudo conectar a la base de datos</h1>
        <p>Revisá que MySQL esté encendido, que hayas importado <code>database.sql</code>
           y que los datos de <code>config.php</code> sean correctos.</p>
        <p class="suave">Detalle técnico: <?= e($ex->getMessage()) ?></p>
    </div>
</body>
</html>
    <?php
    exit;
}

$hoy    = hoy();
$titulo = $titulo ?? 'Panel';
$activo = $activo ?? '';

// Tres módulos independientes (A, B, C) + secciones generales
$menu = [
    'B · Producción (control de stock)' => [
        'index.php'       => ['Panel',           'panel'],
        'articulos.php'   => ['Artículos',       'articulos'],
        'movimientos.php' => ['Movimientos',     'movimientos'],
        'control.php'     => ['Control diario',  'control'],
        'resumen.php'     => ['Resumen del día', 'resumen'],
    ],
    'A · Administración' => [
        'administracion.php' => ['Costos y ganancias', 'administracion'],
    ],
    'C · Personal' => [
        'personal.php'    => ['Personal y sueldos', 'personal'],
    ],
    'General' => [
        'comercio.php'    => ['Comercio',         'comercio'],
        'manual.html'     => ['Manual de usuario', 'manual'],
    ],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo) ?> - <?= e($comercio['nombre']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/estilos.css">
</head>
<body>
<div class="app">

    <aside class="lateral">
        <div class="marca">
            <span class="sello" aria-hidden="true">TP 7</span>
            <div>
                <strong><?= e($comercio['nombre']) ?></strong>
                <small>Control de stock</small>
            </div>
        </div>

        <nav class="menu" aria-label="Secciones">
            <?php foreach ($menu as $grupo => $items): ?>
                <p class="menu-titulo"><?= e($grupo) ?></p>
                <?php foreach ($items as $archivo => $item): ?>
                    <a href="<?= e($archivo) ?>"<?= $archivo === $activo ? ' class="activo" aria-current="page"' : '' ?>>
                        <?= icono($item[1]) ?><span><?= e($item[0]) ?></span>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>

        <?php if ($comercio['integrantes'] !== ''): ?>
            <p class="lateral-pie">Equipo: <?= e($comercio['integrantes']) ?></p>
        <?php endif; ?>
    </aside>

    <div class="contenido">
        <header class="barra-sup">
            <h1><?= e($titulo) ?></h1>
            <p class="fecha-hoy"><?= icono('hoy') ?><?= e(dia_semana($hoy)) ?> <?= e(fmt_fecha($hoy)) ?></p>
        </header>

        <main>
            <?php mostrar_flash(); ?>
