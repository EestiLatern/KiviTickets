<?php
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';

require_login();

$currentUserId = (int)$_SESSION['user_id'];
$currentRole   = $_SESSION['role'] ?? '';
$isAdmin       = ($currentRole === 'admin');

$message = '';
$messageType = 'success';

function selected_role($current, $value) {
    return $current === $value ? 'selected' : '';
}

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if($action === 'create') {
        if(!$isAdmin) {
            $message = 'Ainult administraator saab uusi kasutajaid lisada.';
            $messageType = 'error';
        } else {
            $username  = trim($_POST['username'] ?? '');
            $full_name = trim($_POST['full_name'] ?? '');
            $email     = trim($_POST['email'] ?? '');
            $phone     = trim($_POST['phone'] ?? '');
            $password  = $_POST['password'] ?? '';
            $role      = $_POST['role'] ?? 'user';

            if(!in_array($role, ['user', 'conductor', 'admin'], true)) {
                $role = 'user';
            }

            if($username === '' || $password === '') {
                $message = 'Kasutajanimi ja parool on kohustuslikud.';
                $messageType = 'error';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $public_code = 'KT' . time() . rand(100,999);

                $stmt = mysqli_prepare($connection, "
                    INSERT INTO users (username, full_name, email, phone, password, role, public_code)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                mysqli_stmt_bind_param($stmt, 'sssssss', $username, $full_name, $email, $phone, $hash, $role, $public_code);

                $message = mysqli_stmt_execute($stmt)
                    ? 'Kasutaja lisatud.'
                    : 'Kasutaja lisamine ebaõnnestus: ' . mysqli_error($connection);

                if(!mysqli_stmt_error($stmt) === '') {
                    $messageType = 'error';
                }
            }
        }
    }

    if($action === 'update_user') {
        $user_id = (int)($_POST['user_id'] ?? 0);

        if(!$isAdmin && $user_id !== $currentUserId) {
            $message = 'Saad muuta ainult enda andmeid.';
            $messageType = 'error';
        } else {
            $username  = trim($_POST['username'] ?? '');
            $full_name = trim($_POST['full_name'] ?? '');
            $email     = trim($_POST['email'] ?? '');
            $phone     = trim($_POST['phone'] ?? '');
            $password  = $_POST['password'] ?? '';

            $canEditRole = $isAdmin && $user_id !== $currentUserId;
            $role = $_POST['role'] ?? 'user';

            if(!in_array($role, ['user', 'conductor', 'admin'], true)) {
                $role = 'user';
            }

            if($username === '') {
                $message = 'Kasutajanimi on kohustuslik.';
                $messageType = 'error';
            } else {
                if($password !== '') {
                    $hash = password_hash($password, PASSWORD_DEFAULT);

                    if($canEditRole) {
                        $stmt = mysqli_prepare($connection, "
                            UPDATE users
                            SET username = ?, full_name = ?, email = ?, phone = ?, password = ?, role = ?
                            WHERE id = ?
                        ");
                        mysqli_stmt_bind_param($stmt, 'ssssssi', $username, $full_name, $email, $phone, $hash, $role, $user_id);
                    } else {
                        $stmt = mysqli_prepare($connection, "
                            UPDATE users
                            SET username = ?, full_name = ?, email = ?, phone = ?, password = ?
                            WHERE id = ?
                        ");
                        mysqli_stmt_bind_param($stmt, 'sssssi', $username, $full_name, $email, $phone, $hash, $user_id);
                    }
                } else {
                    if($canEditRole) {
                        $stmt = mysqli_prepare($connection, "
                            UPDATE users
                            SET username = ?, full_name = ?, email = ?, phone = ?, role = ?
                            WHERE id = ?
                        ");
                        mysqli_stmt_bind_param($stmt, 'sssssi', $username, $full_name, $email, $phone, $role, $user_id);
                    } else {
                        $stmt = mysqli_prepare($connection, "
                            UPDATE users
                            SET username = ?, full_name = ?, email = ?, phone = ?
                            WHERE id = ?
                        ");
                        mysqli_stmt_bind_param($stmt, 'ssssi', $username, $full_name, $email, $phone, $user_id);
                    }
                }

                if(mysqli_stmt_execute($stmt)) {
                    if($user_id === $currentUserId) {
                        $_SESSION['username'] = $username;
                    }

                    $message = 'Kasutaja andmed salvestatud.';
                } else {
                    $message = 'Salvestamine ebaõnnestus: ' . mysqli_error($connection);
                    $messageType = 'error';
                }
            }
        }
    }

    if($action === 'delete') {
        $user_id = (int)($_POST['user_id'] ?? 0);

        if(!$isAdmin) {
            $message = 'Ainult administraator saab kasutajaid kustutada.';
            $messageType = 'error';
        } elseif($user_id === $currentUserId) {
            $message = 'Enda kasutajat ei saa kustutada.';
            $messageType = 'error';
        } else {
            $stmt = mysqli_prepare($connection, "DELETE FROM users WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $user_id);

            if(mysqli_stmt_execute($stmt)) {
                $message = 'Kasutaja kustutatud.';
            } else {
                $message = 'Kustutamine ebaõnnestus: ' . mysqli_error($connection);
                $messageType = 'error';
            }
        }
    }
}

$search = trim($_GET['q'] ?? '');
$users = null;

if($isAdmin && $search !== '') {
    $like = '%' . $search . '%';

    $stmt = mysqli_prepare($connection, "
        SELECT id, username, full_name, role, public_code, email, phone
        FROM users
        WHERE username LIKE ?
           OR full_name LIKE ?
           OR email LIKE ?
           OR phone LIKE ?
           OR public_code LIKE ?
        ORDER BY full_name ASC, username ASC
    ");

    mysqli_stmt_bind_param($stmt, 'sssss', $like, $like, $like, $like, $like);
    mysqli_stmt_execute($stmt);
    $users = mysqli_stmt_get_result($stmt);
} else {
    $stmt = mysqli_prepare($connection, "
        SELECT id, username, full_name, role, public_code, email, phone
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    mysqli_stmt_bind_param($stmt, 'i', $currentUserId);
    mysqli_stmt_execute($stmt);
    $users = mysqli_stmt_get_result($stmt);
}

require __DIR__ . '/includes/header.php';
?>

<a class="btn secondary" href="dashboard.php" style="margin-bottom:1.5rem;display:inline-block;">← Tagasi</a>

<h2><?= $isAdmin ? 'Kasutajad' : 'Minu andmed' ?></h2>

<?php if($message): ?>
  <div class="message <?= htmlspecialchars($messageType) ?>">
    <?= htmlspecialchars($message) ?>
  </div>
<?php endif; ?>

<?php if($isAdmin): ?>
  <form method="GET" style="display:flex;gap:8px;margin-bottom:1.5rem;">
    <input 
      type="text" 
      name="q" 
      value="<?= htmlspecialchars($search) ?>"
      placeholder="Otsi nime, kasutajanime, e-posti, telefoni või ID-koodi järgi..."
      style="margin-bottom:0;flex:1;"
    >

    <button type="submit" class="btn" style="width:auto;padding:0 20px;margin-top:0;">
      Otsi
    </button>

    <?php if($search !== ''): ?>
      <a href="admin_users.php" class="btn secondary" style="width:auto;padding:0 16px;margin-top:0;">
        ✕
      </a>
    <?php endif; ?>
  </form>
<?php endif; ?>

<?php if($users !== null): ?>
  <?php $count = 0; while($user = mysqli_fetch_assoc($users)): $count++; ?>
    <?php
      $editedUserId = (int)$user['id'];
      $isSelf = $editedUserId === $currentUserId;
      $canEditThisUser = $isAdmin || $isSelf;
      $canEditRole = $isAdmin && !$isSelf;
      $canDelete = $isAdmin && !$isSelf;
    ?>

    <div class="card">
      <p>
        <strong><?= htmlspecialchars($user['username']) ?></strong>
        <span style="font-size:12px;color:#888;margin-left:8px;">
          <?= htmlspecialchars($user['role']) ?><?= $isSelf ? ' · sina' : '' ?>
        </span>
      </p>

      <?php if($user['full_name']): ?>
        <p><?= htmlspecialchars($user['full_name']) ?></p>
      <?php endif; ?>

      <?php if($user['email']): ?>
        <p style="font-size:13px;color:#666;">✉ <?= htmlspecialchars($user['email']) ?></p>
      <?php endif; ?>

      <?php if($user['phone']): ?>
        <p style="font-size:13px;color:#666;">📞 <?= htmlspecialchars($user['phone']) ?></p>
      <?php endif; ?>

      <p style="font-size:12px;color:#aaa;">
        ID-kood: <?= htmlspecialchars($user['public_code']) ?>
      </p>

      <?php if($canEditThisUser): ?>
        <details style="margin-top:10px;" open>
          <summary style="cursor:pointer;color:var(--accent);font-size:13px;">
            Muuda andmeid
          </summary>

          <form method="POST" style="margin-top:10px;">
            <input type="hidden" name="action" value="update_user">
            <input type="hidden" name="user_id" value="<?= $editedUserId ?>">

            <label>Täisnimi</label>
            <input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>">

            <label>Kasutajanimi</label>
            <input type="text" name="username" value="<?= htmlspecialchars($user['username'] ?? '') ?>" required>

            <label>E-post</label>
            <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" placeholder="nimi@domeen.ee">

            <label>Telefon</label>
            <input type="text" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="+372 5000 0000">

            <label>Uus parool</label>
            <input type="password" name="password" placeholder="Jäta tühjaks, kui ei muuda">

            <?php if($canEditRole): ?>
              <label>Õigused</label>
              <select name="role">
                <option value="user" <?= selected_role($user['role'], 'user') ?>>Kasutaja</option>
                <option value="conductor" <?= selected_role($user['role'], 'conductor') ?>>Kontrolör</option>
                <option value="admin" <?= selected_role($user['role'], 'admin') ?>>Admin</option>
              </select>
            <?php else: ?>
              <p style="font-size:13px;color:#666;margin-top:8px;">
                <strong>Õigused:</strong> <?= htmlspecialchars($user['role']) ?>
              </p>

              <?php if($isSelf): ?>
                <p style="font-size:12px;color:#999;">
                  Enda õigusi ei saa muuta.
                </p>
              <?php endif; ?>
            <?php endif; ?>

            <button class="btn" type="submit">Salvesta andmed</button>
          </form>
        </details>
      <?php endif; ?>

      <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap;">
        <a 
          href="lookup_passenger.php?code=<?= urlencode($user['public_code']) ?>"
          class="btn secondary" 
          style="width:auto;padding:6px 12px;font-size:13px;"
        >
          👤 Vaata andmeid
        </a>

        <?php if($canDelete): ?>
          <form 
            method="POST" 
            onsubmit="return confirm('Kas kustutan kasutaja <?= htmlspecialchars($user['username'], ENT_QUOTES) ?>?');" 
            style="margin:0;"
          >
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="user_id" value="<?= $editedUserId ?>">

            <button class="btn danger" type="submit" style="width:auto;padding:6px 12px;font-size:13px;">
              Kustuta
            </button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endwhile; ?>

  <?php if($count === 0): ?>
    <p style="color:#888;">
      Otsingule „<?= htmlspecialchars($search) ?>” ei leitud kasutajaid.
    </p>
  <?php endif; ?>
<?php endif; ?>

<?php if($isAdmin): ?>
  <hr style="margin:2rem 0;">

  <h3>Lisa kasutaja</h3>

  <form method="POST">
    <input type="hidden" name="action" value="create">

    <label>Täisnimi</label>
    <input type="text" name="full_name">

    <label>Kasutajanimi</label>
    <input type="text" name="username" required>

    <label>E-post</label>
    <input type="email" name="email">

    <label>Telefon</label>
    <input type="text" name="phone" placeholder="+372 5000 0000">

    <label>Parool</label>
    <input type="password" name="password" required>

    <label>Roll</label>
    <select name="role">
      <option value="user">Kasutaja</option>
      <option value="conductor">Kontrolör</option>
      <option value="admin">Admin</option>
    </select>

    <button class="btn" type="submit">Lisa kasutaja</button>
  </form>
<?php endif; ?>

<a class="btn secondary" href="dashboard.php" style="margin-top:1.5rem;display:inline-block;">
  ← Tagasi
</a>

<?php require __DIR__ . '/includes/footer.php'; ?>
