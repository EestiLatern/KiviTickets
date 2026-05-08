<?php
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';

require_role(['conductor', 'admin']);

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? 'general';

    $_SESSION['work_mode'] = $mode;
    unset($_SESSION['active_trip_id']);

    if($mode === 'personal') {
        
        header('Location: dashboard.php');
        exit;
    }

    if($mode === 'trip') {
        $trip_id = (int)($_POST['trip_id'] ?? 0);

        if($trip_id > 0) {
            $_SESSION['active_trip_id'] = $trip_id;

            $stmt = mysqli_prepare($connection, "
                UPDATE trips
                SET status = 'active'
                WHERE id = ?
                  AND status = 'planned'
            ");
            mysqli_stmt_bind_param($stmt, 'i', $trip_id);
            mysqli_stmt_execute($stmt);
        }
    }

    header('Location: dashboard.php');
    exit;
}

$trips = mysqli_query($connection, "
    SELECT id, trip_number, route_name, scheduled_start, scheduled_end, status
    FROM trips
    WHERE status IN ('planned','active')
    ORDER BY scheduled_start ASC
");

require __DIR__ . '/includes/header.php';
?>

<h2>Vali režiim</h2>

<form method="POST">
  <label>Režiim</label>
  <select name="mode" id="mode">
    <option value="general">Üldine teenindus</option>
    <option value="trip">Kindel reis</option>
    <option value="personal">Isiklik sõit</option>
  </select>

  <div id="tripBlock" style="display:none;">
    <label>Reis</label>
    <select name="trip_id">
      <?php while($trip = mysqli_fetch_assoc($trips)): ?>
        <option value="<?= (int)$trip['id'] ?>">
          <?= $trip['trip_number'] ? '#'.htmlspecialchars($trip['trip_number']).' ' : '' ?>
          <?= htmlspecialchars($trip['route_name']) ?>
          —
          <?= date('d.m.Y H:i', strtotime($trip['scheduled_start'])) ?>
          —
          <?= $trip['status'] === 'active' ? 'aktiivne' : 'planeeritud' ?>
        </option>
      <?php endwhile; ?>
    </select>
  </div>

  <div id="personalInfo" style="display:none; padding:0.75rem; background:#f0fdf4; border:1px solid #86efac; border-radius:8px; margin-top:0.5rem;">
    <p style="margin:0; color:#166534; font-size:14px;">
      ✔ Isikliku sõidu režiimis kehtib sulle automaatselt <strong>100% sõidusoodus</strong>.<br>
      Kolleegile näidatakse sinu töötaja staatust.
    </p>
  </div>

  <button class="btn" type="submit" style="margin-top:1rem;">Jätka</button>
</form>

<script>
const mode      = document.getElementById('mode');
const tripBlock = document.getElementById('tripBlock');
const personalInfo = document.getElementById('personalInfo');

function toggleBlocks() {
  tripBlock.style.display    = mode.value === 'trip'     ? 'block' : 'none';
  personalInfo.style.display = mode.value === 'personal' ? 'block' : 'none';
}

mode.addEventListener('change', toggleBlocks);
toggleBlocks();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
