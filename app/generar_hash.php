<?php
$password = 'adminpassword123'; // ¡Usa una contraseña segura y cámbiala!
$hashed_password = password_hash($password, PASSWORD_DEFAULT);
echo "El hash para la contraseña '{$password}' es: " . $hashed_password;
?>
