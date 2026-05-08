<?php
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';

require_role(['conductor', 'admin']);

$message = '';
$messageType = '';
$ticket = null;
$groupTickets = [];
$passenger = null;
$passengerTickets = [];

if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refund') {
    $code = trim($_POST['ticket_code'] ?? '');

    $upd = mysqli_prepare($connection, "
        UPDATE tickets
        SET status='Tühistatud/Tagasi ostetud', cancelled_at=NOW()
        WHERE ticket_code=? AND status NOT IN ('Tühistatud/Tagasi ostetud')
    ");
    mysqli_stmt_bind_param($upd, 's', $code);
    mysqli_stmt_execute($upd);

    $affected = mysqli_affected_rows($connection);
    $message = $affected > 0
        ? '↩ Pilet tühistatud ja raha tagastatud.'
        : '✕ Tühistamine ebaõnnestus.';
    $messageType = $affected > 0 ? 'success' : 'error';
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'validate') {
    $scanned = trim($_POST['ticket_code'] ?? '');

    if($scanned === '') {
        $message = 'Sisesta või skänni kood.';
        $messageType = 'error';
    } else {
        $stmt = mysqli_prepare($connection, "
            SELECT t.*, tc.name AS category_name
            FROM tickets t
            LEFT JOIN ticket_categories tc ON tc.id = t.ticket_category_id
            WHERE t.ticket_code = ?
            LIMIT 1
        ");
        mysqli_stmt_bind_param($stmt, 's', $scanned);
        mysqli_stmt_execute($stmt);
        $ticket = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if($ticket) {
            if($ticket['status'] === 'Tühistatud/Tagasi ostetud') {
                $message = '✕ Pilet ei kehti.';
                $messageType = 'error';
                $ticket = null;
            } elseif($ticket['status'] === 'Kasutamata') {
                $message = '✕ Pilet on aegunud.';
                $messageType = 'error';
                $ticket = null;
            } elseif(!in_array($ticket['status'], ['Kehtib', 'Kehtib teisel reisil', 'Valideeritud'])) {
                $message = '✕ Pilet ei kehti.';
                $messageType = 'error';
                $ticket = null;
            } else {
                if(!empty($ticket['group_code'])) {
                    $gStmt = mysqli_prepare($connection, "
                        SELECT t.*, tc.name AS category_name
                        FROM tickets t
                        LEFT JOIN ticket_categories tc ON tc.id = t.ticket_category_id
                        WHERE t.group_code = ?
                        ORDER BY t.id ASC
                    ");
                    mysqli_stmt_bind_param($gStmt, 's', $ticket['group_code']);
                    mysqli_stmt_execute($gStmt);
                    $gRes = mysqli_stmt_get_result($gStmt);

                    while($row = mysqli_fetch_assoc($gRes)) {
                        $groupTickets[] = $row;
                    }
                } else {
                    $groupTickets[] = $ticket;
                }

                $message = count($groupTickets) > 1
                    ? '✓ Grupipilet kehtib. Pileteid kokku: ' . count($groupTickets)
                    : '✓ Pilet kehtib.';
                $messageType = 'success';
            }
        } else {
            $uStmt = mysqli_prepare($connection, "
                SELECT id, full_name, username, public_code
                FROM users
                WHERE public_code = ?
                LIMIT 1
            ");
            mysqli_stmt_bind_param($uStmt, 's', $scanned);
            mysqli_stmt_execute($uStmt);
            $passenger = mysqli_fetch_assoc(mysqli_stmt_get_result($uStmt));

            if($passenger) {
                $tStmt = mysqli_prepare($connection, "
                    SELECT t.*, tc.name AS category_name
                    FROM tickets t
                    LEFT JOIN ticket_categories tc ON tc.id = t.ticket_category_id
                    WHERE t.user_id = ?
                      AND t.status IN ('Kehtib', 'Kehtib teisel reisil', 'Valideeritud')
                    ORDER BY t.group_code ASC, t.valid_until ASC, t.id ASC
                ");
                mysqli_stmt_bind_param($tStmt, 'i', $passenger['id']);
                mysqli_stmt_execute($tStmt);
                $res = mysqli_stmt_get_result($tStmt);

                while($row = mysqli_fetch_assoc($res)) {
                    $passengerTickets[] = $row;
                }

                if(empty($passengerTickets)) {
                    header('Location: sell_ticket.php?prefill_code=' . urlencode($passenger['public_code']));
                    exit;
                }

                $message = 'Kasutaja: ' . htmlspecialchars($passenger['full_name'] ?: $passenger['username']);
                $messageType = 'info';
            } else {
                $message = '✕ Koodi ei leitud — ei pilet ega kasutaja.';
                $messageType = 'error';
            }
        }
    }
}

require __DIR__ . '/includes/header.php';
?>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

<a class="btn secondary" href="dashboard.php" style="margin-bottom:1.5rem;display:block;">← Tagasi</a>
<h2>Valideeri pilet</h2>

<?php if($message): ?>
  <div class="message <?= htmlspecialchars($messageType) ?>"><?= $message ?></div>
<?php endif; ?>

<?php if($ticket && $messageType === 'success'): ?>
  <?php
    $totalPrice = array_sum(array_map(fn($t) => (float)$t['price'], $groupTickets));
    $mainTicket = $groupTickets[0] ?? $ticket;
  ?>

  <div class="card">
    <h3><?= count($groupTickets) > 1 ? 'Grupipilet / ühine ost' : 'Pilet' ?></h3>

    <p><strong>QR / piletikood:</strong> <?= htmlspecialchars($mainTicket['ticket_code']) ?></p>
    <?php if(!empty($mainTicket['group_code'])): ?>
      <p><strong>Grupi kood:</strong> <?= htmlspecialchars($mainTicket['group_code']) ?></p>
    <?php endif; ?>

    <p><strong>Reisija:</strong> <?= htmlspecialchars($mainTicket['passenger_name']) ?></p>
    <p><strong>Marsruut:</strong> <?= htmlspecialchars($mainTicket['route_text']) ?></p>
    <p><strong>Summa kokku:</strong> <?= number_format($totalPrice, 2) ?> €</p>

    <?php if(!empty($mainTicket['user_id'])): ?>
      <?php
        $uStmt2 = mysqli_prepare($connection, "SELECT full_name, username, public_code, role FROM users WHERE id = ? LIMIT 1");
        mysqli_stmt_bind_param($uStmt2, 'i', $mainTicket['user_id']);
        mysqli_stmt_execute($uStmt2);
        $ticketUser = mysqli_fetch_assoc(mysqli_stmt_get_result($uStmt2));
        $ticketUserIsStaff = $ticketUser && in_array($ticketUser['role'], ['conductor', 'admin']);
      ?>
      <?php if($ticketUser): ?>
        <?php if($ticketUserIsStaff): ?>
          <div style="margin:10px 0;padding:10px;background:#fef9c3;border-radius:6px;border-left:3px solid #ca8a04;">
            <strong>PERSONAL – sõit tasuta</strong><br>
            <small>Nimi: <?= htmlspecialchars($ticketUser['full_name'] ?: $ticketUser['username']) ?></small><br>
            <small>ID-kood: <?= htmlspecialchars($ticketUser['public_code']) ?></small>
          </div>
        <?php else: ?>
          <div style="margin:10px 0;padding:10px;background:#f0f4ff;border-radius:6px;border-left:3px solid #1565c0;">
            <strong>👤 Kasutaja andmed</strong><br>
            <small>Nimi: <?= htmlspecialchars($ticketUser['full_name'] ?: $ticketUser['username']) ?></small><br>
            <small>Kasutajanimi: <?= htmlspecialchars($ticketUser['username']) ?></small><br>
            <small>ID-kood: <?= htmlspecialchars($ticketUser['public_code']) ?></small>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>

    <div style="margin-top:12px;border-top:1px solid #eee;">
      <?php foreach($groupTickets as $gt): ?>
        <div style="padding:10px 0;border-bottom:1px solid #eee;">
          <strong><?= htmlspecialchars($gt['category_name']) ?></strong> —
          <?= number_format((float)$gt['price'], 2) ?> €<br>
          <small>Kood: <?= htmlspecialchars($gt['ticket_code']) ?></small><br>
          <small>Kehtib kuni: <?= date('d.m.Y H:i', strtotime($gt['valid_until'])) ?></small><br>
          <small>Staatus: <?= htmlspecialchars($gt['status']) ?></small>

          <form method="POST" style="margin-top:6px;"
                onsubmit="return confirm('Tühistad selle pileti ja tagastad raha?')">
            <input type="hidden" name="action" value="refund">
            <input type="hidden" name="ticket_code" value="<?= htmlspecialchars($gt['ticket_code']) ?>">
            <button class="btn danger" type="submit" style="width:auto;padding:6px 14px;font-size:13px;">
              ↩ Tühista see pilet
            </button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<?php if($passenger && !empty($passengerTickets)): ?>
  <div class="card">
    <h3>Kehtivad piletid — <?= htmlspecialchars($passenger['full_name'] ?: $passenger['username']) ?></h3>

    <div style="margin-bottom:10px;padding:10px;background:#f0f4ff;border-radius:6px;border-left:3px solid #1565c0;">
      <strong>👤 Kasutaja andmed</strong><br>
      <small>Kasutajanimi: <?= htmlspecialchars($passenger['username']) ?></small><br>
      <small>ID-kood: <?= htmlspecialchars($passenger['public_code']) ?></small>
    </div>

    <?php
      $shownGroups = [];
      foreach($passengerTickets as $pt):
        $key = !empty($pt['group_code']) ? $pt['group_code'] : $pt['ticket_code'];

        if(isset($shownGroups[$key])) {
            continue;
        }

        $sameGroup = [];
        foreach($passengerTickets as $x) {
            $xKey = !empty($x['group_code']) ? $x['group_code'] : $x['ticket_code'];
            if($xKey === $key) {
                $sameGroup[] = $x;
            }
        }

        $shownGroups[$key] = true;
        $sum = array_sum(array_map(fn($t) => (float)$t['price'], $sameGroup));
    ?>
      <div style="border-top:1px solid #eee;padding:10px 0;">
        <strong><?= count($sameGroup) > 1 ? 'Ühine ost / grupipilet' : htmlspecialchars($pt['category_name']) ?></strong><br>
        <small><?= htmlspecialchars($pt['route_text']) ?></small><br>
        <small>Pileteid: <?= count($sameGroup) ?> | Kokku: <?= number_format($sum, 2) ?> €</small>

        <?php foreach($sameGroup as $gt): ?>
          <div style="margin-top:6px;padding-left:10px;border-left:3px solid #eee;">
            <?= htmlspecialchars($gt['category_name']) ?> —
            <?= number_format((float)$gt['price'], 2) ?> €<br>
            <small>Kood: <?= htmlspecialchars($gt['ticket_code']) ?></small><br>
            <small>Kehtib kuni: <?= date('d.m.Y H:i', strtotime($gt['valid_until'])) ?></small>

            <form method="POST" style="margin-top:6px;">
              <input type="hidden" name="action" value="refund">
              <input type="hidden" name="ticket_code" value="<?= htmlspecialchars($gt['ticket_code']) ?>">
              <button class="btn danger" type="submit"
                      style="padding:5px 12px;font-size:13px;width:auto;"
                      onclick="return confirm('Tühistad selle pileti ja tagastad raha?')">
                ↩ Tühista
              </button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="POST" id="validate_form">
  <input type="hidden" name="action" value="validate">
  <label>Pileti kood või kasutaja ID-kood</label>
  <input type="text" name="ticket_code" id="ticket_code_input" autofocus autocomplete="off"
         placeholder="Sisesta või skänneeri kood või kasutaja ID" required>
  <button class="btn" type="submit">Valideeri</button>
</form>

<button type="button" class="btn secondary" id="btn_scan_ticket" style="margin-top:0.6rem;">
  📷 Valideeri kaameraga
</button>

<div id="scan_ticket_container" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.92);z-index:9999;flex-direction:column;align-items:center;justify-content:center;">
  <p style="color:#fff;font-size:16px;margin-bottom:1rem;">Suuna kaamera pileti QR-koodile</p>
  <div id="scan_ticket_reader" style="width:min(90vw,400px);"></div>
  <button type="button" class="btn secondary" id="btn_scan_ticket_stop" style="margin-top:1.5rem;font-size:18px;padding:12px 32px;">✕ Sulge kaamera</button>
</div>

<a class="btn secondary" href="dashboard.php" style="margin-top:1.5rem;display:block;">← Tagasi</a>

<script>
let html5QrTicket = null;

function stopTicketScan() {
  if(html5QrTicket) {
    html5QrTicket.stop().catch(() => {});
    html5QrTicket = null;
  }

  document.getElementById('scan_ticket_container').style.display = 'none';
  document.getElementById('btn_scan_ticket').style.display = 'block';
}

document.getElementById('btn_scan_ticket').addEventListener('click', function() {
  document.getElementById('scan_ticket_container').style.display = 'flex';
  this.style.display = 'none';

  html5QrTicket = new Html5Qrcode('scan_ticket_reader');

  html5QrTicket.start(
    { facingMode: 'environment' },
    { fps: 10, qrbox: { width: 280, height: 280 } },
    (decodedText) => {
      document.getElementById('ticket_code_input').value = decodedText;
      stopTicketScan();
      document.getElementById('validate_form').submit();
    },
    () => {}
  ).catch(() => {
    stopTicketScan();
  });
});

document.getElementById('btn_scan_ticket_stop').addEventListener('click', stopTicketScan);
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
