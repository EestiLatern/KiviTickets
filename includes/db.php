<?php

$connection = mysqli_connect(
    getenv('DB_HOST')     ?: 'localhost',
    getenv('DB_USER')     ?: '',
    getenv('DB_PASSWORD') ?: '',
    getenv('DB_NAME')     ?: ''
);

if(!$connection) {
    die("Viga ühendumisel: " . mysqli_connect_error());
}

mysqli_set_charset($connection, 'utf8mb4');
