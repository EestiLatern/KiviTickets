<?php
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';

require_role(['conductor', 'admin']);

$message = '';

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $trip_id = (int)$_POST['trip_id'];

    $stmt = mysqli_prepare($connection, "
        UPDATE trips
        SET status = 'ended',
            actual_end = NOW()
        WHERE id = ?
    ");
    mysqli_stmt_bind_param($stmt, 'i', $trip_id);
    mysqli_stmt_execute($stmt);

    
    $markUsed = mysqli_prepare($connection, "
        UPDATE tickets
        SET status = 'Kasutatud/Reis lõppenud',
            cancelled_at = NOW()
        WHERE trip_id = ?
          AND status = 'Valideeritud'
    ");
    mysqli_stmt_bind_param($markUsed, 'i', $trip_id);
    mysqli_stmt_execute($markUsed);
    $usedCount = mysqli_affected_rows($connection);

    
    $expire = mysqli_prepare($connection, "
        UPDATE tickets
        SET status = 'Kasutamata',
            cancelled_at = NOW()
        WHERE trip_id = ?
          AND status IN ('Kehtib', 'Kehtib teisel reisil')
    ");
    mysqli_stmt_bind_param($expire, 'i', $trip_id);
    mysqli_stmt_execute($expire);
    $expiredCount = mysqli_affected_rows($connection);

    $message = 'Reis lõpetatud.';
    if($usedCount > 0) $message .= ' ' . $usedCount . ' piletit märgiti kasutatud.';
    if($expiredCount > 0) $message .= ' ' . $expiredCount . ' üksikpiletit aegus.';

    if(isset($_SESSION['active_trip_id']) && (int)$_SESSION['active_trip_id'] === $trip_id) {
        unset($_SESSION['active_trip_id']);
    }
}

$trips = mysqli_query($connection, "
    SELECT id, trip_number, route_name, scheduled_start, scheduled_end
    FROM trips
    WHERE status IN ('planned','active')
    ORDER BY scheduled_start ASC
");

require __DIR__ . '/includes/header.php';
?>

<h2>Lõpeta reis</h2>

<?php if($message): ?>
  <div class="message success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<form method="POST">
  <label>Vali reis</label>
  <select name="trip_id" required>
    <option value="">— Vali reis —</option>
    <?php while($trip = mysqli_fetch_assoc($trips)): ?>
      <option value="<?= (int)$trip['id'] ?>">
        <?= $trip['trip_number'] ? '#'.htmlspecialchars($trip['trip_number']).' ' : '' ?>
        <?= htmlspecialchars($trip['route_name']) ?>
        — <?= date('d.m.Y H:i', strtotime($trip['scheduled_start'])) ?>
      </option>
    <?php endwhile; ?>
  </select>

  <button class="btn danger" type="submit"
          onclick="return confirm('Lõpetad reisi? Kõik selle reisi kehtivad üksikpiletid aeguvad kohe.')">
    Lõpeta reis
  </button>
</form>

<a class="btn secondary" href="dashboard.php">Tagasi</a>

<?php require __DIR__ . '/includes/footer.php'; ?>
