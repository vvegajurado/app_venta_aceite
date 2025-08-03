<?php
// auth_check.php

// Incluir el archivo de conexión a la base de datos si es necesario para obtener roles por nombre
// Aunque en este caso, solo necesitamos el ID del rol de la sesión.
// include 'conexion.php'; 

// Iniciar la sesión si aún no está iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Verificar si el usuario ha iniciado sesión
if (!isset($_SESSION['user_id'])) {
    // Si no hay un ID de usuario en la sesión, redirigir a la página de login
    header("Location: login.php");
    exit(); // Terminar la ejecución del script para asegurar la redirección
}

// Definir los roles permitidos para cada página
// Asocia el nombre del archivo (sin .php) con un array de IDs de roles permitidos.
// Puedes obtener los IDs de los roles de tu tabla `roles` (1=Administrador, 2=Bodega, 3=Ventas, 4=Tesoreria, 5=Usuario)
$page_roles = [
    'index' => [1, 2, 3, 4, 5], // Todos los roles pueden ver el index
    'materias_primas' => [1, 2], // Administrador y Bodega
    'articulos' => [1, 2], // Administrador y Bodega
    'productos' => [1, 2, 3], // Administrador, Bodega y Ventas
    'depositos' => [1, 2], // Administrador y Bodega
    'entradas_granel' => [1, 2], // Administrador y Bodega
    'trasvases' => [1, 2], // Administrador y Bodega
    'lotes_envasado' => [1, 2], // Administrador y Bodega
    'actividad_envasado' => [1, 2], // Administrador y Bodega
    'informe_movimientos_lote' => [1, 2, 3], // Administrador, Bodega y Ventas
    'informe_desglose_lote_productos' => [1, 2, 3], // Administrador, Bodega y Ventas
    'pedidos' => [1, 3], // Administrador y Ventas
    'facturas_ventas' => [1, 3], // Administrador y Ventas
    'partes_ruta' => [1, 3], // Administrador y Ventas
    'informe_cobros_diarios' => [1, 4], // Administrador y Tesoreria
    'informe_movimientos_caja_varios' => [1, 4], // Administrador y Tesoreria
    'informes' => [1], // Solo Administrador
    'trazabilidad' => [1], // Solo Administrador
	'dashboard' => [1, 2, 3, 4, 5], // Todos los roles logueados pueden ver el dashboard
	'informe_cobros_diarios' => [1, 4], // Administrador y Tesoreria
    // Agrega aquí todas tus páginas y los roles que pueden acceder a ellas
    // Si una página no está en esta lista, por defecto solo el administrador podrá acceder
];

// Obtener el nombre del archivo de la página actual
$current_page = basename($_SERVER['PHP_SELF'], '.php');

// Obtener el ID del rol del usuario actual desde la sesión
$user_role_id = $_SESSION['id_role'] ?? null;

// Si el rol del usuario no está definido en la sesión (por alguna razón), redirigir al login
if (is_null($user_role_id)) {
    header("Location: login.php");
    exit();
}

// Verificar si la página actual tiene roles definidos
if (array_key_exists($current_page, $page_roles)) {
    // Si la página tiene roles definidos, verificar si el rol del usuario está permitido
    if (!in_array($user_role_id, $page_roles[$current_page])) {
        // Si el rol no está permitido, redirigir a una página de acceso denegado
        header("Location: unauthorized.php"); // Crea esta página o redirige a index.php con un mensaje de error
        exit();
    }
} else {
    // Si la página no está en la lista $page_roles, por defecto solo los administradores pueden acceder.
    // Esto es una medida de seguridad para páginas nuevas o no especificadas.
    $admin_role_id = 1; // Suponiendo que el ID del rol 'Administrador' es 1
    if ($user_role_id !== $admin_role_id) {
        header("Location: unauthorized.php");
        exit();
    }
}

// Opcional: Puedes añadir más verificaciones aquí si es necesario.
// No hay cierre de PHP al final para evitar problemas de salida inesperada.
