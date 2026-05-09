<?php
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';

require_login();

$id = (int)($_GET['id'] ?? 0);
$format = $_GET['format'] ?? 'a4';
$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'user';

if($role === 'user') {
    $format = 'a4';
}

$stmt = mysqli_prepare($connection, "
    SELECT t.*, tc.name AS category_name, tc.code AS category_code,
           u.public_code AS passenger_public_code,
           tr.trip_number, tr.route_name AS trip_route_name
    FROM tickets t
    LEFT JOIN ticket_categories tc ON tc.id = t.ticket_category_id
    LEFT JOIN users u ON u.id = t.user_id
    LEFT JOIN trips tr ON tr.id = t.trip_id
    WHERE t.id = ? AND (
        t.user_id = ?
        OR ? IN (SELECT id FROM users WHERE role IN ('admin','conductor'))
    )
    LIMIT 1
");
mysqli_stmt_bind_param($stmt, 'iii', $id, $user_id, $user_id);
mysqli_stmt_execute($stmt);
$ticket = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if(!$ticket) {
    die('Piletit ei leitud.');
}

$groupTickets = [$ticket];

if(!empty($ticket['group_code'])) {
    $gStmt = mysqli_prepare($connection, "
        SELECT t.*, tc.name AS category_name, tc.code AS category_code,
               u.public_code AS passenger_public_code,
               tr.trip_number, tr.route_name AS trip_route_name
        FROM tickets t
        LEFT JOIN ticket_categories tc ON tc.id = t.ticket_category_id
        LEFT JOIN users u ON u.id = t.user_id
        LEFT JOIN trips tr ON tr.id = t.trip_id
        WHERE t.group_code = ?
        ORDER BY t.id ASC
    ");
    mysqli_stmt_bind_param($gStmt, 's', $ticket['group_code']);
    mysqli_stmt_execute($gStmt);
    $res = mysqli_stmt_get_result($gStmt);

    $groupTickets = [];
    while($row = mysqli_fetch_assoc($res)) {
        $groupTickets[] = $row;
    }
}

$mainTicket = $groupTickets[0] ?? $ticket;
$totalPrice = array_sum(array_map(fn($t) => (float)$t['price'], $groupTickets));

$isPeriod = str_starts_with(strtoupper($mainTicket['category_code'] ?? ''), 'PERIOD');

$qrCodeValue = $mainTicket['ticket_code'] ?? $ticket['ticket_code'];
$invoiceNr = ltrim($qrCodeValue ?? '', '$');

$soldAt = !empty($mainTicket['valid_from'])
    ? date('d.m.Y H:i', strtotime($mainTicket['valid_from']))
    : '—';

$qrUrl = !empty($mainTicket['code_image_url'])
    ? $mainTicket['code_image_url']
    : 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($qrCodeValue);

$fromZone = '—';
$toZone = '—';

if(!empty($mainTicket['from_zone_id'])) {
    $fzStmt = mysqli_prepare($connection, "SELECT zone_number, name FROM zones WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($fzStmt, 'i', $mainTicket['from_zone_id']);
    mysqli_stmt_execute($fzStmt);
    $fz = mysqli_fetch_assoc(mysqli_stmt_get_result($fzStmt));
    if($fz) {
        $fromZone = 'Tsoon ' . $fz['zone_number'] . ' (' . $fz['name'] . ')';
    }
}

if(!empty($mainTicket['to_zone_id'])) {
    $tzStmt = mysqli_prepare($connection, "SELECT zone_number, name FROM zones WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($tzStmt, 'i', $mainTicket['to_zone_id']);
    mysqli_stmt_execute($tzStmt);
    $tz = mysqli_fetch_assoc(mysqli_stmt_get_result($tzStmt));
    if($tz) {
        $toZone = 'Tsoon ' . $tz['zone_number'] . ' (' . $tz['name'] . ')';
    }
}
?>
<!DOCTYPE html>
<html lang="et">
<head>
<meta charset="UTF-8">
<title>Pilet — <?= htmlspecialchars($qrCodeValue) ?></title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: Arial, sans-serif; background: 

.a4-wrap { display: flex; justify-content: center; padding: 40px; }
.a4-page {
    background: 
    width: 680px;
    font-size: 14px;
    color: 
    padding: 40px 50px;
}

.a4-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 20px;
}
.a4-header h1 { font-size: 20px; font-weight: bold; margin-bottom: 16px; }
.a4-fields { flex: 1; }
.a4-field {
    display: flex;
    margin-bottom: 6px;
    font-size: 14px;
}
.a4-field .lbl { font-weight: bold; width: 140px; flex-shrink: 0; }
.a4-field .val { flex: 1; }
.a4-qr-box {
    border: 1px solid 
    width: 130px;
    min-height: 145px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    margin-left: 20px;
    padding: 6px;
}
.a4-qr-box img { width: 100px; height: 100px; object-fit: contain; }
.a4-qr-label { font-size: 10px; text-align: center; margin-top: 4px; word-break: break-all; }

.a4-divider { border: none; border-top: 2px solid 

.a4-tickets-title { font-size: 18px; font-weight: bold; margin-bottom: 10px; }
.a4-tickets-list { font-size: 13px; margin-bottom: 6px; color: 
.a4-ticket-row {
    display: flex;
    justify-content: space-between;
    padding: 4px 0;
    border-bottom: 1px solid 
    font-size: 14px;
}

.receipt-wrap { display: flex; justify-content: center; padding: 20px; }
.receipt-ticket {
    background: 
    width: 302px;
    padding: 12px;
    font-family: 'Courier New', monospace;
    font-size: 12px;
}
.receipt-ticket h1 {
    font-size: 14px;
    text-align: center;
    margin-bottom: 8px;
    border-bottom: 1px dashed 
    padding-bottom: 6px;
}
.receipt-ticket .info { margin-bottom: 4px; }
.receipt-ticket .code { font-size: 11px; word-break: break-all; margin: 6px 0; }
.receipt-ticket .qr { text-align: center; margin-top: 8px; }
.receipt-ticket .qr img { width: 130px; }
.receipt-ticket .footer {
    text-align: center;
    margin-top: 8px;
    border-top: 1px dashed 
    padding-top: 6px;
    font-size: 10px;
}

.print-bar { 
    text-align: center; 
    padding: 20px; 
}

.print-bar button, 
.print-bar a {
    margin: 6px;
    padding: 16px 24px;
    font-size: 18px;
    min-height: 52px;
    cursor: pointer;
    border: 1px solid #333;
    background: #fff;
    border-radius: 12px;
    text-decoration: none;
    color: #000;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    touch-action: manipulation;
}

.print-bar button { 
    background: #f2f2f2; 
}

@media print {
    .print-bar { display: none; }
    body { background: 
    .a4-wrap, .receipt-wrap { padding: 0; }
    <?php if($format === 'receipt'): ?>
    @page { size: 80mm auto; margin: 0; }
    <?php else: ?>
    @page { size: A4; margin: 15mm; }
    <?php endif; ?>
}
</style>
</head>
<body>

<div class="print-bar">
    <?php if($role !== 'user'): ?>
        <a href="print_ticket.php?id=<?= $id ?>&format=a4">A4 formaat</a>
        <a href="print_ticket.php?id=<?= $id ?>&format=receipt">Tšeki formaat (80mm)</a>
    <?php endif; ?>
    <button onclick="window.print()">🖨️ Prindi</button>
    <a href="javascript:history.back()">← Tagasi</a>
</div>

<?php if($format === 'receipt'): ?>

<div class="receipt-wrap">
  <div class="receipt-ticket">
    <h1>KIVITICKETS</h1>

    <div class="info"><strong>Reisija nimi:</strong> <?= htmlspecialchars($mainTicket['passenger_name']) ?></div>
    <div class="info"><strong>Reisija tunnus:</strong> <?= htmlspecialchars($mainTicket['passenger_public_code'] ?? '—') ?></div>
    <div class="info"><strong>Marsruut:</strong> <?= htmlspecialchars($mainTicket['trip_route_name'] ?? $mainTicket['route_text']) ?></div>
    <div class="info"><strong>Lähtetsoon:</strong> <?= htmlspecialchars($fromZone) ?></div>
    <div class="info"><strong>Sihttsoon:</strong> <?= htmlspecialchars($toZone) ?></div>

    <?php if(!$isPeriod): ?>
      <div class="info"><strong>Reis:</strong> <?= !empty($mainTicket['trip_number']) ? htmlspecialchars($mainTicket['trip_number']) : '—' ?></div>
    <?php endif; ?>

    <div class="info"><strong>Müüdud:</strong> <?= htmlspecialchars($soldAt) ?></div>
    <div class="info"><strong>Summa kokku:</strong> <?= number_format($totalPrice, 2) ?> €</div>

    <div class="code"><strong>Arve nr:</strong> <?= htmlspecialchars($invoiceNr) ?></div>
    <div class="code"><strong>QR:</strong> <?= htmlspecialchars($qrCodeValue) ?></div>

    <div style="border-top:1px dashed #000;border-bottom:1px dashed #000;padding:6px 0;margin:8px 0;">

      <div class="info" style="font-weight:bold;">
        PILETID:
      </div>

      <?php foreach($groupTickets as $gt): ?>
        <div class="info">
          <?= htmlspecialchars($gt['category_name'] ?? '—') ?>
          — <?= number_format((float)$gt['price'], 2) ?> €
        </div>
      <?php endforeach; ?>

    </div>

    <div class="qr"><img src="<?= htmlspecialchars($qrUrl) ?>"></div>
    <div class="footer">Säilitake kviitung reisi lõpuni! Head reisi!</div>
  </div>
</div>

<?php else: ?>

<div class="a4-wrap">
  <div class="a4-page">

    <div class="a4-header">
      <div class="a4-fields">
        <h1>KiviTickets sõidupilet</h1>

        <div class="a4-field">
          <span class="lbl">Reisija nimi:</span>
          <span class="val"><?= htmlspecialchars($mainTicket['passenger_name']) ?></span>
        </div>
        <div class="a4-field">
          <span class="lbl">Reisija tunnus:</span>
          <span class="val"><?= htmlspecialchars($mainTicket['passenger_public_code'] ?? '—') ?></span>
        </div>
        <div class="a4-field">
          <span class="lbl">Marsruut:</span>
          <span class="val"><?= htmlspecialchars($mainTicket['trip_route_name'] ?? $mainTicket['route_text']) ?></span>
        </div>
        <div class="a4-field">
          <span class="lbl">Lähtetsoon:</span>
          <span class="val"><?= htmlspecialchars($fromZone) ?></span>
        </div>
        <div class="a4-field">
          <span class="lbl">Sihttsoon:</span>
          <span class="val"><?= htmlspecialchars($toZone) ?></span>
        </div>

        <?php if(!$isPeriod): ?>
          <div class="a4-field">
            <span class="lbl">Reis:</span>
            <span class="val"><?= !empty($mainTicket['trip_number']) ? htmlspecialchars($mainTicket['trip_number']) : '—' ?></span>
          </div>
        <?php endif; ?>

        <div class="a4-field">
          <span class="lbl">Müüdud:</span>
          <span class="val"><?= htmlspecialchars($soldAt) ?></span>
        </div>
        <div class="a4-field">
          <span class="lbl">Summa kokku:</span>
          <span class="val"><?= number_format($totalPrice, 2) ?> €</span>
        </div>
        <div class="a4-field">
          <span class="lbl">Arve nr:</span>
          <span class="val"><?= htmlspecialchars($invoiceNr) ?></span>
        </div>
        <div class="a4-field">
          <span class="lbl">QR:</span>
          <span class="val"><?= htmlspecialchars($qrCodeValue) ?></span>
        </div>
      </div>

      <div class="a4-qr-box">
        <img src="<?= htmlspecialchars($qrUrl) ?>" alt="QR kood">
        <div class="a4-qr-label">QR: <?= htmlspecialchars($qrCodeValue) ?></div>
      </div>
    </div>

    <hr class="a4-divider">

    <div class="a4-tickets-title">Piletid</div>
    <div class="a4-tickets-list">
      <?php foreach($groupTickets as $gt): ?>
        <div class="a4-ticket-row">
          <span><?= htmlspecialchars($gt['category_name'] ?? '—') ?></span>
          <span><?= number_format((float)$gt['price'], 2) ?> €</span>
        </div>
      <?php endforeach; ?>
    </div>

  </div>
</div>

<?php endif; ?>

</body>
</html>
