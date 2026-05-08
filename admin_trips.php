<?php
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';

require_role('admin');

$message = '';

if(isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_trip') {
    $trip_number = trim($_POST['trip_number'] ?? '');
    $route_name = trim($_POST['route_name'] ?? '');
    $start_station_id = (int)$_POST['start_station_id'];
    $end_station_id = (int)$_POST['end_station_id'];
    $scheduled_start = $_POST['scheduled_start'];
    $scheduled_end = $_POST['scheduled_end'];
    $status = $_POST['status'];

    mysqli_begin_transaction($connection);

    try {
        $stmt = mysqli_prepare($connection, "
            INSERT INTO trips 
            (trip_number, route_name, start_station_id, end_station_id, scheduled_start, scheduled_end, status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        mysqli_stmt_bind_param($stmt, 'ssiisss', $trip_number, $route_name, $start_station_id, $end_station_id, $scheduled_start, $scheduled_end, $status);

        if(!mysqli_stmt_execute($stmt)) {
            throw new Exception(mysqli_error($connection));
        }

        $trip_id = mysqli_insert_id($connection);

        $stop_station_ids = $_POST['stop_station_id'] ?? [];
        $stop_departure_times = $_POST['stop_departure_time'] ?? [];

        $insertStop = mysqli_prepare($connection, "
            INSERT INTO trip_stops 
            (trip_id, station_id, stop_order, departure_time)
            VALUES (?, ?, ?, ?)
        ");

        foreach($stop_station_ids as $index => $station_id) {
            $station_id = (int)$station_id;
            if($station_id <= 0) continue;

            $stop_order = $index + 1;
            $departure_time = trim($stop_departure_times[$index] ?? '') ?: null;

            mysqli_stmt_bind_param($insertStop, 'iiis', $trip_id, $station_id, $stop_order, $departure_time);

            if(!mysqli_stmt_execute($insertStop)) {
                throw new Exception(mysqli_error($connection));
            }
        }

        mysqli_commit($connection);
        $message = 'Reis koos peatustega lisatud.';
    } catch(Exception $e) {
        mysqli_rollback($connection);
        $message = 'Viga: ' . $e->getMessage();
    }
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_trip_stops') {
    $trip_id = (int)$_POST['trip_id'];
    $stop_station_ids = $_POST['stop_station_id'] ?? [];
    $stop_departure_times = $_POST['stop_departure_time'] ?? [];

    mysqli_begin_transaction($connection);

    try {
        $delete = mysqli_prepare($connection, "DELETE FROM trip_stops WHERE trip_id = ?");
        mysqli_stmt_bind_param($delete, 'i', $trip_id);

        if(!mysqli_stmt_execute($delete)) {
            throw new Exception(mysqli_error($connection));
        }

        $insertStop = mysqli_prepare($connection, "
            INSERT INTO trip_stops 
            (trip_id, station_id, stop_order, departure_time)
            VALUES (?, ?, ?, ?)
        ");

        foreach($stop_station_ids as $index => $station_id) {
            $station_id = (int)$station_id;
            if($station_id <= 0) continue;

            $stop_order = $index + 1;
            $departure_time = trim($stop_departure_times[$index] ?? '') ?: null;

            mysqli_stmt_bind_param($insertStop, 'iiis', $trip_id, $station_id, $stop_order, $departure_time);

            if(!mysqli_stmt_execute($insertStop)) {
                throw new Exception(mysqli_error($connection));
            }
        }

        mysqli_commit($connection);
        $message = 'Reisi peatused salvestatud.';
    } catch(Exception $e) {
        mysqli_rollback($connection);
        $message = 'Viga: ' . $e->getMessage();
    }
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_station') {
    $name = trim($_POST['station_name'] ?? '');
    $zone_id = (int)($_POST['zone_id'] ?? 0);
    $stop_order = (int)($_POST['stop_order'] ?? 0);

    $stmt = mysqli_prepare($connection, "
        INSERT INTO stations (name, zone_id, stop_order) 
        VALUES (?, ?, ?)
    ");

    mysqli_stmt_bind_param($stmt, 'sii', $name, $zone_id, $stop_order);
    $message = mysqli_stmt_execute($stmt) ? 'Peatus lisatud.' : 'Viga: ' . mysqli_error($connection);
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reorder_stations') {
    $orders = $_POST['order'] ?? [];

    foreach($orders as $stationId => $order) {
        $stationId = (int)$stationId;
        $order = (int)$order;

        $s = mysqli_prepare($connection, "UPDATE stations SET stop_order = ? WHERE id = ?");
        mysqli_stmt_bind_param($s, 'ii', $order, $stationId);
        mysqli_stmt_execute($s);
    }

    $message = 'Järjekord salvestatud.';
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && $message !== '') {
    $_SESSION['flash_message'] = $message;
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$stations = mysqli_query($connection, "
    SELECT s.id, s.name, s.stop_order, z.name AS zone_name, z.id AS zone_id
    FROM stations s
    JOIN zones z ON z.id = s.zone_id
    ORDER BY s.stop_order ASC, s.id ASC
");

$stationsArr = mysqli_fetch_all($stations, MYSQLI_ASSOC);

$zones = mysqli_query($connection, "
    SELECT id, name, zone_number 
    FROM zones 
    ORDER BY zone_number ASC
");

$trips = mysqli_query($connection, "
    SELECT t.*, s1.name AS start_station, s2.name AS end_station
    FROM trips t
    JOIN stations s1 ON s1.id = t.start_station_id
    JOIN stations s2 ON s2.id = t.end_station_id
    ORDER BY t.scheduled_start DESC
");

require __DIR__ . '/includes/header.php';
?>

<style>
.trip-stops-builder {
  display: grid;
  gap: 10px;
  margin-bottom: 12px;
}

.trip-stop-row {
  display: grid;
  grid-template-columns: 34px 1fr 150px 42px;
  gap: 10px;
  align-items: center;
  padding: 10px;
  border: 1px solid 
  background: 
}

.trip-stop-number {
  width: 30px;
  height: 30px;
  border-radius: 999px;
  background: 
  color: 
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 13px;
}

.trip-stop-row select,
.trip-stop-row input {
  margin: 0;
  width: 100%;
}

.trip-stop-remove {
  width: 42px;
  height: 42px;
  border: 1px solid 
  background: 
  color: 
  cursor: pointer;
  font-size: 20px;
  line-height: 1;
  border-radius: 0;
  display: flex;
  align-items: center;
  justify-content: center;
}

.trip-stop-remove:hover {
  opacity: 0.85;
}

.add-stop-btn {
  width: 100%;
  border: 1px solid 
  background: 
  color: 
  padding: 13px;
  cursor: pointer;
  font-weight: 700;
  text-transform: uppercase;
  border-radius: 0;
}

.add-stop-btn:hover {
  opacity: 0.85;
}

.trip-stop-help {
  font-size: 12px;
  color: 
  margin-top: 4px;
}

.trip-edit-box {
  margin-top: 0.8rem;
  padding: 0.9rem;
  background: 
  border: 1px solid var(--border);
}

@media (max-width: 700px) {
  .trip-stop-row {
    grid-template-columns: 30px 1fr;
  }

  .trip-stop-row input,
  .trip-stop-remove {
    grid-column: 2;
    width: 100%;
  }
}
</style>

<h2>Reisid ja sõiduplaan</h2>

<a class="btn secondary" href="dashboard.php" style="margin-bottom:1rem;display:inline-block;">
  ← Tagasi
</a>

<?php if($message): ?>
  <div class="message success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<h3>Lisa reis</h3>

<form method="POST">
  <input type="hidden" name="action" value="add_trip">

  <label>Reisi number</label>
  <input type="text" name="trip_number" placeholder="nt 142">

  <label>Liini / reisi nimi</label>
  <input type="text" name="route_name" placeholder="Tallinn → Pääsküla" required>

  <label>Algpeatus</label>
  <select name="start_station_id" required>
    <?php foreach($stationsArr as $s): ?>
      <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
    <?php endforeach; ?>
  </select>

  <label>Lõpp-peatus</label>
  <select name="end_station_id" required>
    <?php foreach($stationsArr as $s): ?>
      <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
    <?php endforeach; ?>
  </select>

  <label>Planeeritud algus</label>
  <input type="datetime-local" name="scheduled_start" required>

  <label>Planeeritud lõpp</label>
  <input type="datetime-local" name="scheduled_end" required>

  <label>Staatus</label>
  <select name="status">
    <option value="planned">Planeeritud</option>
    <option value="active">Aktiivne</option>
    <option value="ended">Lõppenud</option>
    <option value="cancelled">Tühistatud</option>
  </select>

  <h4 style="margin-top:1.5rem;">Reisi peatused</h4>

  <div id="newTripStops" class="trip-stops-builder">
    <div class="trip-stop-row">
      <div class="trip-stop-number">1</div>

      <select name="stop_station_id[]" required>
        <option value="">Vali peatus</option>
        <?php foreach($stationsArr as $s): ?>
          <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
        <?php endforeach; ?>
      </select>

      <input type="time" name="stop_departure_time[]">

      <button type="button" class="trip-stop-remove" onclick="removeStopRow(this)">×</button>
    </div>
  </div>

  <button type="button" class="add-stop-btn" onclick="addStopRow('newTripStops')">
    + Lisa peatus
  </button>

  <p class="trip-stop-help">
    Peatuste järjekord salvestatakse ülevalt alla. Lõpp-peatuse väljumisaja võib tühjaks jätta.
  </p>

  <button class="btn" type="submit">Lisa reis</button>
</form>

<h3 style="margin-top:2rem;">Olemasolevad reisid</h3>

<?php while($trip = mysqli_fetch_assoc($trips)): ?>
  <?php
    $tsStmt = mysqli_prepare($connection, "
        SELECT ts.id, ts.station_id, ts.stop_order, ts.departure_time, s.name AS station_name
        FROM trip_stops ts
        JOIN stations s ON s.id = ts.station_id
        WHERE ts.trip_id = ?
        ORDER BY ts.stop_order ASC
    ");

    mysqli_stmt_bind_param($tsStmt, 'i', $trip['id']);
    mysqli_stmt_execute($tsStmt);
    $tripStops = mysqli_fetch_all(mysqli_stmt_get_result($tsStmt), MYSQLI_ASSOC);

    $containerId = 'tripStops_' . (int)$trip['id'];
  ?>

  <div class="card">
    <?php if($trip['trip_number']): ?>
      <p><strong>Reisi nr <?= htmlspecialchars($trip['trip_number']) ?></strong></p>
    <?php endif; ?>

    <p><strong><?= htmlspecialchars($trip['route_name']) ?></strong></p>
    <p><?= htmlspecialchars($trip['start_station']) ?> → <?= htmlspecialchars($trip['end_station']) ?></p>
    <p><?= htmlspecialchars($trip['scheduled_start']) ?> kuni <?= htmlspecialchars($trip['scheduled_end']) ?></p>
    <p>Staatus: <?= htmlspecialchars($trip['status']) ?></p>

    <?php if(!empty($tripStops)): ?>
      <p style="margin-top:0.8rem;font-size:0.8rem;color:var(--muted);text-transform:uppercase;letter-spacing:1px;">
        Peatused
      </p>

      <table style="width:100%;border-collapse:collapse;margin-bottom:0.8rem;">
        <tr style="font-size:12px;color:var(--muted);">
          <th style="text-align:left;padding:4px 6px;">
          <th style="text-align:left;padding:4px 6px;">Peatus</th>
          <th style="text-align:left;padding:4px 6px;">Väljumisaeg</th>
        </tr>

        <?php foreach($tripStops as $ts): ?>
          <tr style="border-top:1px solid var(--border);font-size:13px;">
            <td style="padding:4px 6px;"><?= (int)$ts['stop_order'] ?></td>
            <td style="padding:4px 6px;"><?= htmlspecialchars($ts['station_name']) ?></td>
            <td style="padding:4px 6px;">
              <?= $ts['departure_time'] ? htmlspecialchars($ts['departure_time']) : '<em style="color:#aaa;">lõpp-peatus</em>' ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php else: ?>
      <p style="font-size:13px;color:#aaa;margin-top:0.5rem;">
        <em>Peatusi pole lisatud.</em>
      </p>
    <?php endif; ?>

    <details style="margin-top:0.7rem;">
      <summary style="font-size:13px;cursor:pointer;color:var(--accent);">
        Muuda selle reisi peatusi
      </summary>

      <form method="POST" class="trip-edit-box">
        <input type="hidden" name="action" value="save_trip_stops">
        <input type="hidden" name="trip_id" value="<?= (int)$trip['id'] ?>">

        <div id="<?= $containerId ?>" class="trip-stops-builder">
          <?php if(!empty($tripStops)): ?>
            <?php foreach($tripStops as $index => $ts): ?>
              <div class="trip-stop-row">
                <div class="trip-stop-number"><?= $index + 1 ?></div>

                <select name="stop_station_id[]" required>
                  <option value="">Vali peatus</option>
                  <?php foreach($stationsArr as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id'] === (int)$ts['station_id']) ? 'selected' : '' ?>>
                      <?= htmlspecialchars($s['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>

                <input type="time" name="stop_departure_time[]" value="<?= htmlspecialchars($ts['departure_time'] ?? '') ?>">

                <button type="button" class="trip-stop-remove" onclick="removeStopRow(this)">×</button>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="trip-stop-row">
              <div class="trip-stop-number">1</div>

              <select name="stop_station_id[]" required>
                <option value="">Vali peatus</option>
                <?php foreach($stationsArr as $s): ?>
                  <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                <?php endforeach; ?>
              </select>

              <input type="time" name="stop_departure_time[]">

              <button type="button" class="trip-stop-remove" onclick="removeStopRow(this)">×</button>
            </div>
          <?php endif; ?>
        </div>

        <button type="button" class="add-stop-btn" onclick="addStopRow('<?= $containerId ?>')">
          + Lisa peatus
        </button>

        <p class="trip-stop-help">
          Järjekord salvestatakse ülevalt alla.
        </p>

        <button class="btn" type="submit" style="margin-top:0.5rem;">
          Salvesta peatused
        </button>
      </form>
    </details>
  </div>
<?php endwhile; ?>

<hr style="margin:2rem 0;">

<h3>Peatuste haldus</h3>

<h4>Lisa peatus</h4>

<form method="POST">
  <input type="hidden" name="action" value="add_station">

  <label>Peatuse nimi</label>
  <input type="text" name="station_name" required placeholder="nt Balti jaam">

  <label>Tsoon</label>
  <select name="zone_id" required>
    <?php mysqli_data_seek($zones, 0); while($z = mysqli_fetch_assoc($zones)): ?>
      <option value="<?= (int)$z['id'] ?>">
        <?= htmlspecialchars($z['name']) ?> 
        (tsoon <?= htmlspecialchars($z['zone_number']) ?>)
      </option>
    <?php endwhile; ?>
  </select>

  <label>Järjekorranumber</label>
  <input type="number" name="stop_order" value="<?= count($stationsArr) + 1 ?>" min="1">

  <button class="btn" type="submit">Lisa peatus</button>
</form>

<h4 style="margin-top:1.5rem;">Peatuste järjekord</h4>

<form method="POST">
  <input type="hidden" name="action" value="reorder_stations">

  <table style="width:100%;border-collapse:collapse;">
    <tr>
      <th style="text-align:left;padding:6px;">Peatus</th>
      <th style="text-align:left;padding:6px;">Tsoon</th>
      <th style="text-align:left;padding:6px;width:80px;">Järjekord</th>
    </tr>

    <?php foreach($stationsArr as $s): ?>
      <tr style="border-top:1px solid #eee;">
        <td style="padding:6px;"><?= htmlspecialchars($s['name']) ?></td>
        <td style="padding:6px;"><?= htmlspecialchars($s['zone_name']) ?></td>
        <td style="padding:6px;">
          <input 
            type="number" 
            name="order[<?= (int)$s['id'] ?>]"
            value="<?= (int)$s['stop_order'] ?>"
            style="width:60px;padding:4px;"
          >
        </td>
      </tr>
    <?php endforeach; ?>
  </table>

  <button class="btn secondary" type="submit" style="margin-top:0.8rem;">
    Salvesta järjekord
  </button>
</form>

<a class="btn secondary" href="dashboard.php" style="margin-top:1rem;display:inline-block;">
  ← Tagasi
</a>

<script>
function refreshStopNumbers(container) {
    container.querySelectorAll('.trip-stop-row').forEach((row, index) => {
        const number = row.querySelector('.trip-stop-number');
        if(number) {
            number.textContent = index + 1;
        }
    });
}

function addStopRow(containerId) {
    const container = document.getElementById(containerId);
    if(!container) return;

    const firstRow = container.querySelector('.trip-stop-row');
    if(!firstRow) return;

    const newRow = firstRow.cloneNode(true);

    const select = newRow.querySelector('select');
    const timeInput = newRow.querySelector('input[type="time"]');

    if(select) select.value = '';
    if(timeInput) timeInput.value = '';

    container.appendChild(newRow);
    refreshStopNumbers(container);
}

function removeStopRow(button) {
    const container = button.closest('.trip-stops-builder');
    if(!container) return;

    const rows = container.querySelectorAll('.trip-stop-row');

    if(rows.length <= 1) {
        alert('Vähemalt üks peatus peab jääma.');
        return;
    }

    button.closest('.trip-stop-row').remove();
    refreshStopNumbers(container);
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
