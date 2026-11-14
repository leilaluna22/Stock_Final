<?php
/* ==========================================================
   PANEL (DASHBOARD)
   Resumen de todo el comercio: totales de hoy, gráfico de los
   últimos 7 días, stock bajo y últimos movimientos.
   ========================================================== */
$titulo = 'Panel';
$activo = 'index.php';
require __DIR__ . '/includes/cabecera.php';

/* ---------- Existencia de todos los artículos al día de hoy ---------- */
$filas    = existencias_al($hoy);
$unidades = 0;
$bajos    = [];
foreach ($filas as $f) {
    $unidades += $f['existencia'];
    if ($f['existencia'] <= $f['stock_minimo']) {
        $bajos[] = $f;
    }
}
// Los más urgentes primero (los que están más lejos del mínimo)
usort($bajos, function ($a, $b) {
    return ($a['existencia'] - $a['stock_minimo']) <=> ($b['existencia'] - $b['stock_minimo']);
});

/* ---------- Movimientos de hoy ---------- */
$st = $pdo->prepare(
    "SELECT COALESCE(SUM(CASE WHEN tipo = 'entrada' THEN cantidad END), 0) AS ent,
            COALESCE(SUM(CASE WHEN tipo = 'salida'  THEN cantidad END), 0) AS sal,
            COUNT(*) AS cantidad
     FROM movimientos WHERE fecha = :f"
);
$st->execute([':f' => $hoy]);
$mh = $st->fetch();

/* ---------- Últimos 7 días de todo el comercio ---------- */
$desde = sumar_dias($hoy, -6);

$st = $pdo->prepare(
    "SELECT fecha, tipo, SUM(cantidad) AS total
     FROM movimientos WHERE fecha BETWEEN :d AND :h
     GROUP BY fecha, tipo"
);
$st->execute([':d' => $desde, ':h' => $hoy]);
$por_dia = [];
foreach ($st->fetchAll() as $r) {
    $por_dia[$r['fecha']][$r['tipo']] = (int)$r['total'];
}

// Existencia total del comercio antes del primer día del período
$st = $pdo->prepare(
    "SELECT (SELECT COALESCE(SUM(stock_inicial), 0) FROM articulos)
          + COALESCE((SELECT SUM(IF(tipo = 'entrada', cantidad, -cantidad))
                      FROM movimientos WHERE fecha < :d), 0)"
);
$st->execute([':d' => $desde]);
$saldo = (int)$st->fetchColumn();

$serie = [];
$max   = 1;
for ($i = 6; $i >= 0; $i--) {
    $fecha = sumar_dias($hoy, -$i);
    $ent   = $por_dia[$fecha]['entrada'] ?? 0;
    $sal   = $por_dia[$fecha]['salida'] ?? 0;
    $saldo += $ent - $sal;
    $serie[] = ['fecha' => $fecha, 'ent' => $ent, 'sal' => $sal, 'exi' => $saldo];
    $max = max($max, $ent, $sal);
}

/* ---------- Últimos movimientos ---------- */
$ultimos = $pdo->query(
    "SELECT m.fecha, m.tipo, m.cantidad, a.codigo, a.nombre
     FROM movimientos m
     JOIN articulos a ON a.id = m.articulo_id
     ORDER BY m.fecha DESC, m.id DESC
     LIMIT 8"
)->fetchAll();

$alto_grafico = 150; // píxeles de la barra más alta
?>

<?php if (!$filas): ?>
    <section class="tarjeta vacio-grande">
        <h2>Todavía no hay artículos cargados</h2>
        <p>Empezá cargando los productos del comercio y después registrá sus entradas y salidas.</p>
        <a class="boton" href="articulos.php">Cargar el primer artículo</a>
    </section>
<?php endif; ?>

<section class="kpis" aria-label="Totales de hoy">
    <article class="kpi">
        <span class="kpi-icono"><?= icono('articulos') ?></span>
        <div>
            <p>Artículos cargados</p>
            <strong><?= n(count($filas)) ?></strong>
        </div>
    </article>
    <article class="kpi">
        <span class="kpi-icono"><?= icono('unidades') ?></span>
        <div>
            <p>Unidades en existencia</p>
            <strong><?= n($unidades) ?></strong>
        </div>
    </article>
    <article class="kpi">
        <span class="kpi-icono"><?= icono('movimientos') ?></span>
        <div>
            <p>Hoy entraron / salieron</p>
            <strong><span class="ent">+<?= n($mh['ent']) ?></span> <span class="separador">/</span> <span class="sal">-<?= n($mh['sal']) ?></span></strong>
        </div>
    </article>
    <article class="kpi<?= count($bajos) ? ' kpi-alerta' : '' ?>">
        <span class="kpi-icono"><?= icono('alerta') ?></span>
        <div>
            <p>Con stock bajo</p>
            <strong><?= n(count($bajos)) ?></strong>
        </div>
    </article>
</section>

<div class="rejilla dos-col">

    <section class="tarjeta">
        <div class="tarjeta-cab">
            <h2>Movimiento de los últimos 7 días</h2>
            <ul class="leyenda">
                <li><span class="punto p-ent"></span>Entradas</li>
                <li><span class="punto p-sal"></span>Salidas</li>
            </ul>
        </div>
        <div class="grafico" role="img" aria-label="Entradas y salidas de cada uno de los últimos 7 días">
            <?php foreach ($serie as $d):
                $h_ent = $d['ent'] > 0 ? max(4, (int)round($d['ent'] / $max * $alto_grafico)) : 0;
                $h_sal = $d['sal'] > 0 ? max(4, (int)round($d['sal'] / $max * $alto_grafico)) : 0;
            ?>
                <div class="col">
                    <div class="barras">
                        <div class="barra b-ent" style="height: <?= $h_ent ?>px">
                            <?php if ($d['ent'] > 0): ?><span class="val"><?= n($d['ent']) ?></span><?php endif; ?>
                        </div>
                        <div class="barra b-sal" style="height: <?= $h_sal ?>px">
                            <?php if ($d['sal'] > 0): ?><span class="val"><?= n($d['sal']) ?></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="etq"><b><?= e(mb_substr(dia_semana($d['fecha']), 0, 3)) ?></b> <?= e(fmt_corta($d['fecha'])) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="tarjeta">
        <div class="tarjeta-cab">
            <h2>Stock bajo</h2>
            <a class="enlace" href="resumen.php">Ver resumen</a>
        </div>
        <?php if (!$bajos): ?>
            <p class="vacio">Ningún artículo está en su stock mínimo.</p>
        <?php else: ?>
            <ul class="lista-bajos">
                <?php foreach (array_slice($bajos, 0, 8) as $b): ?>
                    <li>
                        <div>
                            <strong><?= e($b['nombre']) ?></strong>
                            <small><?= e($b['codigo']) ?>, mínimo <?= n($b['stock_minimo']) ?></small>
                        </div>
                        <span class="insignia bajo"><?= n($b['existencia']) ?> u.</span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($bajos) > 8): ?>
                <p class="suave">y <?= n(count($bajos) - 8) ?> más.</p>
            <?php endif; ?>
        <?php endif; ?>
    </section>

</div>

<div class="rejilla dos-col iguales">

    <section class="tarjeta">
        <div class="tarjeta-cab">
            <h2>Día por día de todo el comercio</h2>
        </div>
        <div class="tabla-wrap">
            <table class="tabla tabla-dia">
                <thead>
                    <tr>
                        <th>Día</th>
                        <th class="num">Entrada</th>
                        <th class="num">Salida</th>
                        <th class="num">Existencia</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($serie as $d): ?>
                        <tr>
                            <td><b><?= e(dia_semana($d['fecha'])) ?></b> <span class="suave"><?= e(fmt_corta($d['fecha'])) ?></span></td>
                            <td class="num ent"><?= $d['ent'] ? '+' . n($d['ent']) : '<span class="raya">-</span>' ?></td>
                            <td class="num sal"><?= $d['sal'] ? '-' . n($d['sal']) : '<span class="raya">-</span>' ?></td>
                            <td class="num exi"><?= n($d['exi']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="tarjeta">
        <div class="tarjeta-cab">
            <h2>Últimos movimientos</h2>
            <a class="enlace" href="movimientos.php">Registrar otro</a>
        </div>
        <?php if (!$ultimos): ?>
            <p class="vacio">Todavía no se registró ningún movimiento.</p>
        <?php else: ?>
            <div class="tabla-wrap">
                <table class="tabla">
                    <thead>
                        <tr><th>Fecha</th><th>Artículo</th><th>Tipo</th><th class="num">Cantidad</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ultimos as $m): ?>
                            <tr>
                                <td><?= e(fmt_corta($m['fecha'])) ?></td>
                                <td><?= e($m['nombre']) ?></td>
                                <td><span class="insignia <?= $m['tipo'] === 'entrada' ? 'ent' : 'sal' ?>"><?= $m['tipo'] === 'entrada' ? 'Entrada' : 'Salida' ?></span></td>
                                <td class="num <?= $m['tipo'] === 'entrada' ? 'ent' : 'sal' ?>"><?= $m['tipo'] === 'entrada' ? '+' : '-' ?><?= n($m['cantidad']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

</div>

<?php require __DIR__ . '/includes/pie.php'; ?>
