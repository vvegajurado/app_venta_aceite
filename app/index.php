<?php
// Incluir el archivo de verificación de autenticación al principio de cada página protegida
include 'auth_check.php';

// Incluir el sidebar para la navegación
include 'conexion.php'; // Asegúrate de que conexion.php esté disponible si lo necesitas aquí
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Aceite</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Variables globales para la consistencia del diseño */
        :root {
            --primary-color: #0056b3; /* Azul primario, consistente con otros archivos */
            --primary-dark: #004494;
            --secondary-color: #28a745; /* Verde para énfasis */
            --text-dark: #333;
            --text-light: #666;
            --bg-light: #f4f7f6; /* Fondo claro */
            --sidebar-bg: #343a40; /* Gris oscuro para el sidebar */
            --sidebar-link: #adb5bd; /* Gris claro para enlaces del sidebar */
            --sidebar-hover: #495057; /* Color de fondo hover para sidebar */
            --sidebar-active: #004494; /* Azul más oscuro para el elemento activo */
            --header-bg: #ffffff; /* Fondo blanco para el header */
            --shadow-light: rgba(0, 0, 0, 0.05);
            --shadow-medium: rgba(0, 0, 0, 0.1);

            /* Ajuste de variables para que el sidebar esté siempre expandido o ancho por defecto */
            --sidebar-width: 250px; /* Ancho deseado para el sidebar con texto */
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-light);
            color: var(--text-dark);
            line-height: 1.6;
            font-size: 1rem;
            display: flex; /* Para layout de sidebar y contenido */
            min-height: 100vh; /* Para que el cuerpo ocupe toda la altura de la ventana */
            margin: 0;
            padding: 0;
            overflow-x: hidden; /* Evitar scroll horizontal */
        }

        /* Estilos del Sidebar (adaptados de sidebar.php) */
        .sidebar {
            width: var(--sidebar-width);
            background-color: var(--sidebar-bg);
            color: white;
            padding: 20px;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            flex-shrink: 0; /* Evita que el sidebar se encoja */
            height: 100vh; /* Make sidebar take full viewport height */
            position: sticky; /* Keep sidebar fixed when scrolling */
            top: 0; /* Align to the top */
            overflow-y: auto; /* Enable scrolling if content overflows */
        }

        .sidebar-header {
            text-align: center;
            padding-bottom: 20px;
            margin-bottom: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-header .app-logo {
            max-width: 100px; /* Adjust as needed */
            height: auto;
            margin-bottom: 10px;
        }

        .sidebar-header .app-icon {
            font-size: 3rem;
            color: #fff;
            margin-bottom: 10px;
        }

        .sidebar-header .app-name {
            color: #fff;
            font-size: 1.5rem;
            font-weight: bold;
        }

        .sidebar-menu {
            list-style: none;
            padding: 0;
            display: flex;
            flex-direction: column;
            height: 100%; /* Asegura que la lista ocupe toda la altura disponible */
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
            transition: background-color 0.2s ease, color 0.2s ease;
        }

        .sidebar-menu-link i {
            margin-right: 10px;
            font-size: 1.2rem;
        }

        .sidebar-menu-link:hover {
            background-color: var(--sidebar-hover);
            color: white;
        }

        .sidebar-menu-link.active {
            background-color: var(--sidebar-active);
            color: white;
            font-weight: bold;
        }

        /* Estilos adicionales para los menús desplegables */
        .sidebar-submenu-list {
            padding-left: 0;
            margin-top: 5px;
            margin-bottom: 0;
            list-style: none;
        }

        .sidebar-submenu-item {
            margin-bottom: 2px;
        }

        .sidebar-submenu-link {
            display: block;
            padding: 8px 15px 8px 55px; /* Ajuste para la indentación */
            color: var(--sidebar-link);
            text-decoration: none;
            transition: background-color 0.2s ease, color 0.2s ease;
            font-size: 0.95rem;
            border-left: 5px solid transparent; /* Para la línea activa del submenú */
        }

        .sidebar-submenu-link:hover, .sidebar-submenu-link.active {
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

        /* Contenido Principal */
        .main-content {
            margin-left: var(--sidebar-width); /* Espacio para el sidebar fijo y ancho */
            flex-grow: 1; /* Ocupa el espacio restante */
            padding: 30px;
            transition: margin-left 0.3s ease;
            background-color: var(--bg-light);
            width: calc(100% - var(--sidebar-width)); /* Ancho restante del contenido */
            min-height: 100vh; /* Asegura que el contenido principal también ocupe toda la altura */
        }

        /* Estilos del contenedor principal dentro del main-content */
        .container {
            background-color: var(--header-bg); /* Fondo blanco para el contenedor */
            padding: 30px; /* Reducir padding para dar más espacio al contenido */
            border-radius: 15px; /* Bordes más redondeados */
            box-shadow: 0 4px 8px var(--shadow-light); /* Sombra suave */
            margin-bottom: 30px; /* Reducir margin-bottom */
            max-width: 1500px; /* Aumentar el ancho máximo para el contenedor */
            margin-left: auto; /* Centrar el contenedor */
            margin-right: auto; /* Centrar el contenedor */
        }

        h1, h2, h3, h4, h5, h6 {
            color: var(--primary-dark);
            font-weight: 600;
        }
        h2 {
            font-size: 1.8rem;
            margin-bottom: 25px;
            text-align: center;
        }

        /* Estilos para el nuevo encabezado del index.php */
        .company-header {
            text-align: center;
            margin-bottom: 40px; /* Espacio debajo del encabezado */
        }

        .company-logo {
            max-width: 150px; /* Ajusta el tamaño del logo */
            height: auto;
            margin-bottom: 15px; /* Espacio debajo del logo */
            border-radius: 15%; /* Ligeramente redondeado */
            box-shadow: 0 4px 15px rgba(0,0,0,0.1); /* Sombra suave */
        }

        .company-name {
            font-size: 2.2rem; /* Tamaño de la fuente para el nombre de la empresa */
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 5px; /* Espacio debajo del nombre de la empresa */
        }

        /* Iconos de las secciones principales */
        .row.mt-5 .col-md-4 i {
            color: var(--primary-color); /* Usar el color primario para los iconos */
            font-size: 3.5rem; /* Aumentar el tamaño de los iconos */
            margin-bottom: 15px;
        }
        .row.mt-5 .col-md-4 h5 {
            color: var(--text-dark);
            font-weight: 600;
        }
        .row.mt-5 .col-md-4 p {
            color: var(--text-light);
        }

        /* Estilos específicos para los enlaces de las tarjetas */
        .card-link {
            text-decoration: none; /* Quitar subrayado */
            color: inherit; /* Heredar color del texto */
        }

        .card-link:hover .card {
            transform: translateY(-5px); /* Efecto de elevación al pasar el ratón */
            box-shadow: 0 8px 16px var(--shadow-medium); /* Sombra más pronunciada */
        }

        .card-link .card {
            transition: transform 0.3s ease, box-shadow 0.3s ease; /* Transición suave */
        }

        /* Estilos de facturas_ventas.php aplicados para consistencia */
        .card {
            border-radius: 15px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
            margin-bottom: 20px; /* Ajustado para consistencia */
        }
        .card-header {
            background-color: #0056b3;
            color: white;
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
            font-weight: bold;
        }
        .btn-primary {
            background-color: #0056b3;
            border-color: #0056b3;
            border-radius: 8px;
        }
        .btn-primary:hover {
            background-color: #004494;
            border-color: #004494;
        }
        .btn-success {
            background-color: #28a745;
            border-color: #28a745;
            border-radius: 8px;
        }
        .btn-success:hover {
            background-color: #218838;
            border-color: #218838;
        }
        .btn-info {
            background-color: #17a2b8;
            border-color: #17a2b8;
            border-radius: 8px;
        }
        .btn-info:hover {
            background-color: #138496;
            border-color: #138496;
        }
        .btn-danger {
            background-color: #dc3545;
            border-color: #dc3545;
            border-radius: 8px;
        }
        .btn-danger:hover {
            background-color: #c82333;
            border-color: #c82333;
        }
        .table thead {
            background-color: #e9ecef;
        }
        .table th, .table td {
            vertical-align: middle;
            /* text-align: center; Removido para permitir alineación por defecto o específica */
        }
        .form-control, .form-select {
            border-radius: 8px;
        }
        .modal-content {
            border-radius: 15px;
        }
        .modal-header {
            background-color: #0056b3;
            color: white;
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
        }
        .alert {
            border-radius: 8px;
        }


        /* Media Queries para responsividad */
        @media (max-width: 768px) {
            .sidebar {
                width: 0; /* Ocultar completamente en móvil */
                left: -var(--sidebar-width); /* Asegurar que esté fuera de la pantalla */
            }
            .sidebar.expanded { /* Si tienes un botón para expandirlo en móvil (funcionalidad no implementada aquí) */
                width: var(--sidebar-width);
                left: 0;
            }
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 15px; /* Menos padding en pantallas pequeñas */
            }
            .sidebar.expanded + .main-content {
                margin-left: var(--sidebar-width); /* Para empujar el contenido si se expande */
            }
            .company-name {
                font-size: 1.8rem; /* Ajustar el tamaño de la fuente para pantallas más pequeñas */
            }
        }
    </style>
</head>
<body>
    <?php
    // Incluir el sidebar
    include 'sidebar.php';
    ?>

    <div class="main-content">
        <!-- Contenido principal de la página de inicio, ahora más consistente con otros formularios -->
        <div class="container">
            <!-- Nuevo encabezado con logo y nombre de la empresa -->
            <div class="company-header">
                <img src="assets/img/logo.png" alt="Logotipo Molino de Espera" class="company-logo">
                <h1 class="company-name">Molino de Espera</h1>
            </div>

            <h2 class="mb-4 text-center"><i class="bi bi-droplet-fill me-3"></i>Bienvenido al Sistema de Gestión de Aceite</h2>
            <p class="text-center text-muted">Optimiza el control de tus operaciones de aceite con nuestra intuitiva aplicación.</p>

            <!-- Sección de Ventas (Movida al principio) -->
            <h3 class="mt-5 mb-4 text-center text-primary">Gestión de Ventas</h3>
            <div class="row mt-3">
                <div class="col-md-4 text-center">
                    <a href="pedidos.php" class="card-link">
                        <div class="card p-4">
                            <i class="bi bi-receipt"></i>
                            <h5 class="mt-3">Pedidos</h5>
                            <p class="text-muted">Gestiona los pedidos de tus clientes de forma eficiente.</p>
                        </div>
                    </a>
                </div>
                <div class="col-md-4 text-center">
                    <a href="facturas_ventas.php" class="card-link">
                        <div class="card p-4">
                            <i class="bi bi-file-earmark-text"></i>
                            <h5 class="mt-3">Facturas de Ventas</h5>
                            <p class="text-muted">Crea y administra las facturas generadas por tus ventas.</p>
                        </div>
                    </a>
                </div>
                <div class="col-md-4 text-center">
                    <a href="partes_ruta.php" class="card-link">
                        <div class="card p-4">
                            <i class="bi bi-truck"></i>
                            <h5 class="mt-3">Partes de Ruta</h5>
                            <p class="text-muted">Organiza y sigue las rutas de entrega de tus productos.</p>
                        </div>
                    </a>
                </div>
            </div>

            <!-- Sección de Tesoreria (Movida al principio) -->
            <h3 class="mt-5 mb-4 text-center text-primary">Tesoreria</h3>
            <div class="row mt-3">
                <div class="col-md-6 text-center">
                    <a href="informe_cobros_diarios.php" class="card-link">
                        <div class="card p-4">
                            <i class="bi bi-cash-stack"></i>
                            <h5 class="mt-3">Cobros Diarios</h5>
                            <p class="text-muted">Consulta y cuadra los cobros de efectivo del día.</p>
                        </div>
                    </a>
                </div>
                <div class="col-md-6 text-center">
                    <a href="informe_movimientos_caja_varios.php" class="card-link">
                        <div class="card p-4">
                            <i class="bi bi-journal-text"></i>
                            <h5 class="mt-3">Movimientos Caja Varios</h5>
                            <p class="text-muted">Registra y revisa ingresos y pagos ajenos a las ventas.</p>
                        </div>
                    </a>
                </div>
            </div>

            <!-- Sección de Bodega (Originalmente en index.php, ahora después de Tesoreria) -->
            <h3 class="mt-5 mb-4 text-center text-primary">Gestión de Bodega</h3>
            <div class="row mt-3">
                <div class="col-md-4 text-center">
                    <a href="entradas_granel.php" class="card-link">
                        <div class="card p-4">
                            <i class="bi bi-box-arrow-in-down-right"></i>
                            <h5 class="mt-3">Entradas a Granel</h5>
                            <p class="text-muted">Registra y gestiona todas las entradas de aceite a tus instalaciones.</p>
                        </div>
                    </a>
                </div>
                <div class="col-md-4 text-center">
                    <a href="depositos.php" class="card-link">
                        <div class="card p-4">
                            <i class="bi bi-box-seam"></i>
                            <h5 class="mt-3">Gestión de Depósitos</h5>
                            <p class="text-muted">Controla el stock, capacidad y estado de cada uno de tus depósitos.</p>
                        </div>
                    </a>
                </div>
                <div class="col-md-4 text-center">
                    <a href="trasvases.php" class="card-link">
                        <div class="card p-4">
                            <i class="bi bi-arrow-left-right"></i>
                            <h5 class="mt-3">Trasvases</h5>
                            <p class="text-muted">Realiza traslados de aceite entre depósitos de forma sencilla y precisa.</p>
                        </div>
                    </a>
                </div>
            </div>

            <!-- Sección de Envasado (Originalmente en index.php) -->
            <h3 class="mt-5 mb-4 text-center text-primary">Operaciones de Envasado</h3>
            <div class="row mt-3">
                <div class="col-md-6 text-center">
                    <a href="actividad_envasado.php" class="card-link">
                        <div class="card p-4">
                            <i class="bi bi-boxes"></i>
                            <h5 class="mt-3">Actividad de Envasado</h5>
                            <p class="text-muted">Registra y supervisa el proceso de envasado de tus productos.</p>
                        </div>
                    </a>
                </div>
                <div class="col-md-6 text-center">
                    <a href="informe_movimientos_lote.php" class="card-link">
                        <div class="card p-4">
                            <i class="bi bi-file-earmark-bar-graph"></i>
                            <h5 class="mt-3">Existencias de Aceite Envasado</h5>
                            <p class="text-muted">Consulta el stock actual de tus lotes de productos envasados.</p>
                        </div>
                    </a>
                </div>
            </div>

            <!-- Sección de Informes (Originalmente en index.php) -->
            <h3 class="mt-5 mb-4 text-center text-primary">Informes y Trazabilidad</h3>
            <div class="row mt-3">
                <div class="col-md-6 text-center">
                    <a href="informes.php" class="card-link">
                        <div class="card p-4">
                            <i class="bi bi-graph-up"></i>
                            <h5 class="mt-3">Informes Generales</h5>
                            <p class="text-muted">Accede a diversos informes y análisis de tus operaciones.</p>
                        </div>
                    </a>
                </div>
                <div class="col-md-6 text-center">
                    <a href="trazabilidad.php" class="card-link">
                        <div class="card p-4">
                            <i class="bi bi-arrow-repeat"></i>
                            <h5 class="mt-3">Trazabilidad de Aceite</h5>
                            <p class="text-muted">Sigue el rastro de tus productos desde la materia prima hasta la venta.</p>
                        </div>
                    </a>
                </div>
            </div>

            <div class="text-center mt-5">
                <p class="lead">Explora las secciones y gestiona tu negocio de aceite de forma eficiente.</p>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
