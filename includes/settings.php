<?php

function get_setting($connection, $key) {
    $stmt = mysqli_prepare($connection, "
        SELECT setting_value
        FROM settings
        WHERE setting_key = ?
        LIMIT 1
    ");

    mysqli_stmt_bind_param($stmt, 's', $key);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);

    return $row ? $row['setting_value'] : null;
}
