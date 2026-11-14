<?php
/* ==========================================================
   MOVIMIENTOS
   Registro de entradas (ingresos) y salidas (egresos) de
   mercadería + historial con filtros.
   ========================================================== */
require_once __DIR__ . '/includes/funciones.php';

/* ---------- 1) Procesar formularios (POST) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();
    $accion = post('accion');

    try {
        $pdo = db();

        if ($accion === 'registrar') {
            $datos = [
                'articulo_id' => post('articulo_id'),
                'fecha'       => post('fecha'),
                'tipo'        => post('tipo'),
                'cantidad'    => post('cantidad'),
                'nota'        => post('nota'),
            ];

            $st = $pdo->prepare('SELECT * FROM articulos WHERE id = :id');
            $st->execute([':id' => (int)$datos['articulo_id']]);
            $art = $st->fetch();

            // Validaciones
            $errores = [];
            if (!$art) {
                $errores[] = 'Elegí un artículo de la lista.';
            }
            if (!fecha_valida($datos['fecha'])) {
                $errores[] = 'La fecha no es válida.';
            } elseif ($datos['fecha'] > hoy()) {
                $errores[] = 'La fecha no puede ser posterior a hoy.';
            }
            if (!in_array($datos['tipo'], ['entrada', 'salida'], true)) {
                $errores[] = 'Elegí si es una entrada o una salida.';
            }
            $cantidad = filter_var($datos['cantidad'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($cantidad === false) {
                $errores[] = 'La cantidad tiene que ser un número entero de 1 en adelante.';
            }
            if (mb_strlen($datos['nota']) > 80) {
                $errores[] = 'La nota puede tener hasta 80 caracteres.';
            }
            // La existencia nunca puede quedar negativa en ningún día
            if (!$errores) {
                $neg = primer_dia_negativo(
                    (int)$art['id'],
                    (int)$art['stock_inicial'],
                    ['fecha' => $datos['fecha'], 'tipo' => $datos['tipo'], 'cantidad' => $cantidad]
                );
                if ($neg !== null) {
                    $errores[] = 'No hay stock suficiente: el ' . fmt_fecha($neg) . ' la existencia de "'
                               . $art['nombre'] . '" quedaría en negativo.';
                }
            }
            if ($errores) {
                foreach ($errores as $mensaje) {
                    flash('error', $mensaje);
                }
                $_SESSION['viejo'] = $datos;
                redirigir('movimientos.php');
            }

            $st = $pdo->prepare(
                'INSERT INTO movimientos (articulo_id, fecha, tipo, cantidad, nota)
                 VALUES (:art, :fecha, :tipo, :cant, :nota)'
            );
            $st->execute([':art' => $art['id'], ':fecha' => $datos['fecha'], ':tipo' => $datos['tipo'],
                          ':cant' => $cantidad, ':nota' => $datos['nota']]);

            $existencia = existencia_articulo((int)$art['id'], $datos['fecha']);
            flash('ok', ($datos['tipo'] === 'entrada' ? 'Entrada' : 'Salida') . ' registrada: ' . n($cantidad)
                . ' de "' . $art['nombre'] . '". Existencia al ' . fmt_fecha($datos['fecha']) . ': ' . n($existencia) . '.');
            // Se recuerdan la fecha y el tipo para cargar varios movimientos seguidos
            $_SESSION['ultimo'] = ['fecha' => $datos['fecha'], 'tipo' => $datos['tipo']];
            redirigir('movimientos.php');

        } elseif ($accion === 'eliminar') {
            $id = (int)post('id');
            $st = $pdo->prepare(
                'SELECT m.id, a.id AS art_id, a.stock_inicial
                 FROM movimientos m JOIN articulos a ON a.id = m.articulo_id
                 WHERE m.id = :id'
            );
            $st->execute([':id' => $id]);
            $mov = $st->fetch();

            if (!$mov) {
                flash('error', 'El movimiento ya no existe.');
            } else {
                // Si se borra una entrada, alguna salida posterior podría quedar sin stock
                $neg = primer_dia_negativo((int)$mov['art_id'], (int)$mov['stock_inicial'], null, $id);
                if ($neg !== null) {
                    flash('error', 'No se puede eliminar: el ' . fmt_fecha($neg) . ' la existencia quedaría negativa.');
                } else {
                    $st = $pdo->prepare('DELETE FROM movimientos WHERE id = :id');
                    $st->execute([':id' => $id]);
                    flash('ok', 'Movimiento eliminado.');
                }
            }
            redirigir('movimientos.php');
        }
    } catch (PDOException $ex) {
        flash('error', 'Error en la base de datos: ' . $ex->getMessage());
        redirigir('movimientos.php');
    }
    redirigir('movimientos.php');
}

/* ---------- 2) Mostrar la página (GET) ---------- */
$titulo = 'Movimientos';
$activo = 'movimientos.php';
require __DIR__ . '/includes/cabecera.php';

// Valores del formulario: lo último que escribió, o valores por defecto
$viejo  = $_SESSION['viejo'] ?? null;
$ultimo = $_SESSION['ultimo'] ?? null;
unset($_SESSION['viejo'], $_SESSION['ultimo']);

$form = ['articulo_id' => '', 'fecha' => $hoy, 'tipo' => 'entrada', 'cantidad' => '', 'nota' => ''];
if ($viejo) {
    $form = array_merge($form, $viejo);
} elseif ($ultimo) {
    $form['fecha'] = $ultimo['fecha'];
    $form['tipo']  = $ultimo['tipo'];
}

// Artículos agrupados por categoría para el selector
$articulos = $pdo->query('SELECT id, codigo, nombre, categoria FROM articulos ORDER BY categoria, codigo')->fetchAll();
$por_categoria = [];
foreach ($articulos as $a) {
    $por_categoria[$a['categoria']][] = $a;
}

// Filtros del historial
$f_fecha = fecha_valida(get('fecha')) ? get('fecha') : '';
$f_tipo  = in_array(get('tipo'), ['entrada', 'salida'], true) ? get('tipo') : '';
$f_q     = get('q');

$donde = [];
$par   = [];
if ($f_fecha !== '') {
    $donde[] = 'm.fecha = :fecha';
    $par[':fecha'] = $f_fecha;
}
if ($f_tipo !== '') {
    $donde[] = 'm.tipo = :tipo';
    $par[':tipo'] = $f_tipo;
}
if ($f_q !== '') {
    $donde[] = '(a.codigo LIKE :q1 OR a.nombre LIKE :q2 OR m.nota LIKE :q3)';
    $par[':q1'] = $par[':q2'] = $par[':q3'] = '%' . $f_q . '%';
}
$sql_donde = $donde ? 'WHERE ' . implode(' AND ', $donde) : '';

$st = $pdo->prepare("SELECT COUNT(*) FROM movimientos m JOIN articulos a ON a.id = m.articulo_id $sql_donde");
$st->execute($par);
$total = (int)$st->fetchColumn();

[$pagina, $paginas, $offset] = paginar($total, 15);

$st = $pdo->prepare(
    "SELECT m.id, m.fecha, m.tipo, m.cantidad, m.nota, a.codigo, a.nombre
     FROM movimientos m
     JOIN articulos a ON a.id = m.articulo_id
     $sql_donde
     ORDER BY m.fecha DESC, m.id DESC
     LIMIT 15 OFFSET " . (int)$offset
);
$st->execute($par);
$movimientos = $st->fetchAll();
?>

<section class="tarjeta">
    <div class="tarjeta-cab">
        <h2>Registrar un movimiento</h2>
    </div>
    <p class="suave">Entrada: llega mercadería. Salida: se vende, se rompe o se descarta.</p>

    <?php if (!$articulos): ?>
        <p class="vacio">Primero tenés que <a class="enlace" href="articulos.php">cargar artículos</a>.</p>
    <?php else: ?>
    <form method="post" action="movimientos.php" class="formulario">
        <?= csrf_campo() ?>
        <input type="hidden" name="accion" value="registrar">

        <div class="campo">
            <label for="fecha">Fecha</label>
            <input type="date" id="fecha" name="fecha" required max="<?= e($hoy) ?>" value="<?= e($form['fecha']) ?>">
        </div>
        <div class="campo campo-ancho">
            <label for="articulo_id">Artículo</label>
            <select id="articulo_id" name="articulo_id" required>
                <option value="">Elegí un artículo</option>
                <?php foreach ($por_categoria as $categoria => $lista): ?>
                    <optgroup label="<?= e($categoria) ?>">
                        <?php foreach ($lista as $a): ?>
                            <option value="<?= (int)$a['id'] ?>"<?= (string)$a['id'] === (string)$form['articulo_id'] ? ' selected' : '' ?>>
                                <?= e($a['codigo'] . ' - ' . $a['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="campo">
            <span class="etq" id="etq-tipo">Tipo</span>
            <div class="tipo" role="radiogroup" aria-labelledby="etq-tipo">
                <label><input type="radio" name="tipo" value="entrada"<?= $form['tipo'] !== 'salida' ? ' checked' : '' ?>><span>Entrada</span></label>
                <label><input type="radio" name="tipo" value="salida"<?= $form['tipo'] === 'salida' ? ' checked' : '' ?>><span>Salida</span></label>
            </div>
        </div>
        <div class="campo">
            <label for="cantidad">Cantidad</label>
            <input type="number" id="cantidad" name="cantidad" min="1" step="1" required inputmode="numeric" value="<?= e($form['cantidad']) ?>">
        </div>
        <div class="campo campo-ancho">
            <label for="nota">Nota (opcional)</label>
            <input type="text" id="nota" name="nota" maxlength="80" value="<?= e($form['nota']) ?>">
        </div>

        <div class="acciones campo-completo">
            <button type="submit" class="boton">Registrar movimiento</button>
        </div>
    </form>
    <?php endif; ?>
</section>

<section class="tarjeta">
    <div class="tarjeta-cab">
        <h2>Historial de movimientos</h2>
        <p class="suave"><?= n($total) ?> <?= $total === 1 ? 'movimiento' : 'movimientos' ?></p>
    </div>

    <form method="get" class="filtros">
        <div class="campo">
            <label for="h-fecha">Día</label>
            <input type="date" id="h-fecha" name="fecha" value="<?= e($f_fecha) ?>">
        </div>
        <div class="campo">
            <label for="h-tipo">Tipo</label>
            <select id="h-tipo" name="tipo">
                <option value="">Todos</option>
                <option value="entrada"<?= $f_tipo === 'entrada' ? ' selected' : '' ?>>Entradas</option>
                <option value="salida"<?= $f_tipo === 'salida' ? ' selected' : '' ?>>Salidas</option>
            </select>
        </div>
        <div class="campo">
            <label for="h-q">Artículo o nota</label>
            <input type="search" id="h-q" name="q" value="<?= e($f_q) ?>" autocomplete="off">
        </div>
        <button type="submit" class="boton">Filtrar</button>
        <?php if ($f_fecha !== '' || $f_tipo !== '' || $f_q !== ''): ?>
            <a class="boton sec" href="movimientos.php">Ver todos</a>
        <?php endif; ?>
    </form>

    <?php if (!$movimientos): ?>
        <p class="vacio"><?= ($f_fecha !== '' || $f_tipo !== '' || $f_q !== '') ? 'No hay movimientos con ese filtro.' : 'Todavía no se registró ningún movimiento.' ?></p>
    <?php else: ?>
        <div class="tabla-wrap">
            <table class="tabla">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Artículo</th>
                        <th>Tipo</th>
                        <th class="num">Cantidad</th>
                        <th>Nota</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($movimientos as $m):
                        $es_entrada = $m['tipo'] === 'entrada';
                    ?>
                        <tr>
                            <td><?= e(dia_semana($m['fecha'])) ?> <?= e(fmt_fecha($m['fecha'])) ?></td>
                            <td><?= e($m['codigo'] . ' - ' . $m['nombre']) ?></td>
                            <td><span class="insignia <?= $es_entrada ? 'ent' : 'sal' ?>"><?= $es_entrada ? 'Entrada' : 'Salida' ?></span></td>
                            <td class="num <?= $es_entrada ? 'ent' : 'sal' ?>"><?= $es_entrada ? '+' : '-' ?><?= n($m['cantidad']) ?></td>
                            <td class="suave"><?= e($m['nota']) ?></td>
                            <td class="acc">
                                <form method="post" action="movimientos.php" class="en-linea"
                                      onsubmit="return confirm('¿Eliminar este movimiento?');">
                                    <?= csrf_campo() ?>
                                    <input type="hidden" name="accion" value="eliminar">
                                    <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                    <button type="submit" class="enlace peligro">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php paginacion($pagina, $paginas); ?>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/pie.php'; ?>
