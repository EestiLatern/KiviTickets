<?php
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';

require_role(['conductor', 'admin']);

$isAdmin = $_SESSION['role'] === 'admin';

$message = '';
$messageType = '';
$passenger = null;
$tripCount = 0;

if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_username' && $isAdmin) {
    $uid     = (int)$_POST['user_id'];
    $newName = trim($_POST['new_username'] ?? '');
    if($newName === '') {
        $message = 'Kasutajanimi ei tohi olla tühi.';
        $messageType = 'error';
    } else {
        $chk = mysqli_prepare($connection, "SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
        mysqli_stmt_bind_param($chk, 'si', $newName, $uid);
        mysqli_stmt_execute($chk);
        $exists = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
        if($exists) {
            $message = 'See kasutajanimi on juba kasutusel.';
            $messageType = 'error';
        } else {
            $upd = mysqli_prepare($connection, "UPDATE users SET username = ? WHERE id = ?");
            mysqli_stmt_bind_param($upd, 'si', $newName, $uid);
            mysqli_stmt_execute($upd);
            $message = 'Kasutajanimi muudetud.';
            $messageType = 'success';
        }
    }
}

$searchCode = trim($_GET['code'] ?? $_POST['search_code'] ?? '');
if($searchCode !== '') {
    $pStmt = mysqli_prepare($connection, "
        SELECT id, username, full_name, email, phone, public_code, created_at
        FROM users WHERE public_code = ? LIMIT 1
    ");
    mysqli_stmt_bind_param($pStmt, 's', $searchCode);
    mysqli_stmt_execute($pStmt);
    $passenger = mysqli_fetch_assoc(mysqli_stmt_get_result($pStmt));

    if($passenger) {
        
        $cStmt = mysqli_prepare($connection, "
            SELECT COUNT(*) AS cnt
            FROM tickets
            WHERE user_id = ?
        ");
        mysqli_stmt_bind_param($cStmt, 'i', $passenger['id']);
        mysqli_stmt_execute($cStmt);
        $tripCount = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($cStmt))['cnt'] ?? 0);
    } else {
        $message = 'Kasutajat ei leitud.';
        $messageType = 'error';
    }
}

function registrationAge($dateStr) {
    $reg  = new DateTime($dateStr);
    $now  = new DateTime();
    $diff = $now->diff($reg);

    $years  = (int)$diff->y;
    $months = (int)$diff->m;
    $days   = (int)$diff->d;
    $weeks  = (int)floor($days / 7);

    $parts = [];
    if($years  > 0) $parts[] = $years  . ' ' . ($years  === 1 ? 'aasta'  : 'aastat');
    if($months > 0) $parts[] = $months . ' ' . ($months === 1 ? 'kuu'    : 'kuud');
    if($weeks  > 0) $parts[] = $weeks  . ' ' . ($weeks  === 1 ? 'nädal'  : 'nädalat');
    if(empty($parts)) return 'vähem kui nädal tagasi';

    return implode(', ', $parts) . ' tagasi';
}

require __DIR__ . '/includes/header.php';
?>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

<a class="btn secondary" href="dashboard.php" style="margin-bottom:1.5rem;display:block;">← Tagasi</a>
<h2>👤 Kasutaja andmed</h2>

<?php if($message): ?>
  <div class="message <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<form method="GET" id="search_form">
  <label>Kasutaja ID-kood</label>
  <input type="text" name="code" id="code_input" value="<?= htmlspecialchars($searchCode) ?>"
         placeholder="Sisesta või skänneeri kood"
         autocomplete="off" autocorrect="off" autofocus>
  <button class="btn" type="submit">Otsi</button>
</form>

<button type="button" class="btn secondary" id="btn_scan" style="margin-top:0.5rem;">
  📷 Skänni kaameraga
</button>

<div id="scan_container" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.92);z-index:9999;flex-direction:column;align-items:center;justify-content:center;">
  <p style="color:#fff;font-size:16px;margin-bottom:1rem;">Suuna kaamera kasutaja QR-koodile</p>
  <div id="scan_reader" style="width:min(90vw,400px);"></div>
  <button type="button" class="btn secondary" id="btn_scan_stop" style="margin-top:1.5rem;font-size:18px;padding:12px 32px;">✕ Sulge kaamera</button>
</div>

<?php if($passenger): ?>
  <div class="card" style="margin-top:1.5rem;">
    <h3 style="margin-bottom:1rem;">
      <?= htmlspecialchars($passenger['full_name'] ?: $passenger['username']) ?>
    </h3>

    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:0.8rem;">
      <div>
        <span style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:1px;">Kasutajanimi</span><br>
        <strong id="username_display"><?= htmlspecialchars($passenger['username']) ?></strong>
      </div>
      <?php if($isAdmin): ?>
        <button type="button" onclick="document.getElementById('username_edit').style.display='block';this.style.display='none';"
                class="btn secondary" style="width:auto;padding:5px 12px;font-size:12px;margin-top:0;">
          ✏️ Muuda
        </button>
      <?php endif; ?>
    </div>

    <?php if($isAdmin): ?>
    <div id="username_edit" style="display:none;margin-bottom:1rem;">
      <form method="POST">
        <input type="hidden" name="action" value="change_username">
        <input type="hidden" name="user_id" value="<?= (int)$passenger['id'] ?>">
        <input type="hidden" name="search_code" value="<?= htmlspecialchars($searchCode) ?>">
        <div style="display:flex;gap:8px;align-items:flex-start;">
          <input type="text" name="new_username"
                 value="<?= htmlspecialchars($passenger['username']) ?>"
                 style="margin-bottom:0;flex:1;">
          <button type="submit" class="btn" style="width:auto;padding:9px 16px;margin-top:0;">Salvesta</button>
          <button type="button" onclick="document.getElementById('username_edit').style.display='none';"
                  class="btn secondary" style="width:auto;padding:9px 16px;margin-top:0;">Tühista</button>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <table style="width:100%;border-collapse:collapse;font-size:14px;">
      <tr style="border-top:1px solid #eee;">
        <td style="padding:8px 4px;color:#888;width:40%;">Täisnimi</td>
        <td style="padding:8px 4px;"><?= htmlspecialchars($passenger['full_name'] ?: '—') ?></td>
      </tr>
      <?php if(!empty($passenger['phone'])): ?>
      <tr style="border-top:1px solid #eee;">
        <td style="padding:8px 4px;color:#888;">Telefon</td>
        <td style="padding:8px 4px;"><?= htmlspecialchars($passenger['phone']) ?></td>
      </tr>
      <?php endif; ?>
      <?php if(!empty($passenger['email'])): ?>
      <tr style="border-top:1px solid #eee;">
        <td style="padding:8px 4px;color:#888;">E-post</td>
        <td style="padding:8px 4px;"><?= htmlspecialchars($passenger['email']) ?></td>
      </tr>
      <?php endif; ?>
      <tr style="border-top:1px solid #eee;">
        <td style="padding:8px 4px;color:#888;">Registreeris</td>
        <td style="padding:8px 4px;"><?= registrationAge($passenger['created_at']) ?></td>
      </tr>
      <tr style="border-top:1px solid #eee;">
        <td style="padding:8px 4px;color:#888;">Pileteid kokku</td>
        <td style="padding:8px 4px;"><strong><?= $tripCount ?></strong> piletit</td>
      </tr>
      <tr style="border-top:1px solid #eee;">
        <td style="padding:8px 4px;color:#888;">ID-kood</td>
        <td style="padding:8px 4px;font-size:12px;color:#aaa;"><?= htmlspecialchars($passenger['public_code']) ?></td>
      </tr>
    </table>

    <div style="margin-top:1rem;display:flex;gap:8px;flex-wrap:wrap;">
      <a class="btn" href="sell_ticket.php?prefill_code=<?= urlencode($passenger['public_code']) ?>"
         style="width:auto;padding:8px 16px;font-size:13px;">
        + Müü pilet sellele kasutajale
      </a>
    </div>
  </div>
<?php endif; ?>

<script>
let html5Qr = null;

function stopScan() {
  if(html5Qr) { html5Qr.stop().catch(() => {}); html5Qr = null; }
  document.getElementById('scan_container').style.display = 'none';
  document.getElementById('btn_scan').style.display = 'block';
}

document.getElementById('btn_scan').addEventListener('click', function() {
  document.getElementById('scan_container').style.display = 'flex';
  this.style.display = 'none';
  html5Qr = new Html5Qrcode('scan_reader');
  html5Qr.start(
    { facingMode: 'environment' },
    { fps: 10, qrbox: { width: 280, height: 280 } },
    (decodedText) => {
      document.getElementById('code_input').value = decodedText;
      stopScan();
      document.getElementById('search_form').submit();
    },
    () => {}
  ).catch(() => { stopScan(); });
});

document.getElementById('btn_scan_stop').addEventListener('click', stopScan);
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
