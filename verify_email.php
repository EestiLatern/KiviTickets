<?php
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/mailer.php';

require_login();

$user_id = $_SESSION['user_id'];
$error   = '';
$success = '';

$mode = $_SESSION['verify_mode'] ?? 'new_email';

$stmt = mysqli_prepare($connection,
    "SELECT email, email_verified, email_pending, email_verify_code, email_verify_expires FROM users WHERE id = ?"
);
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

$target_email = ($mode === 'old_email') ? $user['email'] : ($user['email_pending'] ?? $user['email']);

if ($mode === 'new_email' && $user['email_verified'] && !$user['email_pending']) {
    header('Location: profile.php');
    exit;
}

if (isset($_POST['send_code'])) {
    $code = generate_and_save_code($connection, $user_id);
    if (send_verification_email($target_email, $code)) {
        $success = 'Kinnituskood saadeti aadressile ' . htmlspecialchars($target_email);
    } else {
        $error = 'Meili saatmine ebaõnnestus. Proovi uuesti.';
    }
}

if (isset($_POST['verify'])) {
    $entered = trim(($_POST['d1'] ?? '') . ($_POST['d2'] ?? '') . ($_POST['d3'] ?? '') . ($_POST['d4'] ?? ''));

    if (strlen($entered) < 4) {
        $error = 'Sisesta kõik 4 numbrit.';
    } elseif ($user['email_verify_expires'] && strtotime($user['email_verify_expires']) < time()) {
        $error = 'Kood on aegunud. Saada uus kood.';
    } elseif ($entered !== $user['email_verify_code']) {
        $error = 'Vale kood.';
    } else {
        if ($mode === 'old_email') {
            
            $new_email = $user['email_pending'];
            $code      = generate_and_save_code($connection, $user_id);
            send_verification_email($new_email, $code);

            
            $stmt = mysqli_prepare($connection,
                "UPDATE users SET email = ?, email_verified = 0, email_pending = NULL WHERE id = ?"
            );
            mysqli_stmt_bind_param($stmt, 'si', $new_email, $user_id);
            mysqli_stmt_execute($stmt);

            $_SESSION['verify_mode'] = 'new_email';
            $success = 'Vana meil kinnitatud! Saatsime koodi uuele aadressile ' . htmlspecialchars($new_email);

            
            $stmt = mysqli_prepare($connection,
                "SELECT email, email_verified, email_pending, email_verify_code, email_verify_expires FROM users WHERE id = ?"
            );
            mysqli_stmt_bind_param($stmt, 'i', $user_id);
            mysqli_stmt_execute($stmt);
            $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            $mode = 'new_email';
            $target_email = $user['email'];

        } else {
            
            $stmt = mysqli_prepare($connection,
                "UPDATE users SET email_verified = 1, email_verify_code = NULL,
                 email_verify_expires = NULL WHERE id = ?"
            );
            mysqli_stmt_bind_param($stmt, 'i', $user_id);
            mysqli_stmt_execute($stmt);

            unset($_SESSION['verify_mode']);
            $user['email_verified'] = 1;
            $success = 'E-post on kinnitatud!';

            if (isset($_SESSION['redirect_after_verify'])) {
                $redirect = $_SESSION['redirect_after_verify'];
                unset($_SESSION['redirect_after_verify']);
                header('Location: ' . $redirect);
                exit;
            } else {
                $redirect = in_array($_SESSION['role'] ?? '', ['conductor', 'admin']) ? 'staff_profile.php' : 'profile.php';
                header('Location: ' . $redirect);
                exit;
            }
        }
    }
}

require __DIR__ . '/includes/header.php';
?>

<h2>E-posti kinnitamine</h2>

<?php if ($error): ?>
  <div class="message error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($success && ($user['email_verified'] ?? false) && $mode === 'new_email'): ?>
  <div class="message success"><?= htmlspecialchars($success) ?></div>
  <a class="btn" href="dashboard.php">Edasi</a>
<?php else: ?>

  <?php if ($success): ?>
    <div class="message success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>

  <?php if ($mode === 'old_email'): ?>
    <p>Meili vahetamiseks kinnita esmalt oma <strong>vana meil</strong>:</p>
    <p><strong><?= htmlspecialchars($target_email) ?></strong></p>
    <p style="font-size:13px;color:#888;">Pärast seda saadame koodi uuele aadressile.</p>
  <?php else: ?>
    <p>Kinnitame e-posti aadressi: <strong><?= htmlspecialchars($target_email) ?></strong></p>
  <?php endif; ?>

  <form method="POST" style="margin-bottom: 1rem;">
    <button class="btn secondary" name="send_code" type="submit">
      <?= $user['email_verify_code'] ? 'Saada uus kood' : 'Saada kinnituskood' ?>
    </button>
  </form>

  <form method="POST" id="verify-form">
    <input type="hidden" name="verify" value="1">
    <label>Sisesta 4-kohaline kood</label>
    <div style="display:flex; gap:1rem; margin:1.5rem auto; justify-content:center;">
      <input type="text" name="d1" id="d1" maxlength="1" inputmode="numeric"
             style="width:4.5rem; height:5rem; font-size:2.5rem; text-align:center; border:2px solid #ccc; border-radius:12px;">
      <input type="text" name="d2" id="d2" maxlength="1" inputmode="numeric"
             style="width:4.5rem; height:5rem; font-size:2.5rem; text-align:center; border:2px solid #ccc; border-radius:12px;">
      <input type="text" name="d3" id="d3" maxlength="1" inputmode="numeric"
             style="width:4.5rem; height:5rem; font-size:2.5rem; text-align:center; border:2px solid #ccc; border-radius:12px;">
      <input type="text" name="d4" id="d4" maxlength="1" inputmode="numeric"
             style="width:4.5rem; height:5rem; font-size:2.5rem; text-align:center; border:2px solid #ccc; border-radius:12px;">
    </div>
    <button class="btn" type="submit">Kinnita</button>
  </form>

  <script>
    const inputs = ['d1','d2','d3','d4'].map(id => document.getElementById(id));

    inputs.forEach((input, i) => {
      input.addEventListener('input', () => {
        input.value = input.value.replace(/\D/g, '');
        if (input.value && i < inputs.length - 1) inputs[i + 1].focus();
        if (inputs.every(inp => inp.value)) document.getElementById('verify-form').submit();
      });

      input.addEventListener('keydown', e => {
        if (e.key === 'Backspace' && !input.value && i > 0) inputs[i - 1].focus();
      });

      input.addEventListener('paste', e => {
        e.preventDefault();
        const text = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
        text.split('').slice(0, 4).forEach((char, idx) => {
          if (inputs[idx]) inputs[idx].value = char;
        });
        const last = Math.min(text.length, 4) - 1;
        if (inputs[last]) inputs[last].focus();
        if (text.length >= 4) document.getElementById('verify-form').submit();
      });
    });

    inputs[0].focus();
  </script>

<?php endif; ?>

<a class="btn secondary" href="<?= in_array($_SESSION['role'] ?? '', ['conductor', 'admin']) ? 'staff_profile.php' : 'profile.php' ?>">← Tagasi profiilile</a>

<?php require __DIR__ . '/includes/footer.php'; ?>
