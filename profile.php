<?php
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/mailer.php';

require_login();

$user_id     = $_SESSION['user_id'];
$message     = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_email = trim($_POST['email'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');

    
    $stmt = mysqli_prepare($connection,
        "SELECT email, email_verified, email_pending FROM users WHERE id = ?"
    );
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $current = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    $email_changed = ($new_email !== '' && $new_email !== $current['email']);

    if ($email_changed) {
        if ($current['email'] && $current['email_verified']) {
            
            $stmt = mysqli_prepare($connection,
                "UPDATE users SET full_name = ?, phone = ?, email_pending = ? WHERE id = ?"
            );
            mysqli_stmt_bind_param($stmt, 'sssi', $full_name, $phone, $new_email, $user_id);
            mysqli_stmt_execute($stmt);

            
            $code = generate_and_save_code($connection, $user_id);
            send_verification_email($current['email'], $code);

            $_SESSION['verify_mode']          = 'old_email';
            $_SESSION['redirect_after_verify'] = 'profile.php';
            header('Location: verify_email.php');
            exit;
        } else {
            
            $stmt = mysqli_prepare($connection,
                "UPDATE users SET full_name = ?, email = ?, phone = ?,
                 email_verified = 0, email_verify_code = NULL,
                 email_verify_expires = NULL, email_pending = NULL WHERE id = ?"
            );
            mysqli_stmt_bind_param($stmt, 'sssi', $full_name, $new_email, $phone, $user_id);
            mysqli_stmt_execute($stmt);

            $code = generate_and_save_code($connection, $user_id);
            send_verification_email($new_email, $code);

            $_SESSION['verify_mode']          = 'new_email';
            $_SESSION['redirect_after_verify'] = 'profile.php';
            header('Location: verify_email.php');
            exit;
        }
    } else {
        
        $stmt = mysqli_prepare($connection,
            "UPDATE users SET full_name = ?, phone = ? WHERE id = ?"
        );
        mysqli_stmt_bind_param($stmt, 'ssi', $full_name, $phone, $user_id);
        if (mysqli_stmt_execute($stmt)) {
            $message     = 'Profiil salvestatud.';
            $messageType = 'success';
        } else {
            $message     = 'Salvestamine ebaõnnestus: ' . mysqli_error($connection);
            $messageType = 'error';
        }
    }
}

$stmt = mysqli_prepare($connection,
    "SELECT username, full_name, public_code, email, phone, email_verified, email_pending FROM users WHERE id = ?"
);
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

require __DIR__ . '/includes/header.php';
?>

<h2>Kasutaja profiil</h2>

<?php if ($message): ?>
  <div class="message <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="card">
  <p><strong>Kasutajanimi:</strong> <?= htmlspecialchars($user['username']) ?></p>
  <p><strong>Kasutaja ID-kood:</strong></p>
  <p class="big-code"><?= htmlspecialchars($user['public_code']) ?></p>
  <img
    src="https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=<?= urlencode($user['public_code']) ?>"
    style="width:200px;background:#fff;padding:1rem;margin-top:1rem;display:block;">
  <p style="font-size:13px;color:#888;margin-top:8px;">Näita seda klienditeenindajale kasutaja kinnitamiseks</p>
</div>

<div class="card">
  <h3>Muuda andmeid</h3>
  <form method="POST">
    <label>Nimi</label>
    <input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>">

    <label>E-post</label>
    <?php if ($user['email'] && $user['email_verified']): ?>
      <input type="email" name="email" value="<?= htmlspecialchars($user['email_pending'] ?? $user['email']) ?>" placeholder="nimi@domeen.ee">
      <?php if ($user['email_pending']): ?>
        <p style="margin:4px 0 0; color:#f97316; font-weight:600; font-size:14px;">⏳ Ootab kinnitust: <?= htmlspecialchars($user['email_pending']) ?></p>
      <?php else: ?>
        <p style="margin:4px 0 0; color:#22c55e; font-weight:600; font-size:14px;">✔ Valideeritud!</p>
      <?php endif; ?>
    <?php elseif ($user['email'] && !$user['email_verified']): ?>
      <input type="text" inputmode="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" placeholder="nimi@domeen.ee">
      <p style="margin:4px 0 4px; color:#ef4444; font-weight:600; font-size:14px;">✖ Meil on valideerimata!</p>
      <a href="verify_email.php" class="btn secondary" style="font-size:13px;">Valideeri e-post</a>
    <?php else: ?>
      <input type="text" inputmode="email" name="email" value="" placeholder="nimi@domeen.ee">
    <?php endif; ?>

    <label>Telefon</label>
    <input type="text" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="+372 5000 0000">

    <button class="btn" type="submit">Salvesta</button>
  </form>
</div>

<a class="btn secondary" href="dashboard.php">← Tagasi</a>

<?php require __DIR__ . '/includes/footer.php'; ?>
