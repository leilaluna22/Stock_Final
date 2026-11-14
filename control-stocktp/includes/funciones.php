<?php
/* ==========================================================
   FUNCIONES COMPARTIDAS POR TODAS LAS PÁGINAS
   ========================================================== */
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const DIAS_SEMANA = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

/* ---------- Texto y fechas ---------- */

// Escapa texto antes de mostrarlo en HTML (evita XSS)
function e($valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function hoy(): string
{
    return date('Y-m-d');
}

function dia_semana(string $fecha): string
{
    return DIAS_SEMANA[(int)date('w', strtotime($fecha))];
}

function fmt_fecha(string $fecha): string
{
    return date('d/m/Y', strtotime($fecha));
}

function fmt_corta(string $fecha): string
{
    return date('d/m', strtotime($fecha));
}

// Número entero con separador de miles: 1.250
function n($numero): string
{
    return number_format((int)$numero, 0, ',', '.');
}

function fecha_valida(string $fecha): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $fecha);
    return $d && $d->format('Y-m-d') === $fecha;
}

function sumar_dias(string $fecha, int $dias): string
{
    $d = new DateTime($fecha);
    $d->modify(($dias >= 0 ? '+' : '') . $dias . ' days');
    return $d->format('Y-m-d');
}

/* ---------- Entrada de datos, avisos y seguridad ---------- */

// Lee un campo de $_POST como texto sin espacios en los bordes
function post(string $campo): string
{
    $v = $_POST[$campo] ?? '';
    return is_string($v) ? trim($v) : '';
}

// Lee un parámetro de la URL ($_GET)
function get(string $campo): string
{
    $v = $_GET[$campo] ?? '';
    return is_string($v) ? trim($v) : '';
}

function redirigir(string $url)
{
    header('Location: ' . $url);
    exit;
}

// Mensajes que se muestran una sola vez después de guardar / fallar
function flash(string $tipo, string $mensaje): void
{
    $_SESSION['flash'][] = ['tipo' => $tipo, 'msg' => $mensaje];
}

function mostrar_flash(): void
{
    foreach ($_SESSION['flash'] ?? [] as $f) {
        echo '<div class="aviso aviso-' . e($f['tipo']) . '" role="status">' . e($f['msg']) . '</div>';
    }
    unset($_SESSION['flash']);
}

// Token anti-CSRF: cada formulario POST lo incluye y se verifica al recibirlo
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_campo(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verificar(): void
{
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        flash('error', 'La sesión venció. Volvé a intentarlo.');
        redirigir(basename($_SERVER['PHP_SELF']));
    }
}

/* ---------- Datos del comercio ---------- */

function datos_comercio(): array
{
    $fila = db()->query('SELECT * FROM comercio WHERE id = 1')->fetch();
    return $fila ?: ['nombre' => 'Mi comercio', 'descripcion' => '', 'integrantes' => '', 'ingreso' => ''];
}

/* ---------- Existencias ----------
   Existencia al cierre de un día =
   stock inicial + entradas - salidas acumuladas hasta ese día.
   ---------------------------------------------------------- */

// Devuelve todos los artículos con: anterior, entrada, salida y existencia de un día
function existencias_al(string $fecha): array
{
    $sql = "SELECT a.id, a.codigo, a.nombre, a.categoria, a.stock_inicial, a.stock_minimo,
                a.stock_inicial + COALESCE(SUM(CASE WHEN m.fecha < :f1
                                                    THEN IF(m.tipo = 'entrada', m.cantidad, -m.cantidad) END), 0) AS anterior,
                COALESCE(SUM(CASE WHEN m.fecha = :f2 AND m.tipo = 'entrada' THEN m.cantidad END), 0) AS entrada,
                COALESCE(SUM(CASE WHEN m.fecha = :f3 AND m.tipo = 'salida'  THEN m.cantidad END), 0) AS salida
            FROM articulos a
            LEFT JOIN movimientos m ON m.articulo_id = a.id AND m.fecha <= :f4
            GROUP BY a.id, a.codigo, a.nombre, a.categoria, a.stock_inicial, a.stock_minimo
            ORDER BY a.codigo";
    $st = db()->prepare($sql);
    $st->execute([':f1' => $fecha, ':f2' => $fecha, ':f3' => $fecha, ':f4' => $fecha]);

    $filas = [];
    foreach ($st->fetchAll() as $r) {
        $r['anterior']      = (int)$r['anterior'];
        $r['entrada']       = (int)$r['entrada'];
        $r['salida']        = (int)$r['salida'];
        $r['stock_minimo']  = (int)$r['stock_minimo'];
        $r['existencia']    = $r['anterior'] + $r['entrada'] - $r['salida'];
        $filas[] = $r;
    }
    return $filas;
}

// Existencia de un solo artículo al cierre de un día
function existencia_articulo(int $id, string $fecha): int
{
    $st = db()->prepare(
        "SELECT a.stock_inicial + COALESCE((SELECT SUM(IF(m.tipo = 'entrada', m.cantidad, -m.cantidad))
                                            FROM movimientos m
                                            WHERE m.articulo_id = a.id AND m.fecha <= :f), 0)
         FROM articulos a WHERE a.id = :id"
    );
    $st->execute([':f' => $fecha, ':id' => $id]);
    return (int)$st->fetchColumn();
}

/**
 * Revisa que la existencia de un artículo nunca quede negativa.
 * Devuelve la primera fecha en la que quedaría negativa, o null si todo está bien.
 *  - $nuevo:       movimiento que se quiere agregar (fecha, tipo, cantidad)
 *  - $excluir_mov: id de un movimiento que se quiere eliminar
 */
function primer_dia_negativo(int $articulo_id, int $stock_inicial, ?array $nuevo = null, ?int $excluir_mov = null): ?string
{
    $sql = "SELECT fecha, SUM(IF(tipo = 'entrada', cantidad, -cantidad)) AS neto
            FROM movimientos WHERE articulo_id = :id";
    $par = [':id' => $articulo_id];
    if ($excluir_mov !== null) {
        $sql .= ' AND id <> :ex';
        $par[':ex'] = $excluir_mov;
    }
    $sql .= ' GROUP BY fecha';

    $st = db()->prepare($sql);
    $st->execute($par);

    $por_dia = [];
    foreach ($st->fetchAll() as $r) {
        $por_dia[$r['fecha']] = (int)$r['neto'];
    }
    if ($nuevo !== null) {
        $delta = $nuevo['tipo'] === 'entrada' ? $nuevo['cantidad'] : -$nuevo['cantidad'];
        $por_dia[$nuevo['fecha']] = ($por_dia[$nuevo['fecha']] ?? 0) + $delta;
    }
    ksort($por_dia);

    $saldo = $stock_inicial;
    foreach ($por_dia as $fecha => $neto) {
        $saldo += $neto;
        if ($saldo < 0) {
            return (string)$fecha;
        }
    }
    return null;
}

// Próximo código libre con el formato ART-001
function proximo_codigo(): string
{
    $max = db()->query(
        "SELECT MAX(CAST(SUBSTRING(codigo, 5) AS UNSIGNED)) FROM articulos WHERE codigo REGEXP '^ART-[0-9]+$'"
    )->fetchColumn();
    return 'ART-' . str_pad((string)((int)$max + 1), 3, '0', STR_PAD_LEFT);
}

/* ---------- Paginación ---------- */

// Devuelve [página actual, cantidad de páginas, offset para el LIMIT]
function paginar(int $total, int $por_pagina): array
{
    $paginas = max(1, (int)ceil($total / $por_pagina));
    $pagina  = min(max(1, (int)($_GET['p'] ?? 1)), $paginas);
    return [$pagina, $paginas, ($pagina - 1) * $por_pagina];
}

// Arma una URL conservando los filtros actuales y cambiando algunos parámetros
function url_con(array $cambios): string
{
    $q = array_merge($_GET, $cambios);
    foreach ($q as $k => $v) {
        if ($v === '' || $v === null) {
            unset($q[$k]);
        }
    }
    return '?' . http_build_query($q);
}

function paginacion(int $pagina, int $paginas): void
{
    if ($paginas <= 1) {
        return;
    }
    echo '<nav class="paginacion" aria-label="Páginas">';
    if ($pagina > 1) {
        echo '<a href="' . e(url_con(['p' => $pagina - 1])) . '">Anterior</a>';
    }
    echo '<span>Página ' . $pagina . ' de ' . $paginas . '</span>';
    if ($pagina < $paginas) {
        echo '<a href="' . e(url_con(['p' => $pagina + 1])) . '">Siguiente</a>';
    }
    echo '</nav>';
}

/* ---------- Dinero y períodos (módulos Administración y Personal) ---------- */

// Retenciones sobre el sueldo (porcentajes de ejemplo, se pueden cambiar acá)
const RETENCIONES     = ['Jubilación' => 11, 'Obra social' => 3, 'Ley 19.032 (PAMI)' => 3, 'Sindicato' => 2];
const PRESENTISMO_PCT = 8.33; // adicional si no hubo ausencias en el mes

const MESES = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

// Importe en pesos: $ 1.250,50
function m($valor): string
{
    return '$ ' . number_format((float)$valor, 2, ',', '.');
}

// Lee un importe (acepta coma o punto). Devuelve null si no es válido.
function decimal_valido(string $v): ?float
{
    $v = str_replace(',', '.', trim($v));
    if ($v === '' || !is_numeric($v) || (float)$v < 0 || (float)$v > 99999999) {
        return null;
    }
    return round((float)$v, 2);
}

function periodo_valido(string $p): bool
{
    return (bool)preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $p);
}

function nombre_periodo(string $p): string
{
    return MESES[(int)substr($p, 5, 2)] . ' ' . substr($p, 0, 4);
}

// [primer día, último día] de un período AAAA-MM
function rango_periodo(string $p): array
{
    return [$p . '-01', date('Y-m-t', strtotime($p . '-01'))];
}

function sumar_meses(string $p, int $n): string
{
    return date('Y-m', strtotime($p . '-01 ' . ($n >= 0 ? '+' : '') . $n . ' months'));
}

/* ---------- Íconos (SVG simples) ---------- */

function icono(string $nombre): string
{
    $trazos = [
        'panel'       => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'articulos'   => '<path d="M21 8l-9-5-9 5v8l9 5 9-5V8z"/><path d="M3 8l9 5 9-5"/><path d="M12 13v8"/>',
        'movimientos' => '<path d="M7 4L3 8l4 4"/><path d="M3 8h14"/><path d="M17 20l4-4-4-4"/><path d="M21 16H7"/>',
        'control'     => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18"/><path d="M8 3v4"/><path d="M16 3v4"/>',
        'resumen'     => '<path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/>',
        'comercio'    => '<path d="M3 9l1.5-5h15L21 9"/><path d="M3 9v11h18V9"/><path d="M3 9h18"/><path d="M9 20v-6h6v6"/>',
        'alerta'      => '<path d="M12 3l10 18H2L12 3z"/><path d="M12 10v5"/><path d="M12 18h.01"/>',
        'unidades'    => '<path d="M4 7l8-4 8 4-8 4-8-4z"/><path d="M4 12l8 4 8-4"/><path d="M4 17l8 4 8-4"/>',
        'administracion' => '<circle cx="12" cy="12" r="9"/><path d="M14.5 9.2c-.5-.8-1.5-1.2-2.5-1.2-1.4 0-2.5.8-2.5 2s1.1 1.7 2.5 2 2.5.8 2.5 2-1.1 2-2.5 2c-1 0-2-.4-2.5-1.2"/><path d="M12 6v2"/><path d="M12 16v2"/>',
        'personal'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><path d="M16 4.6a3.5 3.5 0 010 6.8"/><path d="M18 14.3c2 .7 3.5 2.5 3.5 5.7"/>',
        'manual'      => '<path d="M4 4.5A2.5 2.5 0 016.5 2H20v17H6.5A2.5 2.5 0 004 21.5v-17z"/><path d="M4 21.5A2.5 2.5 0 006.5 19H20"/><path d="M9 7h7"/>',
        'hoy'         => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    ];
    return '<svg class="icono" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
         . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($trazos[$nombre] ?? '') . '</svg>';
}
