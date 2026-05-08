<?php
session_start();
require __DIR__ . '/includes/db.php';

if(isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = mysqli_prepare($connection, "
        SELECT id, username, password, role
        FROM users
        WHERE username = ?
        LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt, 's', $username);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);

    if($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];

        
        setcookie('remember_user', $user['id'], time() + (30 * 24 * 60 * 60), '/', '', true, true);

        
        require __DIR__ . '/includes/mailer.php';
        $stmt2 = mysqli_prepare($connection,
            "SELECT email, email_verified FROM users WHERE id = ?"
        );
        mysqli_stmt_bind_param($stmt2, 'i', $user['id']);
        mysqli_stmt_execute($stmt2);
        $uinfo = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt2));

        if (!empty($uinfo['email']) && !$uinfo['email_verified']) {
            $code = generate_and_save_code($connection, $user['id']);
            send_verification_email($uinfo['email'], $code);
            $_SESSION['redirect_after_verify'] = 'dashboard.php';
            header('Location: verify_email.php');
            exit;
        }

        
        if (in_array($user['role'], ['conductor', 'admin'])) {
            header('Location: select_work_mode.php');
        } else {
            header('Location: index.php');
        }
        exit;
    } else {
        $error = 'Vale kasutajanimi või parool.';
    }
}

require __DIR__ . '/includes/header.php';
?>

<h2>Sisselogimine</h2>

<?php if($error): ?>
  <div class="message error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="POST">
  <label>Kasutajanimi</label>
  <input type="text" name="username" required autofocus>

  <label>Parool</label>
  <input type="password" name="password" required>

  <button class="btn" type="submit">Logi sisse →</button>
</form>

<a class="btn secondary" href="register.php">Registreeri</a>

<?php require __DIR__ . '/includes/footer.php'; ?>
