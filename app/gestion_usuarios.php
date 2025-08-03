<?php
// Incluir el archivo de conexión a la base de datos
include 'conexion.php';

// Incluir el archivo de verificación de autenticación y roles
// Asegura que solo los administradores (id_role = 1) puedan acceder a esta página.
// Si el usuario no es administrador, será redirigido a unauthorized.php.
include 'auth_check.php';

// Iniciar sesión para gestionar mensajes
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Inicializar variables para mensajes
$mensaje = '';
$tipo_mensaje = '';

// --- Lógica para añadir, editar y eliminar usuarios ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Lógica para añadir o editar usuario
    if (isset($_POST['action']) && ($_POST['action'] === 'add_user' || $_POST['action'] === 'edit_user')) {
        $id_user = $_POST['id_user'] ?? null;
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $nombre_completo = trim($_POST['nombre_completo'] ?? '');
        $id_role = $_POST['id_role'] ?? null;
        $password = $_POST['password'] ?? ''; // Solo se usará para añadir o si se cambia en editar

        // Validaciones básicas
        if (empty($username) || empty($email) || empty($id_role)) {
            $mensaje = "Todos los campos obligatorios (Usuario, Email, Rol) deben ser rellenados.";
            $tipo_mensaje = 'danger';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $mensaje = "El formato del email no es válido.";
            $tipo_mensaje = 'danger';
        } else {
            try {
                if ($_POST['action'] === 'add_user') {
                    // Acción de añadir usuario
                    if (empty($password)) {
                        $mensaje = "La contraseña es obligatoria para nuevos usuarios.";
                        $tipo_mensaje = 'danger';
                    } else {
                        $password_hash = password_hash($password, PASSWORD_DEFAULT);

                        // Verificar si el username o email ya existen
                        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE username = ? OR email = ?");
                        $stmt_check->execute([$username, $email]);
                        if ($stmt_check->fetchColumn() > 0) {
                            $mensaje = "El nombre de usuario o el email ya están en uso.";
                            $tipo_mensaje = 'danger';
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO usuarios (username, password_hash, id_role, email, nombre_completo) VALUES (?, ?, ?, ?, ?)");
                            $stmt->execute([$username, $password_hash, $id_role, $email, $nombre_completo]);
                            $mensaje = "Usuario añadido correctamente.";
                            $tipo_mensaje = 'success';
                        }
                    }
                } elseif ($_POST['action'] === 'edit_user') {
                    // Acción de editar usuario
                    $sql = "UPDATE usuarios SET username = ?, email = ?, nombre_completo = ?, id_role = ?";
                    $params = [$username, $email, $nombre_completo, $id_role];

                    if (!empty($password)) {
                        $password_hash = password_hash($password, PASSWORD_DEFAULT);
                        $sql .= ", password_hash = ?";
                        $params[] = $password_hash;
                    }
                    $sql .= " WHERE id_user = ?";
                    $params[] = $id_user;

                    // Verificar si el username o email ya existen para otro usuario (excluyendo el actual)
                    $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE (username = ? OR email = ?) AND id_user != ?");
                    $stmt_check->execute([$username, $email, $id_user]);
                    if ($stmt_check->fetchColumn() > 0) {
                        $mensaje = "El nombre de usuario o el email ya están en uso por otro usuario.";
                        $tipo_mensaje = 'danger';
                    } else {
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($params);
                        $mensaje = "Usuario actualizado correctamente.";
                        $tipo_mensaje = 'success';
                    }
                }
            } catch (PDOException $e) {
                $mensaje = "Error de base de datos: " . $e->getMessage();
                $tipo_mensaje = 'danger';
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'delete_user') {
        // Lógica para eliminar usuario
        $id_user_to_delete = $_POST['id_user_to_delete'] ?? null;

        if ($id_user_to_delete == $_SESSION['user_id']) {
            $mensaje = "No puedes eliminar tu propio usuario.";
            $tipo_mensaje = 'danger';
        } else {
            try {
                $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id_user = ?");
                $stmt->execute([$id_user_to_delete]);
                $mensaje = "Usuario eliminado correctamente.";
                $tipo_mensaje = 'success';
            } catch (PDOException $e) {
                $mensaje = "Error de base de datos al eliminar usuario: " . $e->getMessage();
                $tipo_mensaje = 'danger';
            }
        }
    }
}

// --- Obtener datos para la tabla y el formulario ---

// Obtener todos los usuarios con sus roles
try {
    $stmt_users = $pdo->query("SELECT u.id_user, u.username, u.email, u.nombre_completo, u.created_at, u.last_login, r.role_name, u.id_role
                               FROM usuarios u
                               JOIN roles r ON u.id_role = r.id_role
                               ORDER BY u.username ASC");
    $usuarios = $stmt_users->fetchAll(PDO::FETCH_ASSOC);

    // Obtener todos los roles para el dropdown del formulario
    $stmt_roles = $pdo->query("SELECT id_role, role_name FROM roles ORDER BY role_name ASC");
    $roles = $stmt_roles->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $mensaje = "Error al cargar datos: " . $e->getMessage();
    $tipo_mensaje = 'danger';
    $usuarios = [];
    $roles = [];
}

// Cierra la conexión PDO al final del script
$pdo = null;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios - Gestión de Aceite</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Estilos generales */
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f4f7f6;
        }
        .wrapper {
            display: flex;
            min-height: 100vh;
        }
        .content {
            flex-grow: 1;
            padding: 20px;
        }
        .card {
            border-radius: 15px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
            margin-bottom: 20px;
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

        /* Estilos del Sidebar (copia de sidebar.php) */
        :root {
            --sidebar-width: 250px;
            --primary-color: #007bff; /* Un azul más estándar de Bootstrap */
            --sidebar-bg: #343a40; /* Fondo oscuro */
            --sidebar-link: #adb5bd; /* Gris claro para enlaces */
            --sidebar-hover: #495057; /* Gris un poco más oscuro al pasar el ratón */
            --sidebar-active: #0056b3; /* Azul más oscuro para el elemento activo */
        }

        .sidebar {
            width: var(--sidebar-width);
            background-color: var(--sidebar-bg);
            color: white;
            padding: 20px;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            flex-shrink: 0; /* Evita que el sidebar se encoja */
            height: 100vh; /* Asegura que el sidebar ocupe toda la altura de la ventana */
            position: sticky; /* Hace que el sidebar se mantenga visible al hacer scroll */
            top: 0; /* Alinea el sidebar con la parte superior de la ventana */
            overflow-y: auto; /* Permite el scroll si el contenido del sidebar es demasiado largo */
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

        /* Styles for client search dropdown */
        .client-search-container {
            position: relative;
        }
        .client-search-results {
            position: absolute;
            background-color: #fff;
            border: 1px solid #ced4da;
            border-radius: 8px;
            max-height: 200px;
            overflow-y: auto;
            width: 100%;
            z-index: 1000;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .client-search-results-item {
            padding: 10px;
            cursor: pointer;
            border-bottom: 1px solid #eee;
        }
        .client-search-results-item:last-child {
            border-bottom: none;
        }
        .client-search-results-item:hover {
            background-color: #f8f9fa;
        }

        /* Custom style for scrollable table */
        .scrollable-table-container {
            max-height: 500px; /* Adjust as needed, e.g., 500px for about 15 rows */
            overflow-y: auto;
            border: 1px solid #e9ecef; /* Optional: add a border to the scrollable area */
            border-radius: 8px;
        }
        .scrollable-table-container table {
            margin-bottom: 0; /* Remove default table margin if inside a scrollable div */
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <?php include 'sidebar.php'; // Incluye el sidebar ?>

        <div class="content">
            <?php /* Eliminada la barra de navegación superior según la solicitud del usuario */ ?>
            <?php /*
            <div class="navbar">
                <span class="app-name">Gestión de Aceite</span>
                <div>
                    <span class="me-3">Bienvenido, <?php echo htmlspecialchars($_SESSION['nombre_completo'] ?? $_SESSION['username']); ?></span>
                    <a href="logout.php" class="btn btn-light btn-sm">Cerrar Sesión</a>
                </div>
            </div>
            */ ?>

            <div class="container-fluid">
                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                        <?php echo $mensaje; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        Gestión de Usuarios
                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#userModal" id="addUserBtn">
                            <i class="bi bi-plus-circle me-2"></i>Añadir Nuevo Usuario
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover table-striped">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Usuario</th>
                                        <th>Email</th>
                                        <th>Nombre Completo</th>
                                        <th>Rol</th>
                                        <th>Fecha Registro</th>
                                        <th>Último Login</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($usuarios)): ?>
                                        <?php foreach ($usuarios as $user): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($user['id_user']); ?></td>
                                                <td><?php echo htmlspecialchars($user['username']); ?></td>
                                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                                <td><?php echo htmlspecialchars($user['nombre_completo']); ?></td>
                                                <td><?php echo htmlspecialchars($user['role_name']); ?></td>
                                                <td><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($user['created_at']))); ?></td>
                                                <td><?php echo $user['last_login'] ? htmlspecialchars(date('d/m/Y H:i', strtotime($user['last_login']))) : 'N/A'; ?></td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-primary edit-user-btn"
                                                            data-bs-toggle="modal" data-bs-target="#userModal"
                                                            data-id_user="<?php echo htmlspecialchars($user['id_user']); ?>"
                                                            data-username="<?php echo htmlspecialchars($user['username']); ?>"
                                                            data-email="<?php echo htmlspecialchars($user['email']); ?>"
                                                            data-nombre_completo="<?php echo htmlspecialchars($user['nombre_completo']); ?>"
                                                            data-id_role="<?php echo htmlspecialchars($user['id_role']); ?>">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-danger delete-user-btn"
                                                            data-bs-toggle="modal" data-bs-target="#deleteConfirmModal"
                                                            data-id_user="<?php echo htmlspecialchars($user['id_user']); ?>">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center">No hay usuarios registrados.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para Añadir/Editar Usuario -->
    <div class="modal fade" id="userModal" tabindex="-1" aria-labelledby="userModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="userModalLabel">Añadir Nuevo Usuario</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="userForm" method="POST" action="gestion_usuarios.php">
                    <div class="modal-body">
                        <input type="hidden" name="id_user" id="userId">
                        <input type="hidden" name="action" id="actionType" value="add_user">

                        <div class="mb-3">
                            <label for="username" class="form-label">Usuario</label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" required>
                        </div>
                        <div class="mb-3">
                            <label for="nombre_completo" class="form-label">Nombre Completo</label>
                            <input type="text" class="form-control" id="nombre_completo" name="nombre_completo">
                        </div>
                        <div class="mb-3">
                            <label for="id_role" class="form-label">Rol</label>
                            <select class="form-select" id="id_role" name="id_role" required>
                                <option value="">Selecciona un rol</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo htmlspecialchars($role['id_role']); ?>">
                                        <?php echo htmlspecialchars($role['role_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Contraseña <small class="text-muted" id="passwordHelpText">(Dejar en blanco para no cambiar)</small></label>
                            <input type="password" class="form-control" id="password" name="password">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Guardar Usuario</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de Confirmación de Eliminación -->
    <div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteConfirmModalLabel">Confirmar Eliminación</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    ¿Estás seguro de que deseas eliminar a <strong id="deleteUsername"></strong>? Esta acción no se puede deshacer.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <form id="deleteUserForm" method="POST" action="gestion_usuarios.php">
                        <input type="hidden" name="action" value="delete_user">
                        <input type="hidden" name="id_user_to_delete" id="deleteUserId">
                        <button type="submit" class="btn btn-danger">Eliminar</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const userModal = document.getElementById('userModal');
            const addUserBtn = document.getElementById('addUserBtn');
            const userForm = document.getElementById('userForm');
            const modalTitle = document.getElementById('userModalLabel');
            const userIdInput = document.getElementById('userId');
            const usernameInput = document.getElementById('username');
            const emailInput = document.getElementById('email');
            const nombreCompletoInput = document.getElementById('nombre_completo');
            const idRoleSelect = document.getElementById('id_role');
            const passwordInput = document.getElementById('password');
            const passwordHelpText = document.getElementById('passwordHelpText');
            const actionTypeInput = document.getElementById('actionType');

            // Resetear el formulario al abrir el modal para añadir
            addUserBtn.addEventListener('click', function() {
                userForm.reset();
                userIdInput.value = '';
                modalTitle.textContent = 'Añadir Nuevo Usuario';
                passwordInput.setAttribute('required', 'required'); // Contraseña obligatoria para añadir
                passwordHelpText.style.display = 'none'; // Ocultar texto de ayuda
                actionTypeInput.value = 'add_user';
            });

            // Llenar el formulario al abrir el modal para editar
            document.querySelectorAll('.edit-user-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const id_user = this.getAttribute('data-id_user');
                    const username = this.getAttribute('data-username');
                    const email = this.getAttribute('data-email');
                    const nombre_completo = this.getAttribute('data-nombre_completo');
                    const id_role = this.getAttribute('data-id_role');

                    userIdInput.value = id_user;
                    usernameInput.value = username;
                    emailInput.value = email;
                    nombreCompletoInput.value = nombre_completo;
                    idRoleSelect.value = id_role;
                    passwordInput.value = ''; // Limpiar campo de contraseña al editar
                    passwordInput.removeAttribute('required'); // Contraseña no obligatoria para editar
                    passwordHelpText.style.display = 'inline'; // Mostrar texto de ayuda
                    modalTitle.textContent = 'Editar Usuario';
                    actionTypeInput.value = 'edit_user';
                });
            });

            // Llenar el modal de confirmación de eliminación
            const deleteConfirmModal = document.getElementById('deleteConfirmModal');
            deleteConfirmModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget; // Botón que activó el modal
                const id_user = button.getAttribute('data-id_user');
                const username = button.closest('tr').querySelector('td:nth-child(2)').textContent; // Obtener el nombre de usuario de la tabla

                const modalDeleteUsername = deleteConfirmModal.querySelector('#deleteUsername');
                const modalDeleteUserId = deleteConfirmModal.querySelector('#deleteUserId');

                modalDeleteUsername.textContent = username;
                modalDeleteUserId.value = id_user;
            });
        });
    </script>
</body>
</html>
