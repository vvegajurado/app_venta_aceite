<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Denegado</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background-color: #f8d7da; /* Fondo rojo claro para error */
            color: #721c24; /* Texto rojo oscuro */
            font-family: 'Inter', sans-serif;
        }
        .container {
            text-align: center;
            background-color: #ffffff;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            max-width: 600px;
        }
        h1 {
            color: #dc3545; /* Rojo de Bootstrap */
            margin-bottom: 20px;
        }
        .bi-exclamation-triangle-fill {
            font-size: 4rem;
            color: #dc3545;
            margin-bottom: 20px;
        }
        .btn-primary {
            background-color: #0056b3;
            border-color: #0056b3;
            border-radius: 8px;
            margin-top: 20px;
        }
        .btn-primary:hover {
            background-color: #004494;
            border-color: #004494;
        }
    </style>
</head>
<body>
    <div class="container">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <h1>Acceso Denegado</h1>
        <p class="lead">No tienes los permisos necesarios para acceder a esta página.</p>
        <p>Por favor, contacta al administrador del sistema si crees que esto es un error.</p>
        <a href="index.php" class="btn btn-primary"><i class="bi bi-house-door-fill me-2"></i>Volver al Inicio</a>
    </div>
</body>
</html>