<?php
// Incluir el archivo de conexión a la base de datos
include 'conexion.php';

// Iniciar sesión
session_start();

// Si el usuario ya está logueado, redirigir a la página de inicio
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$mensaje = '';
$tipo_mensaje = '';

// Lógica para procesar el formulario de login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $mensaje = "Por favor, introduce tu nombre de usuario y contraseña.";
        $tipo_mensaje = 'danger';
    } else {
        try {
            // Preparar la consulta para buscar el usuario por nombre de usuario
            // Ahora seleccionamos id_role y last_login, y usamos password_hash
            $stmt = $pdo->prepare("SELECT id_user, username, password_hash, id_role, email, nombre_completo, last_login FROM usuarios WHERE username = ?");
            $stmt->execute([$username]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

            // Verificar si se encontró el usuario y la contraseña es correcta
            if ($usuario && password_verify($password, $usuario['password_hash'])) {
                // Contraseña correcta, iniciar sesión
                $_SESSION['user_id'] = $usuario['id_user'];
                $_SESSION['username'] = $usuario['username'];
                $_SESSION['id_role'] = $usuario['id_role']; // Almacenar el ID del rol
                $_SESSION['nombre_completo'] = $usuario['nombre_completo'];

                // Actualizar la columna last_login
                $update_stmt = $pdo->prepare("UPDATE usuarios SET last_login = NOW() WHERE id_user = ?");
                $update_stmt->execute([$usuario['id_user']]);

                // Redirigir a la página de inicio
                header("Location: index.php");
                exit();
            } else {
                $mensaje = "Nombre de usuario o contraseña incorrectos.";
                $tipo_mensaje = 'danger';
            }
        } catch (PDOException $e) {
            $mensaje = "Error de base de datos al intentar iniciar sesión: " . $e->getMessage();
            $tipo_mensaje = 'danger';
        }
    }
}

// Cierra la conexión PDO al final del script (si es necesario, aunque en este caso se cierra automáticamente al finalizar el script)
$pdo = null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Gestión de Aceite</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(to right, #6dd5ed, #2193b0); /* Degradado azul suave */
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";
        }
        .login-container {
            background-color: #ffffff;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 400px;
            text-align: center;
        }
        .login-logo {
            max-width: 120px;
            margin-bottom: 25px;
            border-radius: 50%;
            border: 3px solid #4CAF50; /* Verde oliva/aceite */
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        .login-container h2 {
            margin-bottom: 30px;
            color: #388E3C; /* Verde oscuro */
            font-weight: 700;
        }
        .form-label {
            font-weight: 600;
            color: #333;
            text-align: left;
            width: 100%;
            margin-bottom: 8px;
        }
        .form-control {
            border-radius: 8px;
            padding: 12px;
            border: 1px solid #ced4da;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .form-control:focus {
            border-color: #4CAF50;
            box-shadow: 0 0 0 0.25rem rgba(76, 175, 80, 0.25);
        }
        .btn-primary {
            background-color: #4CAF50;
            border-color: #4CAF50;
            font-weight: 600;
            padding: 12px 25px;
            border-radius: 8px;
            transition: background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
            width: 100%;
            margin-top: 20px;
        }
        .btn-primary:hover {
            background-color: #388E3C;
            border-color: #388E3C;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }
        .alert {
            margin-top: 20px;
            border-radius: 8px;
            font-size: 0.95rem;
        }
        .form-group {
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <?php
            // Ruta al logotipo
            $logo_path = 'assets/img/logo.png'; // Asegúrate de que esta ruta sea correcta
            if (file_exists($logo_path)) {
                echo '<img src="' . htmlspecialchars($logo_path) . '" alt="Logo de la aplicación" class="login-logo">';
            } else {
                echo '<i class="bi bi-droplet-fill login-logo" style="font-size: 5rem; color: #4CAF50;"></i>';
            }
        ?>
        <h2>Iniciar Sesión</h2>

        <?php if ($mensaje): ?>
            <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                <?php echo $mensaje; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <div class="mb-3">
                <label for="username" class="form-label">Usuario</label>
                <input type="text" class="form-control" id="username" name="username" required autocomplete="username">
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Contraseña</label>
                <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-primary">Entrar</button>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
