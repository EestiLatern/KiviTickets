<?php
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/mailer.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $password  = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';

    if ($password !== $password2) {
        $error = 'Paroolid ei kattu.';
    } elseif ($email === '' && $phone === '') {
        $error = 'Sisesta vähemalt e-post või telefoninumber.';
    } else {
        $hash        = password_hash($password, PASSWORD_DEFAULT);
        $public_code = 'KT' . time() . rand(100, 999);

        $stmt = mysqli_prepare($connection, "
            INSERT INTO users (username, full_name, email, phone, password, role, public_code)
            VALUES (?, ?, ?, ?, ?, 'user', ?)
        ");
        mysqli_stmt_bind_param($stmt, 'ssssss', $username, $full_name, $email, $phone, $hash, $public_code);

        if (mysqli_stmt_execute($stmt)) {
            $new_user_id = mysqli_insert_id($connection);

            
            $_SESSION['user_id'] = $new_user_id;
            $_SESSION['role']    = 'user';
            $_SESSION['username'] = $username;

            
            if ($email !== '') {
                $code = generate_and_save_code($connection, $new_user_id);
                send_verification_email($email, $code);

                $_SESSION['redirect_after_verify'] = 'dashboard.php';
                header('Location: verify_email.php');
                exit;
            }

            
            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'Kasutajanimi või e-post on juba kasutusel.';
        }
    }
}

require __DIR__ . '/includes/header.php';
?>

<h2>Registreeri kasutaja</h2>

<?php if ($error): ?>
  <div class="message error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="POST">
  <label>Nimi</label>
  <input type="text" name="full_name" required>

  <label>Kasutajanimi</label>
  <input type="text" name="username" required>

  <label>E-post</label>
  <input type="text" inputmode="email" name="email">

  <label>Telefon</label>
  <input type="text" name="phone" placeholder="Valikuline, kui e-post on olemas">

  <label>Parool</label>
  <input type="password" name="password" required>

  <label>Korda parooli</label>
  <input type="password" name="password2" required>

  <button class="btn" type="submit">Registreeri</button>
</form>

<a class="btn secondary" href="login.php">Tagasi</a>

<?php require __DIR__ . '/includes/footer.php'; ?>
