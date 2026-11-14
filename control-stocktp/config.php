<?php
/* ==========================================================
   CONFIGURACIÓN DE LA BASE DE DATOS
   Con XAMPP los valores por defecto son estos:
   usuario "root" y sin contraseña.
   ========================================================== */
define('DB_HOST', 'sql205.infinityfree.com');
define('DB_NAME', 'if0_42831551_control_stock');
define('DB_USER', 'if0_42831551');
define('DB_PASS', '1j2o3a4c5o');

date_default_timezone_set('America/Argentina/Buenos_Aires');

/**
 * Devuelve la conexión a la base de datos (se crea una sola vez).
 * Usa PDO con consultas preparadas para evitar la inyección SQL.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }

    return $pdo;
}
