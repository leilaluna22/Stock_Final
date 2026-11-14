<?php
/* ==========================================================
   RESUMEN DEL DÍA
   Todos los artículos en un día: existencia anterior, entrada
   (ingreso), salida (egreso) y existencia al cierre.
   ========================================================== */
$titulo = 'Resumen del día';
$activo = 'resumen.php';
require __DIR__ . '/includes/cabecera.php';

$fecha = fecha_valida(get('fecha')) ? get('fecha') : $hoy;
$q     = mb_strtolower(get('q'));
$solo  = get('solo') === '1';

// Todos los artículos con sus números del día, filtrados en PHP
$filas = array_values(array_filter(existencias_al($fecha), function ($f) use ($q, $solo) {
    if ($q !== '' && mb_strpos(mb_strtolower($f['codigo'] . ' ' . $f['nombre'] . ' ' . $f['categoria']), $q) === false) {
        return false;
    }
    if ($solo && !$f['entrada'] && !$f['salida']) {
        return false;
    }
    return true;
}));

$t_ant = $t_ent = $t_sal = 0;
foreach ($filas as $f) {
    $t_ant += $f['anterior'];
    $t_ent += $f['entrada'];
    $t_sal += $f['salida'];
}

$dia_anterior  = sumar_dias($fecha, -1);
$dia_siguiente = sumar_dias($fecha, 1);
?>

<section class="tarjeta">
    <div class="tarjeta-cab">
        <div>
            <h2><?= e(dia_semana($fecha)) ?> <?= e(fmt_fecha($fecha)) ?></h2>
            <p class="suave"><?= n(count($filas)) ?> <?= count($filas) === 1 ? 'artículo' : 'artículos' ?> en la lista.</p>
        </div>
        <div class="acciones">
            <a class="boton sec" href="<?= e(url_con(['fecha' => $dia_anterior])) ?>">Día anterior</a>
            <?php if ($dia_siguiente <= $hoy): ?>
                <a class="boton sec" href="<?= e(url_con(['fecha' => $dia_siguiente])) ?>">Día siguiente</a>
            <?php endif; ?>
        </div>
    </div>

    <form method="get" class="filtros">
        <div class="campo">
            <label for="fecha">Día</label>
            <input type="date" id="fecha" name="fecha" max="<?= e($hoy) ?>" value="<?= e($fecha) ?>">
        </div>
        <div class="campo">
            <label for="q">Buscar artículo</label>
            <input type="search" id="q" name="q" value="<?= e(get('q')) ?>" autocomplete="off">
        </div>
        <label class="casilla">
            <input type="checkbox" name="solo" value="1"<?= $solo ? ' checked' : '' ?>>
            Solo con movimientos
        </label>
        <button type="submit" class="boton">Ver resumen</button>
    </form>

    <?php if (!$filas): ?>
        <p class="vacio">No hay artículos para mostrar con este filtro.</p>
    <?php else: ?>
        <div class="tabla-wrap tabla-alta">
            <table class="tabla">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Artículo</th>
                        <th class="num">Existencia anterior</th>
                        <th class="num">Entrada</th>
                        <th class="num">Salida</th>
                        <th class="num">Existencia</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($filas as $f): ?>
                        <tr>
                            <td><?= e($f['codigo']) ?></td>
                            <td><?= e($f['nombre']) ?></td>
                            <td class="num"><?= n($f['anterior']) ?></td>
                            <td class="num ent"><?= $f['entrada'] ? '+' . n($f['entrada']) : '<span class="raya">-</span>' ?></td>
                            <td class="num sal"><?= $f['salida'] ? '-' . n($f['salida']) : '<span class="raya">-</span>' ?></td>
                            <td class="num">
                                <b><?= n($f['existencia']) ?></b>
                                <?php if ($f['existencia'] <= $f['stock_minimo']): ?><span class="insignia bajo">Stock bajo</span><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2">Totales</td>
                        <td class="num"><?= n($t_ant) ?></td>
                        <td class="num ent">+<?= n($t_ent) ?></td>
                        <td class="num sal">-<?= n($t_sal) ?></td>
                        <td class="num"><?= n($t_ant + $t_ent - $t_sal) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/pie.php'; ?>
