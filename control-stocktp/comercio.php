<?php
/* ==========================================================
   COMERCIO
   Describe el comercio y la forma de ingreso de datos
   (dos puntos que pide el TP). Se pueden editar desde acá.
   ========================================================== */
require_once __DIR__ . '/includes/funciones.php';

/* ---------- 1) Guardar cambios (POST) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();

    $nombre      = post('nombre');
    $integrantes = post('integrantes');
    $descripcion = post('descripcion');
    $ingreso     = post('ingreso');

    if ($nombre === '') {
        flash('error', 'El nombre del comercio no puede quedar vacío.');
        redirigir('comercio.php?editar=1');
    }
    if (mb_strlen($nombre) > 80 || mb_strlen($integrantes) > 120 || mb_strlen($descripcion) > 1000 || mb_strlen($ingreso) > 2000) {
        flash('error', 'Alguno de los textos es demasiado largo.');
        redirigir('comercio.php?editar=1');
    }

    try {
        $st = db()->prepare(
            'INSERT INTO comercio (id, nombre, descripcion, integrantes, ingreso)
             VALUES (1, :nombre, :descripcion, :integrantes, :ingreso)
             ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), descripcion = VALUES(descripcion),
                                     integrantes = VALUES(integrantes), ingreso = VALUES(ingreso)'
        );
        $st->execute([':nombre' => $nombre, ':descripcion' => $descripcion,
                      ':integrantes' => $integrantes, ':ingreso' => $ingreso]);
        flash('ok', 'Datos del comercio guardados.');
    } catch (PDOException $ex) {
        flash('error', 'Error en la base de datos: ' . $ex->getMessage());
    }
    redirigir('comercio.php');
}

/* ---------- 2) Mostrar la página (GET) ---------- */
$titulo = 'Comercio';
$activo = 'comercio.php';
require __DIR__ . '/includes/cabecera.php';

$editando = get('editar') === '1';
$pasos = array_filter(array_map('trim', explode("\n", $comercio['ingreso'])), 'strlen');
?>

<?php if ($editando): ?>

<section class="tarjeta">
    <div class="tarjeta-cab">
        <h2>Editar datos del comercio</h2>
    </div>
    <form method="post" action="comercio.php" class="formulario">
        <?= csrf_campo() ?>
        <div class="campo campo-ancho">
            <label for="nombre">Nombre del comercio</label>
            <input type="text" id="nombre" name="nombre" maxlength="80" required value="<?= e($comercio['nombre']) ?>">
        </div>
        <div class="campo campo-ancho">
            <label for="integrantes">Integrantes del equipo (hasta 2)</label>
            <input type="text" id="integrantes" name="integrantes" maxlength="120" value="<?= e($comercio['integrantes']) ?>">
        </div>
        <div class="campo campo-completo">
            <label for="descripcion">Descripción del comercio</label>
            <textarea id="descripcion" name="descripcion" rows="4" maxlength="1000"><?= e($comercio['descripcion']) ?></textarea>
        </div>
        <div class="campo campo-completo">
            <label for="ingreso">Forma de ingreso de datos (un paso por línea)</label>
            <textarea id="ingreso" name="ingreso" rows="9" maxlength="2000"><?= e($comercio['ingreso']) ?></textarea>
        </div>
        <div class="acciones campo-completo">
            <button type="submit" class="boton">Guardar datos</button>
            <a class="boton sec" href="comercio.php">Cancelar</a>
        </div>
    </form>
</section>

<?php else: ?>

<div class="rejilla dos-col">
    <section class="tarjeta">
        <div class="tarjeta-cab">
            <h2>Sobre el comercio</h2>
            <a class="enlace" href="comercio.php?editar=1">Editar</a>
        </div>
        <h3><?= e($comercio['nombre']) ?></h3>
        <p class="texto"><?= nl2br(e($comercio['descripcion'])) ?></p>
        <?php if ($comercio['integrantes'] !== ''): ?>
            <p class="texto"><b>Integrantes del equipo:</b> <?= e($comercio['integrantes']) ?></p>
        <?php endif; ?>
    </section>

    <section class="tarjeta">
        <div class="tarjeta-cab">
            <h2>Cómo se ingresan los datos</h2>
        </div>
        <?php if (!$pasos): ?>
            <p class="vacio">Todavía no se describió la forma de ingreso de datos.</p>
        <?php else: ?>
            <ol class="pasos">
                <?php foreach ($pasos as $paso): ?>
                    <li><?= e($paso) ?></li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>
</div>

<?php endif; ?>

<?php require __DIR__ . '/includes/pie.php'; ?>
