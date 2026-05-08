<?php
session_start();

if(!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if(
    ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'conductor')
    && !isset($_SESSION['work_mode'])
) {
    header('Location: select_work_mode.php');
    exit;
}

header('Location: dashboard.php');
exit;
