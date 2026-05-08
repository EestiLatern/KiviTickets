<?php
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/mailer.php';

require_role(['conductor', 'admin']);

$user_id     = $_SESSION['user_id'];
$message     = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $new_email = trim($_POST['email'] ?? '');

    $stmt = mysqli_prepare($connection,
        "SELECT email, email_verified FROM users WHERE id = ?"
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

            $_SESSION['verify_mode']           = 'old_email';
            $_SESSION['redirect_after_verify']  = 'staff_profile.php';
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

            $_SESSION['verify_mode']           = 'new_email';
            $_SESSION['redirect_after_verify']  = 'staff_profile.php';
            header('Location: verify_email.php');
            exit;
        }
    } else {
        $stmt = mysqli_prepare($connection,
            "UPDATE users SET full_name = ?, phone = ? WHERE id = ?"
        );
        mysqli_stmt_bind_param($stmt, 'ssi', $full_name, $phone, $user_id);
        if (mysqli_stmt_execute($stmt)) {
            $message     = 'Andmed salvestatud.';
            $messageType = 'success';
        } else {
            $message     = 'Salvestamine ebaõnnestus.';
            $messageType = 'error';
        }
    }
}

$stmt = mysqli_prepare($connection,
    "SELECT username, full_name, public_code, email, phone, email_verified, email_pending, role FROM users WHERE id = ?"
);
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

$roleLabel = match($user['role']) {
    'admin'     => 'Administraator',
    'conductor' => 'Konduktor',
    default     => $user['role'],
};

$workMode = $_SESSION['work_mode'] ?? 'general';
$workModeLabel = match($workMode) {
    'personal' => '🚆 Isiklik sõit',
    'trip'     => '🎫 Reisil tööl',
    default    => '🛠 Üldine teenindus',
};

require __DIR__ . '/includes/header.php';
?>

<style>
.staff-profile-qr {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 1.5rem 1rem;
  background: 
  border: 1px solid var(--border);
  margin-bottom: 1rem;
  text-align: center;
}
.staff-profile-qr img {
  width: min(260px, 80vw);
  background: 
  padding: 0.75rem;
  border: 1px solid var(--border);
  margin-bottom: 1rem;
}
.staff-name {
  font-size: 1.3rem;
  font-weight: bold;
  margin-bottom: 0.25rem;
}
.staff-meta {
  font-size: 0.8rem;
  color: var(--muted);
  margin-bottom: 0.5rem;
}
.staff-badge {
  display: inline-block;
  padding: 0.3rem 0.8rem;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: bold;
  background: 
  color: 
  border: 1px solid 
}
.staff-code {
  font-size: 1.1rem;
  color: var(--accent);
  letter-spacing: 2px;
  margin-top: 0.75rem;
}
.big-input {
  font-size: 1.1rem !important;
  padding: 1rem !important;
}
</style>

<a class="btn secondary" href="dashboard.php" style="margin-bottom:1.5rem;display:block;">← Tagasi</a>

<h2>Minu profiil</h2>

<?php if ($message): ?>
  <div class="message <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<!-- QR ja info -->
<div class="staff-profile-qr">
  <img src="https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=<?= urlencode($user['public_code']) ?>"
       alt="QR kood">
  <div class="staff-name"><?= htmlspecialchars($user['full_name'] ?: $user['username']) ?></div>
  <div class="staff-meta">@<?= htmlspecialchars($user['username']) ?> &middot; <?= $roleLabel ?></div>
  <div class="staff-badge"><?= $workModeLabel ?></div>
  <div class="staff-code"><?= htmlspecialchars($user['public_code']) ?></div>
  <p style="font-size:12px;color:#aaa;margin-top:0.75rem;">Näita seda kolleegile või skänneeri puhkeruumi sisenemisel</p>
</div>

<!-- Andmete muutmine -->
<div class="card">
  <h3 style="margin-bottom:1rem;">Muuda andmeid</h3>
  <form method="POST">
    <label>Nimi</label>
    <input class="big-input" type="text" name="full_name"
           value="<?= htmlspecialchars($user['full_name'] ?? '') ?>">

    <label>E-post</label>
    <?php if ($user['email'] && $user['email_verified']): ?>
      <input class="big-input" type="text" inputmode="email" name="email"
             value="<?= htmlspecialchars($user['email_pending'] ?? $user['email']) ?>">
      <?php if ($user['email_pending']): ?>
        <p style="color:#f97316;font-size:13px;margin-top:-0.8rem;margin-bottom:1rem;">⏳ E-post on valideerimata: <?= htmlspecialchars($user['email_pending']) ?></p>
      <?php else: ?>
        <p style="color:#22c55e;font-size:13px;margin-top:-0.8rem;margin-bottom:1rem;">✔ Valideeritud</p>
      <?php endif; ?>
    <?php elseif ($user['email'] && !$user['email_verified']): ?>
      <input class="big-input" type="text" inputmode="email" name="email"
             value="<?= htmlspecialchars($user['email']) ?>">
      <p style="color:#ef4444;font-size:13px;margin-top:-0.8rem;margin-bottom:0.4rem;">✖ Valideerimata</p>
      <a href="verify_email.php" class="btn secondary" style="font-size:13px;margin-bottom:1rem;">Valideeri e-post</a>
    <?php else: ?>
      <input class="big-input" type="text" inputmode="email" name="email" value="" placeholder="nimi@domeen.ee">
    <?php endif; ?>

    <label>Telefon</label>
    <input class="big-input" type="tel" name="phone"
           value="<?= htmlspecialchars($user['phone'] ?? '') ?>"
           placeholder="+372 5000 0000">

    <button class="btn" type="submit" style="font-size:1rem;padding:1rem;">Salvesta</button>
  </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
