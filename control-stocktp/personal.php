<?php
/* ==========================================================
   MÓDULO C: PERSONAL
   Empleados, asistencia (presentismo y productividad),
   licencias y liquidación de sueldos con retenciones.
   ========================================================== */
require_once __DIR__ . '/includes/funciones.php';

const VISTAS = ['liquidacion' => 'Liquidación de sueldos', 'empleados' => 'Empleados',
                'asistencia'  => 'Asistencia',            'licencias' => 'Licencias'];
const TIPOS_LICENCIA = ['vacaciones' => 'Vacaciones', 'enfermedad' => 'Enfermedad', 'estudio' => 'Estudio', 'otra' => 'Otra'];

/* ---------- 1) Procesar formularios (POST) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();
    $accion = post('accion');
    $vista  = array_key_exists(post('vista'), VISTAS) ? post('vista') : 'empleados';

    try {
        $pdo = db();
        $errores = [];

        if ($accion === 'empleado') {
            $nombre  = post('nombre');
            $cargo   = post('cargo');
            $sueldo  = decimal_valido(post('sueldo'));
            $ingreso = post('ingreso');
            if ($nombre === '' || mb_strlen($nombre) > 80 || mb_strlen($cargo) > 60) {
                $errores[] = 'Completá el nombre (hasta 80 caracteres) y el cargo (hasta 60).';
            }
            if ($sueldo === null || $sueldo <= 0) {
                $errores[] = 'El sueldo básico tiene que ser un importe mayor a 0.';
            }
            if (!fecha_valida($ingreso)) {
                $errores[] = 'La fecha de ingreso no es válida.';
            }
            if (!$errores) {
                $st = $pdo->prepare('INSERT INTO empleados (nombre, cargo, sueldo_basico, ingreso) VALUES (:n, :c, :s, :i)');
                $st->execute([':n' => $nombre, ':c' => $cargo, ':s' => $sueldo, ':i' => $ingreso]);
                flash('ok', 'Empleado "' . $nombre . '" guardado.');
            }

        } elseif ($accion === 'eliminar_empleado') {
            $st = $pdo->prepare('DELETE FROM empleados WHERE id = :id');
            $st->execute([':id' => (int)post('id')]);
            flash('ok', 'Empleado eliminado junto con su asistencia y licencias.');

        } elseif ($accion === 'asistencia') {
            $emp    = (int)post('empleado_id');
            $fecha  = post('fecha');
            $estado = post('estado');
            $unid   = filter_var(post('unidades') === '' ? '0' : post('unidades'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            $st = $pdo->prepare('SELECT COUNT(*) FROM empleados WHERE id = :id');
            $st->execute([':id' => $emp]);
            if ($st->fetchColumn() == 0) {
                $errores[] = 'Elegí un empleado de la lista.';
            }
            if (!fecha_valida($fecha)) {
                $errores[] = 'La fecha no es válida.';
            } elseif ($fecha > hoy()) {
                $errores[] = 'La fecha no puede ser posterior a hoy.';
            }
            if (!in_array($estado, ['presente', 'tarde', 'ausente'], true)) {
                $errores[] = 'Elegí presente, tarde o ausente.';
            }
            if ($unid === false) {
                $errores[] = 'Las unidades tienen que ser un número entero desde 0.';
            }
            if (!$errores) {
                if ($estado === 'ausente') {
                    $unid = 0;
                }
                // Si ya había un registro de ese día, se reemplaza
                $st = $pdo->prepare(
                    'INSERT INTO asistencias (empleado_id, fecha, estado, unidades) VALUES (:e, :f, :s, :u)
                     ON DUPLICATE KEY UPDATE estado = VALUES(estado), unidades = VALUES(unidades)'
                );
                $st->execute([':e' => $emp, ':f' => $fecha, ':s' => $estado, ':u' => $unid]);
                flash('ok', 'Asistencia del ' . fmt_fecha($fecha) . ' guardada.');
            }

        } elseif ($accion === 'eliminar_asistencia') {
            $st = $pdo->prepare('DELETE FROM asistencias WHERE id = :id');
            $st->execute([':id' => (int)post('id')]);
            flash('ok', 'Registro de asistencia eliminado.');

        } elseif ($accion === 'licencia') {
            $emp   = (int)post('empleado_id');
            $tipo  = post('tipo');
            $desde = post('desde');
            $hasta = post('hasta');
            $nota  = post('nota');
            $st = $pdo->prepare('SELECT COUNT(*) FROM empleados WHERE id = :id');
            $st->execute([':id' => $emp]);
            if ($st->fetchColumn() == 0) {
                $errores[] = 'Elegí un empleado de la lista.';
            }
            if (!array_key_exists($tipo, TIPOS_LICENCIA)) {
                $errores[] = 'Elegí el tipo de licencia.';
            }
            if (!fecha_valida($desde) || !fecha_valida($hasta)) {
                $errores[] = 'Las fechas no son válidas.';
            } elseif ($desde > $hasta) {
                $errores[] = '"Desde" tiene que ser igual o anterior a "Hasta".';
            }
            if (mb_strlen($nota) > 80) {
                $errores[] = 'La nota puede tener hasta 80 caracteres.';
            }
            if (!$errores) {
                $st = $pdo->prepare('INSERT INTO licencias (empleado_id, tipo, desde, hasta, nota) VALUES (:e, :t, :d, :h, :n)');
                $st->execute([':e' => $emp, ':t' => $tipo, ':d' => $desde, ':h' => $hasta, ':n' => $nota]);
                flash('ok', 'Licencia registrada.');
            }

        } elseif ($accion === 'eliminar_licencia') {
            $st = $pdo->prepare('DELETE FROM licencias WHERE id = :id');
            $st->execute([':id' => (int)post('id')]);
            flash('ok', 'Licencia eliminada.');
        }

        foreach ($errores as $mensaje) {
            flash('error', $mensaje);
        }
    } catch (PDOException $ex) {
        flash('error', 'Error en la base de datos: ' . $ex->getMessage());
    }
    redirigir('personal.php?vista=' . $vista);
}

/* ---------- 2) Mostrar la página (GET) ---------- */
$titulo = 'Personal y sueldos';
$activo = 'personal.php';
require __DIR__ . '/includes/cabecera.php';

$vista = array_key_exists(get('vista'), VISTAS) ? get('vista') : 'liquidacion';

$empleados = $pdo->query('SELECT * FROM empleados WHERE activo = 1 ORDER BY nombre')->fetchAll();
?>

<nav class="pestanas" aria-label="Secciones del módulo Personal">
    <?php foreach (VISTAS as $clave => $nombre_vista): ?>
        <a href="personal.php?vista=<?= e($clave) ?>"<?= $clave === $vista ? ' class="activo" aria-current="page"' : '' ?>><?= e($nombre_vista) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($vista === 'empleados'): ?>

<section class="tarjeta">
    <div class="tarjeta-cab"><h2>Nuevo empleado</h2></div>
    <form method="post" action="personal.php" class="formulario">
        <?= csrf_campo() ?>
        <input type="hidden" name="accion" value="empleado">
        <input type="hidden" name="vista" value="empleados">
        <div class="campo campo-ancho">
            <label for="nombre">Nombre y apellido</label>
            <input type="text" id="nombre" name="nombre" maxlength="80" required>
        </div>
        <div class="campo">
            <label for="cargo">Cargo</label>
            <input type="text" id="cargo" name="cargo" maxlength="60">
        </div>
        <div class="campo">
            <label for="sueldo">Sueldo básico ($)</label>
            <input type="number" id="sueldo" name="sueldo" min="0" step="0.01" required inputmode="decimal">
        </div>
        <div class="campo">
            <label for="ingreso">Fecha de ingreso</label>
            <input type="date" id="ingreso" name="ingreso" max="<?= e($hoy) ?>" value="<?= e($hoy) ?>" required>
        </div>
        <div class="acciones campo-completo">
            <button type="submit" class="boton">Guardar empleado</button>
        </div>
    </form>
</section>

<section class="tarjeta">
    <div class="tarjeta-cab">
        <h2>Lista de empleados</h2>
        <p class="suave"><?= n(count($empleados)) ?> en total</p>
    </div>
    <?php if (!$empleados): ?>
        <p class="vacio">Todavía no hay empleados cargados.</p>
    <?php else: ?>
        <div class="tabla-wrap">
            <table class="tabla">
                <thead><tr><th>Nombre</th><th>Cargo</th><th>Ingreso</th><th class="num">Sueldo básico</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($empleados as $emp): ?>
                        <tr>
                            <td><?= e($emp['nombre']) ?></td>
                            <td><?= e($emp['cargo']) ?></td>
                            <td><?= e(fmt_fecha($emp['ingreso'])) ?></td>
                            <td class="num"><?= e(m($emp['sueldo_basico'])) ?></td>
                            <td class="acc">
                                <form method="post" action="personal.php" class="en-linea"
                                      onsubmit="return confirm('¿Eliminar este empleado y todos sus registros?');">
                                    <?= csrf_campo() ?>
                                    <input type="hidden" name="accion" value="eliminar_empleado">
                                    <input type="hidden" name="vista" value="empleados">
                                    <input type="hidden" name="id" value="<?= (int)$emp['id'] ?>">
                                    <button type="submit" class="enlace peligro">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php elseif ($vista === 'asistencia'): ?>
<?php
    $st = $pdo->query(
        "SELECT s.id, s.fecha, s.estado, s.unidades, e.nombre
         FROM asistencias s JOIN empleados e ON e.id = s.empleado_id
         ORDER BY s.fecha DESC, e.nombre LIMIT 30"
    );
    $registros = $st->fetchAll();
?>
<section class="tarjeta">
    <div class="tarjeta-cab"><h2>Registrar asistencia</h2></div>
    <p class="suave">Con esto se calculan el presentismo y la productividad (unidades procesadas por día trabajado).</p>
    <?php if (!$empleados): ?>
        <p class="vacio">Primero tenés que <a class="enlace" href="personal.php?vista=empleados">cargar empleados</a>.</p>
    <?php else: ?>
    <form method="post" action="personal.php" class="formulario">
        <?= csrf_campo() ?>
        <input type="hidden" name="accion" value="asistencia">
        <input type="hidden" name="vista" value="asistencia">
        <div class="campo">
            <label for="fecha">Fecha</label>
            <input type="date" id="fecha" name="fecha" max="<?= e($hoy) ?>" value="<?= e($hoy) ?>" required>
        </div>
        <div class="campo campo-ancho">
            <label for="empleado_id">Empleado</label>
            <select id="empleado_id" name="empleado_id" required>
                <option value="">Elegí un empleado</option>
                <?php foreach ($empleados as $emp): ?>
                    <option value="<?= (int)$emp['id'] ?>"><?= e($emp['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="campo">
            <label for="estado">Estado</label>
            <select id="estado" name="estado">
                <option value="presente">Presente</option>
                <option value="tarde">Llegó tarde</option>
                <option value="ausente">Ausente</option>
            </select>
        </div>
        <div class="campo">
            <label for="unidades">Unidades procesadas</label>
            <input type="number" id="unidades" name="unidades" min="0" step="1" inputmode="numeric" value="0">
        </div>
        <div class="acciones campo-completo">
            <button type="submit" class="boton">Guardar asistencia</button>
        </div>
    </form>
    <?php endif; ?>
</section>

<section class="tarjeta">
    <div class="tarjeta-cab"><h2>Últimos registros</h2></div>
    <?php if (!$registros): ?>
        <p class="vacio">Todavía no se registró asistencia.</p>
    <?php else: ?>
        <div class="tabla-wrap">
            <table class="tabla">
                <thead><tr><th>Fecha</th><th>Empleado</th><th>Estado</th><th class="num">Unidades</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($registros as $r): ?>
                        <tr>
                            <td><?= e(dia_semana($r['fecha'])) ?> <?= e(fmt_fecha($r['fecha'])) ?></td>
                            <td><?= e($r['nombre']) ?></td>
                            <td><span class="insignia <?= $r['estado'] === 'ausente' ? 'bajo' : ($r['estado'] === 'tarde' ? 'sal' : 'ent') ?>"><?= e(ucfirst($r['estado'])) ?></span></td>
                            <td class="num"><?= n($r['unidades']) ?></td>
                            <td class="acc">
                                <form method="post" action="personal.php" class="en-linea"
                                      onsubmit="return confirm('¿Eliminar este registro?');">
                                    <?= csrf_campo() ?>
                                    <input type="hidden" name="accion" value="eliminar_asistencia">
                                    <input type="hidden" name="vista" value="asistencia">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <button type="submit" class="enlace peligro">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php elseif ($vista === 'licencias'): ?>
<?php
    $st = $pdo->query(
        "SELECT l.*, e.nombre FROM licencias l JOIN empleados e ON e.id = l.empleado_id
         ORDER BY l.desde DESC LIMIT 30"
    );
    $licencias = $st->fetchAll();
?>
<section class="tarjeta">
    <div class="tarjeta-cab"><h2>Registrar una licencia</h2></div>
    <p class="suave">Los días de licencia no se descuentan ni cuentan como ausencia.</p>
    <?php if (!$empleados): ?>
        <p class="vacio">Primero tenés que <a class="enlace" href="personal.php?vista=empleados">cargar empleados</a>.</p>
    <?php else: ?>
    <form method="post" action="personal.php" class="formulario">
        <?= csrf_campo() ?>
        <input type="hidden" name="accion" value="licencia">
        <input type="hidden" name="vista" value="licencias">
        <div class="campo campo-ancho">
            <label for="empleado_id">Empleado</label>
            <select id="empleado_id" name="empleado_id" required>
                <option value="">Elegí un empleado</option>
                <?php foreach ($empleados as $emp): ?>
                    <option value="<?= (int)$emp['id'] ?>"><?= e($emp['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="campo">
            <label for="tipo">Tipo</label>
            <select id="tipo" name="tipo">
                <?php foreach (TIPOS_LICENCIA as $clave => $nombre_tipo): ?>
                    <option value="<?= e($clave) ?>"><?= e($nombre_tipo) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="campo">
            <label for="desde">Desde</label>
            <input type="date" id="desde" name="desde" value="<?= e($hoy) ?>" required>
        </div>
        <div class="campo">
            <label for="hasta">Hasta</label>
            <input type="date" id="hasta" name="hasta" value="<?= e($hoy) ?>" required>
        </div>
        <div class="campo campo-ancho">
            <label for="nota">Nota (opcional)</label>
            <input type="text" id="nota" name="nota" maxlength="80">
        </div>
        <div class="acciones campo-completo">
            <button type="submit" class="boton">Guardar licencia</button>
        </div>
    </form>
    <?php endif; ?>
</section>

<section class="tarjeta">
    <div class="tarjeta-cab"><h2>Licencias registradas</h2></div>
    <?php if (!$licencias): ?>
        <p class="vacio">Todavía no se registró ninguna licencia.</p>
    <?php else: ?>
        <div class="tabla-wrap">
            <table class="tabla">
                <thead><tr><th>Empleado</th><th>Tipo</th><th>Desde</th><th>Hasta</th><th class="num">Días</th><th>Nota</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($licencias as $l):
                        $dias = (int)(new DateTime($l['desde']))->diff(new DateTime($l['hasta']))->days + 1;
                    ?>
                        <tr>
                            <td><?= e($l['nombre']) ?></td>
                            <td><?= e(TIPOS_LICENCIA[$l['tipo']] ?? $l['tipo']) ?></td>
                            <td><?= e(fmt_fecha($l['desde'])) ?></td>
                            <td><?= e(fmt_fecha($l['hasta'])) ?></td>
                            <td class="num"><?= n($dias) ?></td>
                            <td class="suave"><?= e($l['nota']) ?></td>
                            <td class="acc">
                                <form method="post" action="personal.php" class="en-linea"
                                      onsubmit="return confirm('¿Eliminar esta licencia?');">
                                    <?= csrf_campo() ?>
                                    <input type="hidden" name="accion" value="eliminar_licencia">
                                    <input type="hidden" name="vista" value="licencias">
                                    <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                                    <button type="submit" class="enlace peligro">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php else: ?>
<?php
    /* ---------- Liquidación de sueldos del mes ---------- */
    $periodo = periodo_valido(get('periodo')) ? get('periodo') : date('Y-m');
    [$desde, $hasta] = rango_periodo($periodo);

    // Asistencia del mes
    $st = $pdo->prepare(
        'SELECT empleado_id, estado, COUNT(*) AS dias, SUM(unidades) AS unidades
         FROM asistencias WHERE fecha BETWEEN :d AND :h GROUP BY empleado_id, estado'
    );
    $st->execute([':d' => $desde, ':h' => $hasta]);
    $asis = [];
    foreach ($st->fetchAll() as $r) {
        $asis[$r['empleado_id']][$r['estado']] = ['dias' => (int)$r['dias'], 'unidades' => (int)$r['unidades']];
    }

    // Días de licencia que caen dentro del mes
    $st = $pdo->prepare('SELECT empleado_id, desde, hasta FROM licencias WHERE desde <= :h AND hasta >= :d');
    $st->execute([':d' => $desde, ':h' => $hasta]);
    $lic = [];
    foreach ($st->fetchAll() as $r) {
        $ini = max($r['desde'], $desde);
        $fin = min($r['hasta'], $hasta);
        $lic[$r['empleado_id']] = ($lic[$r['empleado_id']] ?? 0) + (int)(new DateTime($ini))->diff(new DateTime($fin))->days + 1;
    }

    $filas = [];
    $tot = ['basico' => 0, 'presentismo' => 0, 'faltas' => 0, 'retenciones' => 0, 'neto' => 0];
    $tot_ret = array_fill_keys(array_keys(RETENCIONES), 0.0);

    foreach ($empleados as $emp) {
        $id        = $emp['id'];
        $presentes = $asis[$id]['presente']['dias'] ?? 0;
        $tardes    = $asis[$id]['tarde']['dias'] ?? 0;
        $ausentes  = $asis[$id]['ausente']['dias'] ?? 0;
        $unidades  = ($asis[$id]['presente']['unidades'] ?? 0) + ($asis[$id]['tarde']['unidades'] ?? 0);
        $trabajados = $presentes + $tardes;

        $basico      = (float)$emp['sueldo_basico'];
        $desc_faltas = $basico / 30 * $ausentes;
        $presentismo = ($ausentes === 0 && $trabajados > 0) ? $basico * PRESENTISMO_PCT / 100 : 0.0;
        $remunerativo = $basico - $desc_faltas + $presentismo;

        $ret = [];
        $total_ret = 0.0;
        foreach (RETENCIONES as $concepto => $pct) {
            $ret[$concepto] = $remunerativo * $pct / 100;
            $total_ret     += $ret[$concepto];
            $tot_ret[$concepto] += $ret[$concepto];
        }
        $neto = $remunerativo - $total_ret;

        $filas[] = [
            'nombre' => $emp['nombre'], 'cargo' => $emp['cargo'],
            'trabajados' => $trabajados, 'ausentes' => $ausentes, 'tardes' => $tardes,
            'licencia' => $lic[$id] ?? 0,
            'productividad' => $trabajados > 0 ? $unidades / $trabajados : 0,
            'basico' => $basico, 'presentismo' => $presentismo, 'faltas' => $desc_faltas,
            'retenciones' => $total_ret, 'neto' => $neto,
        ];
        $tot['basico']      += $basico;
        $tot['presentismo'] += $presentismo;
        $tot['faltas']      += $desc_faltas;
        $tot['retenciones'] += $total_ret;
        $tot['neto']        += $neto;
    }
?>
<section class="tarjeta">
    <div class="tarjeta-cab">
        <div>
            <h2>Liquidación de <?= e(nombre_periodo($periodo)) ?></h2>
            <p class="suave">Del <?= e(fmt_fecha($desde)) ?> al <?= e(fmt_fecha($hasta)) ?>.</p>
        </div>
        <div class="acciones">
            <a class="boton sec" href="<?= e(url_con(['periodo' => sumar_meses($periodo, -1)])) ?>">Mes anterior</a>
            <a class="boton sec" href="<?= e(url_con(['periodo' => sumar_meses($periodo, 1)])) ?>">Mes siguiente</a>
        </div>
    </div>
    <form method="get" class="filtros">
        <input type="hidden" name="vista" value="liquidacion">
        <div class="campo">
            <label for="periodo">Mes (AAAA-MM)</label>
            <input type="month" id="periodo" name="periodo" value="<?= e($periodo) ?>">
        </div>
        <button type="submit" class="boton">Ver liquidación</button>
    </form>

    <?php if (!$filas): ?>
        <p class="vacio">Todavía no hay empleados. Cargalos en la pestaña <a class="enlace" href="personal.php?vista=empleados">Empleados</a>.</p>
    <?php else: ?>
        <div class="tabla-wrap">
            <table class="tabla">
                <thead>
                    <tr>
                        <th>Empleado</th>
                        <th class="num">Días trabajados</th>
                        <th class="num">Ausencias</th>
                        <th class="num">Licencia (días)</th>
                        <th class="num">Productividad (u./día)</th>
                        <th class="num">Sueldo básico</th>
                        <th class="num">Presentismo</th>
                        <th class="num">Desc. por faltas</th>
                        <th class="num">Retenciones</th>
                        <th class="num">Sueldo neto</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($filas as $f): ?>
                        <tr>
                            <td><b><?= e($f['nombre']) ?></b><br><span class="suave"><?= e($f['cargo']) ?></span></td>
                            <td class="num"><?= n($f['trabajados']) ?><?= $f['tardes'] ? ' <span class="suave">(' . n($f['tardes']) . ' tarde)</span>' : '' ?></td>
                            <td class="num"><?= $f['ausentes'] ? '<span class="sal">' . n($f['ausentes']) . '</span>' : '<span class="raya">-</span>' ?></td>
                            <td class="num"><?= $f['licencia'] ? n($f['licencia']) : '<span class="raya">-</span>' ?></td>
                            <td class="num"><?= e(number_format($f['productividad'], 1, ',', '.')) ?></td>
                            <td class="num"><?= e(m($f['basico'])) ?></td>
                            <td class="num ent"><?= $f['presentismo'] ? '+' . e(m($f['presentismo'])) : '<span class="raya">-</span>' ?></td>
                            <td class="num sal"><?= $f['faltas'] ? '-' . e(m($f['faltas'])) : '<span class="raya">-</span>' ?></td>
                            <td class="num sal">-<?= e(m($f['retenciones'])) ?></td>
                            <td class="num"><b><?= e(m($f['neto'])) ?></b></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="5">Totales</td>
                        <td class="num"><?= e(m($tot['basico'])) ?></td>
                        <td class="num ent">+<?= e(m($tot['presentismo'])) ?></td>
                        <td class="num sal">-<?= e(m($tot['faltas'])) ?></td>
                        <td class="num sal">-<?= e(m($tot['retenciones'])) ?></td>
                        <td class="num"><?= e(m($tot['neto'])) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <p class="formula">Sueldo neto = sueldo básico - descuento por faltas + presentismo - retenciones</p>
        <p class="suave">
            Descuento por faltas = básico / 30 x ausencias. Presentismo = <?= e(number_format(PRESENTISMO_PCT, 2, ',', '.')) ?>% del básico si no hubo ausencias.
            Retenciones sobre el sueldo del mes:
            <?php
                $partes = [];
                foreach (RETENCIONES as $concepto => $pct) {
                    $partes[] = e($concepto) . ' ' . $pct . '% (' . e(m($tot_ret[$concepto])) . ')';
                }
                echo implode(', ', $partes);
            ?>.
        </p>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php require __DIR__ . '/includes/pie.php'; ?>
