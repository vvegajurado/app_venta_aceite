<?php
$host = 'db';                 // Nombre del servicio de la base de datos en Docker Compose
$db   = 'ventasaceite';      // ¡Importante! Asegúrate de que el nombre de la base de datos coincida
$user = 'admin';             // Tu usuario de MySQL definido en docker-compose.yml
$pass = 'Vicen050270';       // Tu contraseña de MySQL definida en docker-compose.yml
$charset = 'utf8mb4';         // Codificación de caracteres para evitar problemas con acentos y eñes

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,      // Lanza excepciones en caso de error
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,            // Recupera los resultados como arrays asociativos
    PDO::ATTR_EMULATE_PREPARES   => false,                       // Deshabilita la emulación de prepared statements (mejor rendimiento y seguridad)
];

// Variable para almacenar el error de conexión a la base de datos
$pdo_error = null;

try {
    // Crea una nueva instancia de PDO y la asigna a la variable $pdo
    $pdo = new PDO($dsn, $user, $pass, $options);
    // Si quieres verificar la conexión, puedes descomentar la siguiente línea
    // echo "Conexión a la base de datos PDO exitosa.";
} catch (\PDOException $e) {
    // Si la conexión falla, se captura la excepción y se almacena el mensaje de error.
    // No se usa die() para evitar romper las respuestas JSON en llamadas AJAX.
    $pdo_error = "Error de conexión a la base de datos: " . $e->getMessage();
    // En un entorno de producción real, deberías registrar este error en un log
    // error_log($pdo_error);
}