<?php
session_start();

require_once "../config/conexion.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: login.php");
    exit;
}

$usuario = trim($_POST["usuario"] ?? "");
$password = (string) ($_POST["password"] ?? "");

$loginValido = false;
$datosUsuario = null;

if ($conexion && $usuario !== "" && $password !== "") {
    try {
        if (tablaExiste($conexion, "usuarios")) {
            // Buscamos el usuario y resolvemos su rol de forma robusta
            $sql = "SELECT u.*, 
                           COALESCE(r.codigo, 'usuario') AS rol_codigo, 
                           COALESCE(r.nombre, 'Usuario') AS rol_nombre_oficial,
                           r.id_rol AS id_rol_detectado
                    FROM usuarios u
                    LEFT JOIN roles r ON r.id_rol = u.id_rol
                    WHERE u.usuario = :usuario AND u.estado = 1 
                    LIMIT 1";
            $stmt = $conexion->prepare($sql);
            $stmt->execute([":usuario" => $usuario]);
            $datosUsuario = $stmt->fetch();

            if ($datosUsuario && password_verify($password, $datosUsuario["password"])) {
                $loginValido = true;

            }
        }
    } catch (Throwable $e) {
        $loginValido = false;
    }
}

if ($loginValido && $datosUsuario) {
    session_regenerate_id(true);
    $_SESSION["id_usuario"] = (int) $datosUsuario["id_usuario"];
    $_SESSION["usuario"] = $datosUsuario["usuario"];
    $_SESSION["nombres"] = $datosUsuario["nombres"];
    $_SESSION["apellidos"] = $datosUsuario["apellidos"] ?? "";
    $_SESSION["rol"] = strtolower($datosUsuario["rol_codigo"] ?? "usuario");
    $_SESSION["rol_nombre"] = $datosUsuario["rol_nombre_oficial"] ?? "Usuario";

    header("Location: ../empresa/dashboard.php");
    exit;
}

header("Location: login.php?error=1");
exit;
