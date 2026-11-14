<?php
/* ==========================================================
   ARTÍCULOS
   Alta, edición y baja de artículos + listado con búsqueda.
   No hay límite de artículos (el TP pide como mínimo 100).
   ========================================================== */
require_once __DIR__ . '/includes/funciones.php';

/* ---------- 1) Procesar formularios (POST) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();
    $accion = post('accion');

    try {
        $pdo = db();

        if ($accion === 'guardar') {
            $id    = (int)post('id');
            $datos = [
                'id'            => post('id'),
                'codigo'        => strtoupper(post('codigo')),
                'nombre'        => post('nombre'),
                'categoria'     => post('categoria'),
                'stock_inicial' => post('stock_inicial'),
                'stock_minimo'  => post('stock_minimo'),
                'costo'         => post('costo'),
                'precio'        => post('precio'),
                'competencia'   => post('competencia'),
            ];
            $codigo    = $datos['codigo'];
            $nombre    = $datos['nombre'];
            $categoria = $datos['categoria'] !== '' ? $datos['categoria'] : 'Sin categoría';
            $stock_ini = filter_var($datos['stock_inicial'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            $stock_min = filter_var($datos['stock_minimo'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

            $costo       = decimal_valido($datos['costo'] === '' ? '0' : $datos['costo']);
            $precio      = decimal_valido($datos['precio'] === '' ? '0' : $datos['precio']);
            $competencia = decimal_valido($datos['competencia'] === '' ? '0' : $datos['competencia']);

            // Validaciones
            $errores = [];
            if ($costo === null || $precio === null || $competencia === null) {
                $errores[] = 'El costo y los precios tienen que ser importes de 0 en adelante.';
            }
            if ($codigo === '' || $nombre === '') {
                $errores[] = 'Completá el código y el nombre.';
            }
            if (mb_strlen($codigo) > 20 || mb_strlen($nombre) > 80 || mb_strlen($categoria) > 40) {
                $errores[] = 'Alguno de los textos es demasiado largo (código 20, nombre 80, categoría 40).';
            }
            if ($stock_ini === false || $stock_min === false) {
                $errores[] = 'El stock inicial y el mínimo tienen que ser números enteros desde 0.';
            }
            if (!$errores) {
                $st = $pdo->prepare('SELECT COUNT(*) FROM articulos WHERE codigo = :c AND id <> :id');
                $st->execute([':c' => $codigo, ':id' => $id]);
                if ($st->fetchColumn() > 0) {
                    $errores[] = 'Ya existe un artículo con el código ' . $codigo . '.';
                }
            }
            if (!$errores && $id > 0) {
                // Al cambiar el stock inicial no puede quedar una existencia negativa
                $neg = primer_dia_negativo($id, $stock_ini);
                if ($neg !== null) {
                    $errores[] = 'Con ese stock inicial la existencia quedaría negativa el ' . fmt_fecha($neg) . '.';
                }
            }
            if ($errores) {
                foreach ($errores as $mensaje) {
                    flash('error', $mensaje);
                }
                $_SESSION['viejo'] = $datos; // para no perder lo que escribió
                redirigir('articulos.php' . ($id > 0 ? '?editar=' . $id : ''));
            }

            // Guardar
            if ($id > 0) {
                $st = $pdo->prepare(
                    'UPDATE articulos
                     SET codigo = :codigo, nombre = :nombre, categoria = :cat, stock_inicial = :ini, stock_minimo = :min,
                         costo_unitario = :costo, precio_venta = :precio, precio_competencia = :comp
                     WHERE id = :id'
                );
                $st->execute([':codigo' => $codigo, ':nombre' => $nombre, ':cat' => $categoria,
                              ':ini' => $stock_ini, ':min' => $stock_min, ':costo' => $costo,
                              ':precio' => $precio, ':comp' => $competencia, ':id' => $id]);
                flash('ok', 'Cambios guardados en "' . $nombre . '".');
            } else {
                $st = $pdo->prepare(
                    'INSERT INTO articulos (codigo, nombre, categoria, stock_inicial, stock_minimo, costo_unitario, precio_venta, precio_competencia)
                     VALUES (:codigo, :nombre, :cat, :ini, :min, :costo, :precio, :comp)'
                );
                $st->execute([':codigo' => $codigo, ':nombre' => $nombre, ':cat' => $categoria,
                              ':ini' => $stock_ini, ':min' => $stock_min, ':costo' => $costo,
                              ':precio' => $precio, ':comp' => $competencia]);
                flash('ok', 'Artículo "' . $nombre . '" guardado.');
            }
            redirigir('articulos.php');

        } elseif ($accion === 'eliminar') {
            // Los movimientos del artículo se borran solos (ON DELETE CASCADE)
            $st = $pdo->prepare('DELETE FROM articulos WHERE id = :id');
            $st->execute([':id' => (int)post('id')]);
            flash('ok', 'Artículo eliminado junto con sus movimientos.');
            redirigir('articulos.php');
        }
    } catch (PDOException $ex) {
        flash('error', 'Error en la base de datos: ' . $ex->getMessage());
        redirigir('articulos.php');
    }
    redirigir('articulos.php');
}

/* ---------- 2) Mostrar la página (GET) ---------- */
$titulo = 'Artículos';
$activo = 'articulos.php';
require __DIR__ . '/includes/cabecera.php';

// ¿Se está editando un artículo o cargando uno nuevo?
$editar = null;
if (get('editar') !== '') {
    $st = $pdo->prepare('SELECT * FROM articulos WHERE id = :id');
    $st->execute([':id' => (int)get('editar')]);
    $editar = $st->fetch() ?: null;
}
$viejo = $_SESSION['viejo'] ?? null;
unset($_SESSION['viejo']);

if ($viejo) {
    $form = $viejo;
} elseif ($editar) {
    $form = [
        'id'            => $editar['id'],
        'codigo'        => $editar['codigo'],
        'nombre'        => $editar['nombre'],
        'categoria'     => $editar['categoria'],
        'stock_inicial' => $editar['stock_inicial'],
        'stock_minimo'  => $editar['stock_minimo'],
        'costo'         => $editar['costo_unitario'],
        'precio'        => $editar['precio_venta'],
        'competencia'   => $editar['precio_competencia'],
    ];
} else {
    $form = ['id' => '', 'codigo' => proximo_codigo(), 'nombre' => '', 'categoria' => '',
             'stock_inicial' => '0', 'stock_minimo' => '5',
             'costo' => '0', 'precio' => '0', 'competencia' => '0'];
}
$editando = (int)$form['id'] > 0;

$categorias = $pdo->query('SELECT DISTINCT categoria FROM articulos ORDER BY categoria')->fetchAll(PDO::FETCH_COLUMN);

// Filtros del listado
$q   = get('q');
$cat = get('categoria');
$donde = [];
$par   = [];
if ($q !== '') {
    $donde[] = '(a.codigo LIKE :q1 OR a.nombre LIKE :q2)';
    $par[':q1'] = $par[':q2'] = '%' . $q . '%';
}
if ($cat !== '') {
    $donde[] = 'a.categoria = :cat';
    $par[':cat'] = $cat;
}
$sql_donde = $donde ? 'WHERE ' . implode(' AND ', $donde) : '';

$st = $pdo->prepare("SELECT COUNT(*) FROM articulos a $sql_donde");
$st->execute($par);
$total = (int)$st->fetchColumn();
$total_general = (int)$pdo->query('SELECT COUNT(*) FROM articulos')->fetchColumn();

[$pagina, $paginas, $offset] = paginar($total, 20);

$st = $pdo->prepare(
    "SELECT a.*,
            a.stock_inicial + COALESCE((SELECT SUM(IF(m.tipo = 'entrada', m.cantidad, -m.cantidad))
                                        FROM movimientos m
                                        WHERE m.articulo_id = a.id AND m.fecha <= :hoy), 0) AS existencia
     FROM articulos a
     $sql_donde
     ORDER BY a.codigo
     LIMIT 20 OFFSET " . (int)$offset
);
$st->execute($par + [':hoy' => $hoy]);
$articulos = $st->fetchAll();
?>

<section class="tarjeta" id="formulario">
    <div class="tarjeta-cab">
        <h2><?= $editando ? 'Editar artículo' : 'Nuevo artículo' ?></h2>
    </div>
    <form method="post" action="articulos.php" class="formulario">
        <?= csrf_campo() ?>
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id" value="<?= e($form['id']) ?>">

        <div class="campo">
            <label for="codigo">Código</label>
            <input type="text" id="codigo" name="codigo" maxlength="20" required value="<?= e($form['codigo']) ?>">
        </div>
        <div class="campo campo-ancho">
            <label for="nombre">Nombre del artículo</label>
            <input type="text" id="nombre" name="nombre" maxlength="80" required value="<?= e($form['nombre']) ?>">
        </div>
        <div class="campo">
            <label for="categoria">Categoría</label>
            <input type="text" id="categoria" name="categoria" maxlength="40" list="lista-categorias" autocomplete="off" value="<?= e($form['categoria']) ?>">
            <datalist id="lista-categorias">
                <?php foreach ($categorias as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?>
            </datalist>
        </div>
        <div class="campo">
            <label for="stock_inicial">Stock inicial</label>
            <input type="number" id="stock_inicial" name="stock_inicial" min="0" step="1" required inputmode="numeric" value="<?= e($form['stock_inicial']) ?>">
        </div>
        <div class="campo">
            <label for="stock_minimo">Stock mínimo (aviso)</label>
            <input type="number" id="stock_minimo" name="stock_minimo" min="0" step="1" required inputmode="numeric" value="<?= e($form['stock_minimo']) ?>">
        </div>
        <div class="campo">
            <label for="costo">Costo de compra ($)</label>
            <input type="number" id="costo" name="costo" min="0" step="0.01" inputmode="decimal" value="<?= e($form['costo']) ?>">
        </div>
        <div class="campo">
            <label for="precio">Precio de venta ($)</label>
            <input type="number" id="precio" name="precio" min="0" step="0.01" inputmode="decimal" value="<?= e($form['precio']) ?>">
        </div>
        <div class="campo">
            <label for="competencia">Precio de la competencia ($)</label>
            <input type="number" id="competencia" name="competencia" min="0" step="0.01" inputmode="decimal" value="<?= e($form['competencia']) ?>">
        </div>

        <div class="acciones campo-completo">
            <button type="submit" class="boton"><?= $editando ? 'Guardar cambios' : 'Guardar artículo' ?></button>
            <?php if ($editando): ?>
                <a class="boton sec" href="articulos.php">Cancelar edición</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<section class="tarjeta">
    <div class="tarjeta-cab">
        <h2>Lista de artículos</h2>
        <p class="suave"><?= n($total_general) ?> <?= $total_general === 1 ? 'artículo cargado' : 'artículos cargados' ?></p>
    </div>

    <form method="get" class="filtros">
        <div class="campo">
            <label for="q">Buscar por código o nombre</label>
            <input type="search" id="q" name="q" value="<?= e($q) ?>" autocomplete="off">
        </div>
        <div class="campo">
            <label for="filtro-cat">Categoría</label>
            <select id="filtro-cat" name="categoria">
                <option value="">Todas</option>
                <?php foreach ($categorias as $c): ?>
                    <option value="<?= e($c) ?>"<?= $c === $cat ? ' selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="boton">Filtrar</button>
        <?php if ($q !== '' || $cat !== ''): ?>
            <a class="boton sec" href="articulos.php">Ver todos</a>
        <?php endif; ?>
    </form>

    <?php if (!$articulos): ?>
        <p class="vacio"><?= $total_general ? 'Ningún artículo coincide con la búsqueda.' : 'Todavía no hay artículos. Cargá el primero en el formulario de arriba.' ?></p>
    <?php else: ?>
        <div class="tabla-wrap">
            <table class="tabla">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Artículo</th>
                        <th>Categoría</th>
                        <th class="num">Stock inicial</th>
                        <th class="num">Existencia hoy</th>
                        <th class="num">Mínimo</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($articulos as $a):
                        $existencia = (int)$a['existencia'];
                    ?>
                        <tr>
                            <td><?= e($a['codigo']) ?></td>
                            <td><?= e($a['nombre']) ?></td>
                            <td><?= e($a['categoria']) ?></td>
                            <td class="num"><?= n($a['stock_inicial']) ?></td>
                            <td class="num">
                                <b><?= n($existencia) ?></b>
                                <?php if ($existencia <= (int)$a['stock_minimo']): ?><span class="insignia bajo">Stock bajo</span><?php endif; ?>
                            </td>
                            <td class="num"><?= n($a['stock_minimo']) ?></td>
                            <td class="acc">
                                <a class="enlace" href="articulos.php?editar=<?= (int)$a['id'] ?>#formulario">Editar</a>
                                <form method="post" action="articulos.php" class="en-linea"
                                      onsubmit="return confirm('¿Eliminar este artículo y todos sus movimientos?');">
                                    <?= csrf_campo() ?>
                                    <input type="hidden" name="accion" value="eliminar">
                                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
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
