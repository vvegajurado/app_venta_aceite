<?php
// Incluir el archivo de verificación de autenticación AL PRINCIPIO para asegurar que la sesión se inicie primero.
// Esto es crucial para evitar el error "headers already sent" si sidebar.php se incluye antes de session_start().
include 'auth_check.php';

// Helper function to check if a page is the current one
function is_current_page($page_name) {
    return basename($_SERVER['PHP_SELF']) == $page_name;
}

// Define menu structure and associated files for dynamic active/expanded states
// Los elementos se definen en el orden deseado para la visualización.
$menu_items = [
    'terceros' => [
        'name' => 'Clientes y Proveedores', // Nombre cambiado
        'icon' => 'bi-people',
        'pages' => [
            'clientes.php',
            'proveedores.php'
        ],
        'sub_links' => [
            ['href' => 'clientes.php', 'text' => 'Clientes'],
            ['href' => 'proveedores.php', 'text' => 'Proveedores']
        ]
    ],
    'ventas' => [
        'name' => 'Ventas',
        'icon' => 'bi-cash-coin',
        'pages' => [
            'pedidos.php',
            'facturas_ventas.php',
            'partes_ruta.php',
            'partes_paqueteria.php' // NUEVO: Añadir partes_paqueteria.php a las páginas de la sección
        ],
        'sub_links' => [
            ['href' => 'pedidos.php', 'text' => 'Pedidos'],
            ['href' => 'facturas_ventas.php', 'text' => 'Facturas de Ventas'],
            ['href' => 'partes_ruta.php', 'text' => 'Partes de Ruta'],
            ['href' => 'partes_paqueteria.php', 'text' => 'Partes de Paquetería'] // NUEVO: Enlace para Partes de Paquetería
        ]
    ],
    'tesoreria' => [
        'name' => 'Tesoreria',
        'icon' => 'bi-wallet-fill',
        'pages' => [
            'informe_cobros_diarios.php',
            'informe_movimientos_caja_varios.php'
        ],
        'sub_links' => [
            ['href' => 'informe_cobros_diarios.php', 'text' => 'Cobros Diarios'],
            ['href' => 'informe_movimientos_caja_varios.php', 'text' => 'Movimientos Caja Varios']
        ]
    ],
    'maestros' => [
        'name' => 'Maestros',
        'icon' => 'bi-briefcase',
        'pages' => [
            'materias_primas.php',
            'articulos.php',
            'productos.php'
        ],
        'sub_links' => [
            ['href' => 'materias_primas.php', 'text' => 'Materia Prima'],
            ['href' => 'articulos.php', 'text' => 'Artículos'],
            ['href' => 'productos.php', 'text' => 'Productos']
        ]
    ],
    'bodega' => [
        'name' => 'Bodega',
        'icon' => 'bi-barrel',
        'pages' => [
            'depositos.php',
            'entradas_granel.php',
            'trasvases.php',
            'lotes_envasado.php',
        ],
        'sub_links' => [
            ['href' => 'depositos.php', 'text' => 'Gestión de Depósitos'],
            ['href' => 'entradas_granel.php', 'text' => 'Entradas a Granel'],
            ['href' => 'trasvases.php', 'text' => 'Trasvases'],
            ['href' => 'lotes_envasado.php', 'text' => 'Preparación de Lotes']
        ]
    ],
    'envasado' => [
        'name' => 'Envasado',
        'icon' => 'bi-box-seam',
        'pages' => [
            'actividad_envasado.php',
            'informe_movimientos_lote.php'
        ],
        'sub_links' => [
            ['href' => 'actividad_envasado.php', 'text' => 'Actividad de Envasado'],
            ['href' => 'informe_movimientos_lote.php', 'text' => 'Existencias A. Envasados']
        ]
    ],
    'informes' => [
        'name' => 'Informes',
        'icon' => 'bi-graph-up',
        'pages' => [
            'informe_facturas_emitidas.php',
            'informe_litros_vendidos_por_producto.php',
            'informe_totales_diarios_facturas.php' // NUEVO: Informe de Totales Diarios de Facturas
        ],
        'sub_links' => [
            ['href' => 'informe_facturas_emitidas.php', 'text' => 'Facturas Emitidas'],
            ['href' => 'informe_litros_vendidos_por_producto.php', 'text' => 'Litros Vendidos por Producto'],
            ['href' => 'informe_totales_diarios_facturas.php', 'text' => 'Totales Diarios Facturas'] // NUEVO: Enlace al informe
        ]
    ],
    'trazabilidad_main' => [
        'name' => 'Trazabilidad',
        'icon' => 'bi-diagram-3',
        'pages' => ['trazabilidad.php'],
        'sub_links' => [
            ['href' => 'trazabilidad.php', 'text' => 'Trazabilidad de Aceite']
        ]
    ],
    'administracion' => [
        'name' => 'Administración',
        'icon' => 'bi-tools',
        'pages' => [
            'gestion_usuarios.php',
        ],
        'sub_links' => [
            ['href' => 'gestion_usuarios.php', 'text' => 'Gestión de Usuarios']
        ],
        'roles_allowed' => [1]
    ]
];

// Iniciar sesión si no está iniciada (necesario para $_SESSION['id_role'])
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$current_page = basename($_SERVER['PHP_SELF']);
$user_role_id = $_SESSION['id_role'] ?? null;

// Determine which parent menu should be active and expanded
$active_parent_menus = [];
foreach ($menu_items as $id => $menu) {
    // Solo considerar la sección si el usuario tiene permiso para verla
    $show_section_for_active_check = true;
    if (isset($menu['roles_allowed'])) {
        $show_section_for_active_check = in_array($user_role_id, $menu['roles_allowed']);
    }

    if ($show_section_for_active_check) {
        foreach ($menu['pages'] as $page) {
            if (is_current_page($page)) {
                $active_parent_menus[$id] = true;
                break;
            }
        }
    }
}
?>

<div class="sidebar">
    <div class="sidebar-header">
        <?php
        // Ruta al logotipo (asegúrate de que esta ruta sea correcta para tu archivo)
        $logo_path = 'assets/img/logo.png';

        // Verifica si el logotipo existe antes de mostrarlo
        if (file_exists($logo_path)) {
            echo '<img src="' . htmlspecialchars($logo_path) . '" alt="Logo de la aplicación" class="app-logo">';
        } else {
            // Si el logo no existe, muestra el icono de la gota de aceite como fallback
            echo '<i class="bi bi-droplet-fill app-icon"></i>';
        }
        ?>
        <div class="app-name">Gestión de Aceite</div>
    </div>
    <ul class="sidebar-menu">
        <li class="sidebar-menu-item">
            <a href="index.php" class="sidebar-menu-link <?php echo is_current_page('index.php') ? 'active' : ''; ?>">
                <i class="bi bi-house-door"></i> <span>Inicio</span>
            </a>
        </li>
        <li class="sidebar-menu-item">
            <a href="dashboard.php" class="sidebar-menu-link <?php echo is_current_page('dashboard.php') ? 'active' : ''; ?>">
                <i class="bi bi-speedometer"></i> <span>Dashboard</span>
            </a>
        </li>

        <?php foreach ($menu_items as $id => $item): ?>
            <?php
            // Determinar si la sección debe mostrarse según los roles
            $show_section = true;
            if (isset($item['roles_allowed'])) {
                $show_section = in_array($user_role_id, $item['roles_allowed']);
            }

            if ($show_section):
                // Si es un elemento de menú principal sin subenlaces, renderizarlo directamente
                if (empty($item['sub_links']) || (count($item['sub_links']) === 1 && $item['sub_links'][0]['href'] === $item['pages'][0])) {
                    $is_active = is_current_page($item['pages'][0]);
                    ?>
                    <li class="sidebar-menu-item">
                        <a href="<?php echo htmlspecialchars($item['pages'][0]); ?>" class="sidebar-menu-link <?php echo $is_active ? 'active' : ''; ?>">
                            <i class="bi <?php echo $item['icon']; ?>"></i> <span><?php echo $item['name']; ?></span>
                        </a>
                    </li>
                    <?php
                } else {
                    // Si tiene subenlaces o es un menú colapsable
                    $is_active_section = isset($active_parent_menus[$id]) ? true : false;
                    $aria_expanded = $is_active_section ? 'true' : 'false';
                    $collapse_show = $is_active_section ? 'show' : '';
                    ?>
                    <li class="sidebar-menu-item">
                        <a class="sidebar-menu-link <?php echo ($is_active_section ? 'active' : ''); ?>"
                           data-bs-toggle="collapse"
                           href="#submenu<?php echo ucfirst($id); ?>"
                           role="button"
                           aria-expanded="<?php echo $aria_expanded; ?>"
                           aria-controls="submenu<?php echo ucfirst($id); ?>">
                            <i class="bi <?php echo $item['icon']; ?>"></i> <span><?php echo $item['name']; ?></span>
                            <i class="bi bi-chevron-down ms-auto sidebar-collapse-icon"></i>
                        </a>
                        <div class="collapse <?php echo $collapse_show; ?>" id="submenu<?php echo ucfirst($id); ?>">
                            <ul class="sidebar-submenu-list list-unstyled fw-normal pb-1 small">
                                <?php foreach ($item['sub_links'] as $sub_link): ?>
                                    <li class="sidebar-submenu-item">
                                        <a href="<?php echo htmlspecialchars($sub_link['href']); ?>"
                                           class="sidebar-submenu-link <?php echo is_current_page($sub_link['href']) ? 'active' : ''; ?>">
                                            <?php echo htmlspecialchars($sub_link['text']); ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </li>
                <?php
                }
            endif;
            ?>
        <?php endforeach; ?>

        <li class="sidebar-menu-item mt-auto">
            <a href="logout.php" class="sidebar-menu-link">
                <i class="bi bi-box-arrow-right"></i> <span>Cerrar Sesión</span>
            </a>
        </li>
    </ul>
</div>

<style>
    /* Estilos generales del sidebar */
    :root {
        --sidebar-bg: #2c3e50; /* Azul oscuro */
        --sidebar-link: #ecf0f1; /* Gris claro */
        --sidebar-hover: #34495e; /* Azul más oscuro al pasar el ratón */
        --primary-color: #0056b3; /* Azul primario, consistente con otros archivos */
        --primary-dark: #004494;
    }

    .sidebar {
        width: 250px;
        background-color: var(--sidebar-bg);
        color: var(--sidebar-link);
        padding: 20px;
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        box-shadow: 2px 0 5px rgba(0, 0, 0, 0.1);
        transition: width 0.3s ease;
    }

    .wrapper.sidebar-collapsed .sidebar {
        width: 0;
        padding-left: 0;
        padding-right: 0;
        overflow: hidden;
    }

    .sidebar-header {
        text-align: center;
        padding-bottom: 20px;
        margin-bottom: 20px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .sidebar-logo {
        font-size: 3rem;
        color: #4CAF50; /* Verde oliva/aceite */
        margin-bottom: 10px;
    }

    .sidebar-header h3 {
        color: white;
        margin: 0;
        font-weight: 700;
    }

    .app-name { /* Asegúrate de que esta clase exista en tu HTML o cámbiala por la correcta */
        color: white; /* Color del texto del nombre de la aplicación */
        font-size: 1.5rem; /* Tamaño de fuente */
        font-weight: 700; /* Negrita */
    }


    .sidebar-menu {
        list-style: none;
        padding: 0;
        margin: 0;
        flex-grow: 1; /* Permite que el menú ocupe el espacio disponible */
        display: flex;
        flex-direction: column;
    }

    .sidebar-menu-item {
        margin-bottom: 5px;
    }

    .sidebar-menu-link {
        display: flex;
        align-items: center;
        padding: 10px 15px;
        color: var(--sidebar-link);
        text-decoration: none;
        border-radius: 8px;
        transition: background-color 0.3s ease, color 0.3s ease, border-left-color 0.3s ease;
        border-left: 5px solid transparent; /* Para el indicador activo */
    }

    .sidebar-menu-link i {
        font-size: 1.2rem;
        margin-right: 10px;
    }

    .sidebar-menu-link:hover, .sidebar-menu-link.active {
        background-color: var(--sidebar-hover);
        color: white;
        border-left-color: var(--primary-color);
    }

    /* Ajuste del icono de flecha del colapsable */
    .sidebar-menu-link .sidebar-collapse-icon {
        transition: transform 0.3s ease;
    }
    .sidebar-menu-link[aria-expanded="true"] .sidebar-collapse-icon {
        transform: rotate(180deg);
    }

    /* Estilo para empujar el elemento de cerrar sesión al final */
    .sidebar-menu-item.mt-auto {
        margin-top: auto !important; /* Empuja este elemento al final de la flexbox */
        border-top: 1px solid rgba(255, 255, 255, 0.1); /* Separador visual */
        padding-top: 10px;
    }

    /* Submenú */
    .sidebar-submenu-item {
        list-style: none;
    }

    .sidebar-submenu-link {
        display: block;
        padding: 8px 15px 8px 45px; /* Indentación para submenú */
        color: var(--sidebar-link);
        text-decoration: none;
        border-radius: 8px;
        transition: background-color 0.3s ease, color 0.3s ease, border-left-color 0.3s ease;
        border-left: 5px solid transparent;
        font-size: 0.95rem;
    }

    .sidebar-submenu-link:hover, .sidebar-submenu-link.active {
        background-color: var(--sidebar-hover);
        color: white;
        border-left-color: var(--primary-color);
    }

    /* Estilos para el logo de la aplicación */
    .sidebar-header .app-logo {
        max-width: 80px !important; /* Reducido para que sea más pequeño y con prioridad */
        width: 100% !important; /* Asegura que se adapte al contenedor y con prioridad */
        height: auto;
        margin-bottom: 10px;
    }

    .sidebar-header .app-icon {
        font-size: 3rem;
        color: #fff;
        margin-bottom: 10px;
    }
</style>
