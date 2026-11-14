<?php
/* ==========================================================
   CONTROL DIARIO DE UN ARTÍCULO
   Es la tabla del pizarrón: para cada día muestra la entrada
   (ingreso), la salida (egreso) y la existencia al cierre.
   ========================================================== */
$titulo = 'Control diario';
$activo = 'control.php';
require __DIR__ . '/includes/cabecera.php';

// Artículos para el selector (agrupados por categoría)
$lista = $pdo->query('SELECT id, codigo, nombre, categoria FROM articulos ORDER BY categoria, codigo')->fetchAll();
$por_categoria = [];
foreach ($lista as $a) {
    $por_categoria[$a['categoria']][] = $a;
}

// Artículo elegido (si no eligió ninguno, se toma el primero por código)
$art = null;
if ((int)get('articulo') > 0) {
    $st = $pdo->prepare('SELECT * FROM articulos WHERE id = :id');
    $st->execute([':id' => (int)get('articulo')]);
    $art = $st->fetch() ?: null;
}
if (!$art && $lista) {
    $art = $pdo->query('SELECT * FROM articulos ORDER BY codigo LIMIT 1')->fetch();
}

// Período (por defecto: los últimos 7 días)
$hasta = fecha_valida(get('hasta')) ? get('hasta') : $hoy;
$desde = fecha_valida(get('desde')) ? get('desde') : sumar_dias($hasta, -6);

$error = null;
if ($desde > $hasta) {
    $error = 'Revisá las fechas: "Desde" tiene que ser igual o anterior a "Hasta".';
} elseif ((new DateTime($desde))->diff(new DateTime($hasta))->days > 365) {
    $error = 'El período puede ser de hasta 366 días.';
}

$filas   = [];
$inicial = 0;
$tot_ent = 0;
$tot_sal = 0;
$saldo   = 0;

if ($art && !$error) {
    // Existencia antes del primer día del período
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(IF(tipo = 'entrada', cantidad, -cantidad)), 0)
         FROM movimientos WHERE articulo_id = :id AND fecha < :d"
    );
    $st->execute([':id' => $art['id'], ':d' => $desde]);
    $inicial = (int)$art['stock_inicial'] + (int)$st->fetchColumn();

    // Entradas y salidas de cada día del período
    $st = $pdo->prepare(
        "SELECT fecha, tipo, SUM(cantidad) AS total
         FROM movimientos
         WHERE articulo_id = :id AND fecha BETWEEN :d AND :h
         GROUP BY fecha, tipo"
    );
    $st->execute([':id' => $art['id'], ':d' => $desde, ':h' => $hasta]);
    $por_dia = [];
    foreach ($st->fetchAll() as $r) {
        $por_dia[$r['fecha']][$r['tipo']] = (int)$r['total'];
    }

    // Se recorre día por día: existencia = anterior + entrada - salida
    $saldo = $inicial;
    for ($f = $desde; $f <= $hasta; $f = sumar_dias($f, 1)) {
        $ent = $por_dia[$f]['entrada'] ?? 0;
        $sal = $por_dia[$f]['salida'] ?? 0;
        $saldo   += $ent - $sal;
        $tot_ent += $ent;
        $tot_sal += $sal;
        $filas[] = ['fecha' => $f, 'ent' => $ent, 'sal' => $sal, 'exi' => $saldo];
    }
}
?>

<?php if (!$lista): ?>
    <section class="tarjeta vacio-grande">
        <h2>Todavía no hay artículos cargados</h2>
        <p>Cargá al menos un artículo para ver su control día por día.</p>
        <a class="boton" href="articulos.php">Cargar un artículo</a>
    </section>
<?php else: ?>

<section class="tarjeta">
    <form method="get" class="filtros">
        <div class="campo campo-ancho">
            <label for="articulo">Artículo</label>
            <select id="articulo" name="articulo">
                <?php foreach ($por_categoria as $categoria => $items): ?>
                    <optgroup label="<?= e($categoria) ?>">
                        <?php foreach ($items as $a): ?>
                            <option value="<?= (int)$a['id'] ?>"<?= $art && (int)$a['id'] === (int)$art['id'] ? ' selected' : '' ?>>
                                <?= e($a['codigo'] . ' - ' . $a['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="campo">
            <label for="desde">Desde</label>
            <input type="date" id="desde" name="desde" value="<?= e($desde) ?>">
        </div>
        <div class="campo">
            <label for="hasta">Hasta</label>
            <input type="date" id="hasta" name="hasta" value="<?= e($hasta) ?>">
        </div>
        <button type="submit" class="boton">Ver control</button>
    </form>
    <p class="atajos suave">
        Período rápido:
        <a class="enlace" href="<?= e(url_con(['desde' => sumar_dias($hoy, -6), 'hasta' => $hoy, 'p' => null])) ?>">últimos 7 días</a>
        <a class="enlace" href="<?= e(url_con(['desde' => sumar_dias($hoy, -29), 'hasta' => $hoy, 'p' => null])) ?>">últimos 30 días</a>
    </p>
</section>

<?php if ($error): ?>
    <div class="aviso aviso-error" role="alert"><?= e($error) ?></div>
<?php elseif ($art): ?>

    <section class="kpis" aria-label="Totales del período">
        <article class="kpi">
            <span class="kpi-icono"><?= icono('hoy') ?></span>
            <div><p>Existencia inicial</p><strong><?= n($inicial) ?></strong></div>
        </article>
        <article class="kpi">
            <span class="kpi-icono"><?= icono('movimientos') ?></span>
            <div><p>Entradas del período</p><strong class="ent">+<?= n($tot_ent) ?></strong></div>
        </article>
        <article class="kpi">
            <span class="kpi-icono"><?= icono('movimientos') ?></span>
            <div><p>Salidas del período</p><strong class="sal">-<?= n($tot_sal) ?></strong></div>
        </article>
        <article class="kpi<?= $saldo <= (int)$art['stock_minimo'] ? ' kpi-alerta' : '' ?>">
            <span class="kpi-icono"><?= icono('unidades') ?></span>
            <div><p>Existencia final</p><strong><?= n($saldo) ?></strong></div>
        </article>
    </section>

    <section class="tarjeta">
        <div class="tarjeta-cab">
            <div>
                <h2><?= e($art['nombre']) ?></h2>
                <p class="suave">
                    Código <?= e($art['codigo']) ?>, categoría <?= e($art['categoria']) ?>, stock mínimo <?= n($art['stock_minimo']) ?>.
                    Del <?= e(fmt_fecha($desde)) ?> al <?= e(fmt_fecha($hasta)) ?>.
                </p>
            </div>
            <?php if ($saldo <= (int)$art['stock_minimo']): ?><span class="insignia bajo">Stock bajo</span><?php endif; ?>
        </div>

        <div class="tabla-wrap">
            <table class="tabla tabla-dia tabla-grande">
                <thead>
                    <tr>
                        <th>Día</th>
                        <th class="num">Entrada <small>ingreso</small></th>
                        <th class="num">Salida <small>egreso</small></th>
                        <th class="num">Existencia</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="fila-inicio">
                        <td>Existencia inicial</td>
                        <td></td>
                        <td></td>
                        <td class="num exi"><?= n($inicial) ?></td>
                    </tr>
                    <?php foreach ($filas as $f): ?>
                        <tr>
                            <td><b><?= e(dia_semana($f['fecha'])) ?></b> <span class="suave"><?= e(fmt_corta($f['fecha'])) ?></span></td>
                            <td class="num ent"><?= $f['ent'] ? '+' . n($f['ent']) : '<span class="raya">-</span>' ?></td>
                            <td class="num sal"><?= $f['sal'] ? '-' . n($f['sal']) : '<span class="raya">-</span>' ?></td>
                            <td class="num exi"><?= n($f['exi']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td>Total del período</td>
                        <td class="num ent">+<?= n($tot_ent) ?></td>
                        <td class="num sal">-<?= n($tot_sal) ?></td>
                        <td class="num exi"><?= n($saldo) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>

<?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/pie.php'; ?>
