<?php
require 'includes/db.php';
require 'includes/auth.php';
require_login();

$user_id = $_SESSION['user_id'];

$stmt = mysqli_prepare($connection, "
    SELECT t.*, tc.name AS category_name,
           tr.route_name, tr.trip_number, tr.scheduled_start, tr.scheduled_end
    FROM tickets t
    LEFT JOIN ticket_categories tc ON tc.id = t.ticket_category_id
    LEFT JOIN trips tr ON tr.id = t.trip_id
    WHERE (
        t.user_id = ?
        OR (t.sold_by_user_id = ? AND t.user_id IS NULL)
    )
      AND NOT (
            t.status IN ('Tühistatud/Tagasi ostetud', 'expired')
            AND t.cancelled_at IS NOT NULL
            AND t.cancelled_at < NOW() - INTERVAL 24 HOUR
          )
      AND NOT (
            t.status = 'Kasutamata'
            AND t.valid_until IS NOT NULL
            AND t.valid_until < NOW() - INTERVAL 24 HOUR
          )
    ORDER BY t.created_at DESC
");
mysqli_stmt_bind_param($stmt, 'ii', $user_id, $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$total  = mysqli_num_rows($result);

require 'includes/header.php';
?>

<a class="btn secondary" href="dashboard.php" style="margin-bottom:1.5rem;display:block;">← Tagasi</a>
<h2>Minu piletid <span style="font-size:0.75rem;color:var(--muted);font-weight:normal;">(<?= $total ?>)</span></h2>

<?php if($total === 0): ?>
  <p>Sul pole ühtegi piletit.</p>
<?php else: ?>

<?php while($ticket = mysqli_fetch_assoc($result)):
    $validUntilTs = !empty($ticket['valid_until']) ? strtotime($ticket['valid_until']) : null;

    if($ticket['status'] === 'Tühistatud/Tagasi ostetud') {
        $badge = ['🔴', 'Tühistatud / Tagasi ostetud', '#c62828'];
    } elseif($ticket['status'] === 'Kasutamata') {
        $badge = ['🟡', 'Kasutamata', '#b45309'];
    } elseif($ticket['status'] === 'Valideeritud') {
        $badge = ['✅', 'Valideeritud', '#1565c0'];
    } elseif($ticket['status'] === 'Kehtib teisel reisil') {
        $badge = ['🔵', 'Kehtib teisel reisil', '#0277bd'];
    } else {
        $badge = ['🟢', 'Kehtib', '#2e7d32'];
    }

    $qrUrl = !empty($ticket['code_image_url'])
        ? $ticket['code_image_url']
        : 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($ticket['ticket_code']);
?>
<div class="card">
  <p style="font-size:13px;color:<?= $badge[2] ?>;font-weight:bold;margin-bottom:8px;">
    <?= $badge[0] ?> <?= $badge[1] ?>
  </p>

  <p><strong>Pileti liik:</strong> <?= htmlspecialchars($ticket['category_name'] ?? '—') ?></p>
  <p><strong>Marsruut:</strong> <?= htmlspecialchars($ticket['route_text'] ?: '—') ?></p>

  <?php if(!empty($ticket['route_name'])): ?>
    <p><strong>Reis:</strong>
      <?= $ticket['trip_number'] ? '#' . htmlspecialchars($ticket['trip_number']) . ' ' : '' ?>
      <?= htmlspecialchars($ticket['route_name']) ?>
      <?php if(!empty($ticket['scheduled_start'])): ?>
        <small style="color:#888;">(<?= date('d.m.Y H:i', strtotime($ticket['scheduled_start'])) ?>)</small>
      <?php endif; ?>
    </p>
    <a href="active_trip.php?id=<?= (int)$ticket['trip_id'] ?>"
       class="btn secondary"
       style="font-size:13px;padding:6px 12px;width:auto;margin-bottom:8px;">
      🚉 Vaata reisi peatusi
    </a>
  <?php else: ?>
    <p><strong>Reis:</strong> <em style="color:#999;">Seotud reisita</em></p>
  <?php endif; ?>

  <?php if($validUntilTs): ?>
    <p><strong>Kehtib kuni:</strong> <?= date('d.m.Y H:i', $validUntilTs) ?></p>
  <?php endif; ?>
  <p><strong>Hind:</strong> <?= number_format($ticket['price'], 2) ?> €</p>
  <p style="font-size:12px;color:#aaa;">Kood: <?= htmlspecialchars($ticket['ticket_code']) ?></p>

  <img src="<?= htmlspecialchars($qrUrl) ?>" style="width:160px;background:#fff;padding:6px;display:block;margin:10px 0;">

  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a class="btn secondary" href="print_ticket.php?id=<?= (int)$ticket['id'] ?>" target="_blank"
       style="font-size:13px;padding:6px 12px;width:auto;">🖨️ Prindi</a>
  </div>
</div>
<?php endwhile; ?>

<?php endif; ?>

<a class="btn secondary" href="dashboard.php" style="margin-top:1rem;display:block;">← Tagasi</a>

<?php require 'includes/footer.php'; ?>
