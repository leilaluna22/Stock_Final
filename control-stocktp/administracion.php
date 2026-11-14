<?php
/* ==========================================================
   MÓDULO A: ADMINISTRACIÓN
   Da información: costos (unitarios, totales, fijos y variables),
   ganancia (unitaria y total) y valoración de mercadería.
   Gestión: ayuda a decidir precios y ventajas competitivas.
   Lee los movimientos (módulo Producción) y los sueldos
   (módulo Personal) sin depender de sus pantallas.
   ========================================================== */
require_once __DIR__ . '/includes/funciones.php';

/* ---------- 1) Procesar formularios (POST) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();
    $accion  = post('accion');
    $periodo = periodo_valido(post('periodo')) ? post('periodo') : date('Y-m');

    try {
        $pdo = db();

        if ($accion === 'gasto') {
            $concepto = post('concepto');
            $tipo     = post('tipo');
            $monto    = decimal_valido(post('monto'));

            $errores = [];
            if ($concepto === '' || mb_strlen($concepto) > 80) {
                $errores[] = 'Escribí el concepto del gasto (hasta 80 caracteres).';
            }
            if (!in_array($tipo, ['fijo', 'variable'], true)) {
                $errores[] = 'Elegí si el gasto es fijo o variable.';
            }
            if ($monto === null) {
                $errores[] = 'El monto tiene que ser un importe de 0 en adelante.';
            }
            if ($errores) {
                foreach ($errores as $mensaje) {
                    flash('error', $mensaje);
                }
            } else {
                $st = $pdo->prepare('INSERT INTO gastos (concepto, tipo, monto, periodo) VALUES (:c, :t, :m, :p)');
                $st->execute([':c' => $concepto, ':t' => $tipo, ':m' => $monto, ':p' => $periodo]);
                flash('ok', 'Gasto "' . $concepto . '" guardado.');
            }

        } elseif ($accion === 'eliminar_gasto') {
            $st = $pdo->prepare('DELETE FROM gastos WHERE id = :id');
            $st->execute([':id' => (int)post('id')]);
            flash('ok', 'Gasto eliminado.');
        }
    } catch (PDOException $ex) {
        flash('error', 'Error en la base de datos: ' . $ex->getMessage());
    }
    redirigir('administracion.php?periodo=' . $periodo);
}

/* ---------- 2) Mostrar la página (GET) ---------- */
$titulo = 'Costos y ganancias';
$activo = 'administracion.php';
require __DIR__ . '/includes/cabecera.php';

$periodo = periodo_valido(get('periodo')) ? get('periodo') : date('Y-m');
[$desde, $hasta] = rango_periodo($periodo);

// Margen de ganancia deseado para el precio sugerido (en %)
$margen = is_numeric(get('margen')) ? min(500, max(0, (float)get('margen'))) : 20.0;

// Gastos del período
$st = $pdo->prepare('SELECT * FROM gastos WHERE periodo = :p ORDER BY tipo, concepto');
$st->execute([':p' => $periodo]);
$gastos = $st->fetchAll();

$costos_fijos = 0.0;
$costos_var   = 0.0;
foreach ($gastos as $g) {
    if ($g['tipo'] === 'fijo') {
        $costos_fijos += (float)$g['monto'];
    } else {
        $costos_var += (float)$g['monto'];
    }
}

// Mano de obra: sueldos básicos del personal activo (módulo Personal). Es un costo fijo.
$mano_obra = (float)$pdo->query('SELECT COALESCE(SUM(sueldo_basico), 0) FROM empleados WHERE activo = 1')->fetchColumn();

$costo_total = $costos_fijos + $costos_var + $mano_obra;

// Artículos con existencia de hoy y unidades que salieron en el período
$st = $pdo->prepare(
    "SELECT a.id, a.codigo, a.nombre, a.costo_unitario, a.precio_venta, a.precio_competencia,
            a.stock_inicial + COALESCE((SELECT SUM(IF(m.tipo = 'entrada', m.cantidad, -m.cantidad))
                                        FROM movimientos m
                                        WHERE m.articulo_id = a.id AND m.fecha <= :hoy), 0) AS existencia,
            COALESCE((SELECT SUM(m.cantidad) FROM movimientos m
                      WHERE m.articulo_id = a.id AND m.tipo = 'salida' AND m.fecha BETWEEN :d AND :h), 0) AS vendidas
     FROM articulos a
     ORDER BY a.codigo"
);
$st->execute([':hoy' => $hoy, ':d' => $desde, ':h' => $hasta]);
$articulos = $st->fetchAll();

$unidades = 0;
$ingresos = 0.0;
$cmv      = 0.0; // costo de la mercadería vendida
$val_costo = 0.0;
$val_venta = 0.0;
foreach ($articulos as $a) {
    $unidades  += (int)$a['vendidas'];
    $ingresos  += (int)$a['vendidas'] * (float)$a['precio_venta'];
    $cmv       += (int)$a['vendidas'] * (float)$a['costo_unitario'];
    $existencia = max(0, (int)$a['existencia']);
    $val_costo += $existencia * (float)$a['costo_unitario'];
    $val_venta += $existencia * (float)$a['precio_venta'];
}

// Costo unitario = costo total / cantidad (igual que el ejemplo del pizarrón)
$costo_unit_op   = $unidades > 0 ? $costo_total / $unidades : 0.0;
$ganancia_total  = $ingresos - $cmv - $costo_total;
$ganancia_prom_u = $unidades > 0 ? $ganancia_total / $unidades : 0.0;

$mes_ant = sumar_meses($periodo, -1);
$mes_sig = sumar_meses($periodo, 1);
?>

<section class="tarjeta">
    <div class="tarjeta-cab">
        <div>
            <h2><?= e(nombre_periodo($periodo)) ?></h2>
            <p class="suave">Del <?= e(fmt_fecha($desde)) ?> al <?= e(fmt_fecha($hasta)) ?>.</p>
        </div>
        <div class="acciones">
            <a class="boton sec" href="<?= e(url_con(['periodo' => $mes_ant])) ?>">Mes anterior</a>
            <a class="boton sec" href="<?= e(url_con(['periodo' => $mes_sig])) ?>">Mes siguiente</a>
        </div>
    </div>
    <form method="get" class="filtros">
        <div class="campo">
            <label for="periodo">Mes (AAAA-MM)</label>
            <input type="month" id="periodo" name="periodo" value="<?= e($periodo) ?>">
        </div>
        <div class="campo">
            <label for="margen">Ganancia deseada para el precio sugerido (%)</label>
            <input type="number" id="margen" name="margen" min="0" max="500" step="1" inputmode="numeric" value="<?= e((string)$margen) ?>">
        </div>
        <button type="submit" class="boton">Calcular</button>
    </form>
</section>

<section class="kpis" aria-label="Resultados del período">
    <article class="kpi">
        <span class="kpi-icono"><?= icono('administracion') ?></span>
        <div><p>Costo total del período</p><strong><?= e(m($costo_total)) ?></strong></div>
    </article>
    <article class="kpi">
        <span class="kpi-icono"><?= icono('unidades') ?></span>
        <div><p>Costo unitario (total / unidades)</p><strong><?= e(m($costo_unit_op)) ?></strong></div>
    </article>
    <article class="kpi<?= $ganancia_total < 0 ? ' kpi-alerta' : '' ?>">
        <span class="kpi-icono"><?= icono('movimientos') ?></span>
        <div><p>Ganancia total</p><strong><?= e(m($ganancia_total)) ?></strong></div>
    </article>
    <article class="kpi">
        <span class="kpi-icono"><?= icono('articulos') ?></span>
        <div><p>Valoración de la mercadería</p><strong><?= e(m($val_costo)) ?></strong></div>
    </article>
</section>

<div class="rejilla dos-col">

    <section class="tarjeta">
        <div class="tarjeta-cab">
            <h2>Costos del período</h2>
            <p class="suave">CF = costo fijo, CV = costo variable</p>
        </div>
        <div class="tabla-wrap">
            <table class="tabla">
                <thead>
                    <tr><th>Concepto</th><th>Tipo</th><th class="num">Monto</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($gastos as $g): ?>
                        <tr>
                            <td><?= e($g['concepto']) ?></td>
                            <td><?= $g['tipo'] === 'fijo' ? 'CF' : 'CV' ?></td>
                            <td class="num"><?= e(m($g['monto'])) ?></td>
                            <td class="acc">
                                <form method="post" action="administracion.php" class="en-linea"
                                      onsubmit="return confirm('¿Eliminar este gasto?');">
                                    <?= csrf_campo() ?>
                                    <input type="hidden" name="accion" value="eliminar_gasto">
                                    <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                                    <input type="hidden" name="periodo" value="<?= e($periodo) ?>">
                                    <button type="submit" class="enlace peligro">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td>Mano de obra (sueldos básicos, módulo Personal)</td>
                        <td>CF</td>
                        <td class="num"><?= e(m($mano_obra)) ?></td>
                        <td></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr><td colspan="2">Costos fijos</td><td class="num"><?= e(m($costos_fijos + $mano_obra)) ?></td><td></td></tr>
                    <tr><td colspan="2">Costos variables</td><td class="num"><?= e(m($costos_var)) ?></td><td></td></tr>
                    <tr><td colspan="2">Costo total</td><td class="num"><?= e(m($costo_total)) ?></td><td></td></tr>
                </tfoot>
            </table>
        </div>
        <p class="formula">Costo unitario = <?= e(m($costo_total)) ?> / <?= n($unidades) ?> unidades = <?= e(m($costo_unit_op)) ?></p>
        <p class="suave">Ganancia total = ventas (<?= e(m($ingresos)) ?>) - costo de la mercadería vendida (<?= e(m($cmv)) ?>) - costo total.
            Ganancia promedio por unidad: <?= e(m($ganancia_prom_u)) ?>.</p>
    </section>

    <section class="tarjeta">
        <div class="tarjeta-cab">
            <h2>Cargar un gasto</h2>
        </div>
        <form method="post" action="administracion.php" class="formulario">
            <?= csrf_campo() ?>
            <input type="hidden" name="accion" value="gasto">
            <input type="hidden" name="periodo" value="<?= e($periodo) ?>">
            <div class="campo campo-completo">
                <label for="concepto">Concepto (alquiler, luz, insumos...)</label>
                <input type="text" id="concepto" name="concepto" maxlength="80" required>
            </div>
            <div class="campo">
                <label for="tipo">Tipo</label>
                <select id="tipo" name="tipo">
                    <option value="fijo">Fijo (no cambia con las ventas)</option>
                    <option value="variable">Variable (cambia con las ventas)</option>
                </select>
            </div>
            <div class="campo">
                <label for="monto">Monto ($)</label>
                <input type="number" id="monto" name="monto" min="0" step="0.01" required inputmode="decimal">
            </div>
            <div class="acciones campo-completo">
                <button type="submit" class="boton">Guardar gasto</button>
            </div>
        </form>
        <p class="suave">Se guarda en <?= e(nombre_periodo($periodo)) ?>.</p>
    </section>

</div>

<section class="tarjeta">
    <div class="tarjeta-cab">
        <div>
            <h2>Gestión: precios y ventajas competitivas</h2>
            <p class="suave">Costo unitario total = costo de compra + costo unitario del período (<?= e(m($costo_unit_op)) ?>).
                Precio sugerido = costo unitario total + <?= e((string)$margen) ?>% de ganancia.
                Valoración a precio de venta: <?= e(m($val_venta)) ?>.</p>
        </div>
    </div>
    <?php if (!$articulos): ?>
        <p class="vacio">Todavía no hay artículos cargados.</p>
    <?php else: ?>
        <div class="tabla-wrap tabla-alta">
            <table class="tabla">
                <thead>
                    <tr>
                        <th>Artículo</th>
                        <th class="num">Existencia</th>
                        <th class="num">Costo de compra</th>
                        <th class="num">Costo unitario total</th>
                        <th class="num">Precio de venta</th>
                        <th class="num">Ganancia unitaria</th>
                        <th class="num">Competencia</th>
                        <th class="num">Precio sugerido</th>
                        <th>Situación</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($articulos as $a):
                        $costo_u  = (float)$a['costo_unitario'] + $costo_unit_op;
                        $precio   = (float)$a['precio_venta'];
                        $compet   = (float)$a['precio_competencia'];
                        $gan_u    = $precio - $costo_u;
                        $sugerido = $costo_u * (1 + $margen / 100);
                        if ($precio <= 0) {
                            $situacion = ['', 'Sin precio'];
                        } elseif ($gan_u < 0) {
                            $situacion = ['bajo', 'Vende con pérdida'];
                        } elseif ($compet > 0 && $precio < $compet) {
                            $situacion = ['ent', 'Más barato que la competencia'];
                        } elseif ($compet > 0 && $precio > $compet) {
                            $situacion = ['sal', 'Más caro que la competencia'];
                        } else {
                            $situacion = ['sal', 'Precio de mercado'];
                        }
                    ?>
                        <tr>
                            <td><?= e($a['codigo'] . ' - ' . $a['nombre']) ?></td>
                            <td class="num"><?= n($a['existencia']) ?></td>
                            <td class="num"><?= e(m($a['costo_unitario'])) ?></td>
                            <td class="num"><?= e(m($costo_u)) ?></td>
                            <td class="num"><b><?= e(m($precio)) ?></b></td>
                            <td class="num <?= $gan_u < 0 ? 'sal' : 'ent' ?>"><?= e(m($gan_u)) ?></td>
                            <td class="num"><?= $compet > 0 ? e(m($compet)) : '<span class="raya">-</span>' ?></td>
                            <td class="num"><?= e(m($sugerido)) ?></td>
                            <td><span class="insignia <?= e($situacion[0]) ?>"><?= e($situacion[1]) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="suave">Costos y precios de cada artículo se cambian en <a class="enlace" href="articulos.php">Artículos</a>.</p>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/pie.php'; ?>
