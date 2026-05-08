<?php
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';

require_login();

$isAdmin        = $_SESSION['role'] === 'admin';
$isConductor    = $_SESSION['role'] === 'conductor';
$isUser         = $_SESSION['role'] === 'user';
$isPersonalTrip = ($isConductor || $isAdmin) && ($_SESSION['work_mode'] ?? '') === 'personal';

require __DIR__ . '/includes/header.php';
?>

<h2>Töölaud</h2>

<div class="menu-grid">
  <?php if($isPersonalTrip): ?>
    <a class="btn" href="my_tickets.php">Minu piletid</a>
    <a class="btn secondary" href="profile.php">Minu profiil</a>
    <a class="btn secondary" href="select_work_mode.php">Tööle</a>

  <?php elseif($isAdmin || $isConductor): ?>
    <a class="btn" href="sell_ticket.php">Müü pilet</a>
    <a class="btn" href="validate_ticket.php">Valideeri pilet</a>
    <a class="btn secondary" href="lookup_passenger.php">Kasutaja andmed</a>
    <?php if($isAdmin): ?>
      <a class="btn secondary" href="admin_users.php">Kasutajad</a>
      <a class="btn secondary" href="admin_trips.php">Reisid ja sõiduplaan</a>
    <?php endif; ?>
    <?php if (!empty($_SESSION['active_trip_id'])): ?>
      <a class="btn secondary" href="active_trip.php">See reis</a>
    <?php else: ?>
      <a class="btn secondary" href="active_trip.php">Reiside info</a>
    <?php endif; ?>
    <a class="btn secondary" href="end_trip.php">Lõpeta reis</a>
    <a class="btn secondary" href="select_work_mode.php">Vaheta töörežiimi / reisi</a>
    <a class="btn secondary" href="staff_profile.php">Mina</a>

  <?php elseif($isUser): ?>
    <a class="btn" href="my_tickets.php">Minu piletid</a>
    <a class="btn secondary" href="profile.php">Minu profiil</a>
  <?php endif; ?>

  <a class="btn danger" href="logout.php">Logi välja</a>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
