<?php

function idn_to_ascii_compat($email) {
    if (function_exists('idn_to_ascii')) {
        $parts  = explode('@', $email, 2);
        if (count($parts) === 2) {
            $domain = idn_to_ascii($parts[1], IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($domain !== false) {
                return $parts[0] . '@' . $domain;
            }
        }
    }

    $map = [
        'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'õ' => 'o',
        'Ä' => 'A', 'Ö' => 'O', 'Ü' => 'U', 'Õ' => 'O',
    ];

    $parts = explode('@', $email, 2);
    if (count($parts) === 2) {
        $domain = strtr($parts[1], $map);
        return $parts[0] . '@' . $domain;
    }

    return $email;
}

function send_verification_email($to, $code) {
    $subject = '=?UTF-8?B?' . base64_encode('KiviTickets kinnituskood') . '?=';
    $message = "Tere!\n\nSiin on sinu KiviTickets kinnituskood: $code\n\nKood kehtib 15 minutit.\n\nKui sa seda ei taotlenud, ignoreeri seda kirja.";

    $domain = $_SERVER['HTTP_HOST'];
    $from   = 'validate@' . $domain;

    $headers  = 'From: ' . $from . "\r\n";
    $headers .= 'Reply-To: ' . $from . "\r\n";
    $headers .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
    $headers .= 'X-Mailer: PHP/' . phpversion() . "\r\n";

    $to_ascii = idn_to_ascii_compat($to);

    return mail($to_ascii, $subject, $message, $headers);
}

function generate_and_save_code($connection, $user_id) {
    $code    = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
    $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));

    $stmt = mysqli_prepare($connection,
        "UPDATE users SET email_verify_code = ?, email_verify_expires = ? WHERE id = ?"
    );
    mysqli_stmt_bind_param($stmt, 'ssi', $code, $expires, $user_id);
    mysqli_stmt_execute($stmt);

    return $code;
}
