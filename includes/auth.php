<?php
if(session_status() === PHP_SESSION_NONE) {
    session_start();
}

function require_login() {
    if(!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

function require_role($roles) {
    require_login();

    if(!is_array($roles)) {
        $roles = [$roles];
    }

    if(!in_array($_SESSION['role'], $roles)) {
        die('Ligipääs keelatud.');
    }
}

function is_admin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function is_conductor() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'conductor';
}

function is_user() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'user';
}
