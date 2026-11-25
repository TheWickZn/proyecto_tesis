<?php
session_start();

if (isset($_SESSION['id_usuario'])) {
    header('Location: empresa/dashboard.php');
    exit;
}

header('Location: auth/login.php');
exit;
