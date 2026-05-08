<?php
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';

require_login();

$trip_id = (int)($_GET['id'] ?? 0);
$isStaff = in_array($_SESSION['role'] ?? '', ['conductor', 'admin']);

if (!$trip_id) {
    if (isset($_SESSION['active_trip_id'])) {
        $trip_id = (int)$_SESSION['active_trip_id'];
    } elseif ($isStaff) {
        
        $allTrips = mysqli_query($connection, "
            SELECT id, trip_number, route_name, scheduled_start, status
            FROM trips
            WHERE status IN ('planned', 'active')
            ORDER BY scheduled_start ASC
        ");
        require __DIR__ . '/includes/header.php';
        ?>
        <a class="btn secondary" href="dashboard.php" style="margin-bottom:1.5rem;display:block;">← Tagasi töölauale</a>
        <h2>Vali reis</h2>
        <div class="menu-grid">
        <?php while ($tr = mysqli_fetch_assoc($allTrips)): ?>
            <a class="btn secondary" href="active_trip.php?id=<?= (int)$tr['id'] ?>">
                <?= $tr['trip_number'] ? '#' . htmlspecialchars($tr['trip_number']) . ' ' : '' ?>
                <?= htmlspecialchars($tr['route_name']) ?>
                <small style="display:block;color:var(--muted);font-size:0.75rem;margin-top:2px;">
                    <?= date('d.m.Y H:i', strtotime($tr['scheduled_start'])) ?>
                    · <?= $tr['status'] === 'active' ? '🟢 aktiivne' : '🕐 planeeritud' ?>
                </small>
            </a>
        <?php endwhile; ?>
        </div>
        <?php
        require __DIR__ . '/includes/footer.php';
        exit;
    } else {
        header('Location: dashboard.php');
        exit;
    }
}

$stmt = mysqli_prepare($connection,
    "SELECT * FROM trips WHERE id = ? LIMIT 1"
);
mysqli_stmt_bind_param($stmt, 'i', $trip_id);
mysqli_stmt_execute($stmt);
$trip = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$trip) {
    header('Location: dashboard.php');
    exit;
}

$stmt = mysqli_prepare($connection, "
    SELECT
        ts.stop_order,
        ts.departure_time,
        s.name AS station_name,
        z.zone_number,
        z.name AS zone_name
    FROM trip_stops ts
    JOIN stations s ON s.id = ts.station_id
    JOIN zones z ON z.id = s.zone_id
    WHERE ts.trip_id = ?
    ORDER BY ts.stop_order ASC
");
mysqli_stmt_bind_param($stmt, 'i', $trip_id);
mysqli_stmt_execute($stmt);
$stops_result = mysqli_stmt_get_result($stmt);
$stops = [];
while ($row = mysqli_fetch_assoc($stops_result)) {
    $stops[] = $row;
}

$isStaff = in_array($_SESSION['role'] ?? '', ['conductor', 'admin']);

$statusLabel = match($trip['status']) {
    'planned' => ['🕐', 'Planeeritud', '#888'],
    'active'  => ['🟢', 'Aktiivne',    '#2e7d32'],
    'ended'   => ['⬛', 'Lõpetatud',   '#444'],
    default   => ['⚪', $trip['status'], '#888'],
};

require __DIR__ . '/includes/header.php';
?>

<style>
.stop-list {
    position: relative;
    padding-left: 2.5rem;
    margin: 1rem 0;
}

.stop-item {
    position: relative;
    padding: 0.6rem 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
}

.stop-dot {
    position: absolute;
    left: -1.85rem;
    top: 50%;
    transform: translateY(-50%);
    width: 0.75rem;
    height: 0.75rem;
    box-sizing: border-box;
    border-radius: 50%;
    background: var(--border);
    border: 2px solid var(--box);
    z-index: 2;
}

.stop-item:not(.last-stop) .stop-dot::before {
    content: '';
    position: absolute;
    left: 50%;
    top: calc(100% + 2px);
    transform: translateX(-50%);
    width: 2px;
    height: calc(1.2rem + 0.75rem);
    background: var(--border);
    z-index: -1;
}

.stop-item:not(.first-stop) .stop-dot::after {
    content: '';
    position: absolute;
    left: 50%;
    bottom: calc(100% + 2px);
    transform: translateX(-50%);
    width: 2px;
    height: calc(1.2rem + 0.75rem);
    background: var(--border);
    z-index: -1;
}

.stop-item.first-stop .stop-dot,
.stop-item.last-stop .stop-dot {
    background: var(--text);
    border-color: var(--text);
}

.stop-name,
.stop-time {
    font-weight: bold;
}

.stop-name {
    font-size: 0.95rem;
}

.stop-zone {
    font-size: 0.75rem;
    color: var(--muted);
    margin-top: 2px;
}

.stop-time {
    font-size: 0.85rem;
    color: var(--text);
    white-space: nowrap;
}

.trip-header {
    border-left: 4px solid var(--accent);
    padding-left: 1rem;
    margin-bottom: 1.5rem;
}

.trip-header h3 {
    font-size: 1.2rem;
    margin-bottom: 0.25rem;
}

.trip-header .meta {
    font-size: 0.8rem;
    color: var(--muted);
}
</style>

<?php
$backUrl = $isStaff ? 'dashboard.php' : 'my_tickets.php';
$backLabel = $isStaff ? '← Tagasi töölauale' : '← Tagasi piletitele';
?>
<a class="btn secondary" href="<?= $backUrl ?>" style="margin-bottom:1.5rem;display:block;"><?= $backLabel ?></a>

<h2>Reisi info</h2>

<div class="trip-header">
    <h3>
        <?= $trip['trip_number'] ? '#' . htmlspecialchars($trip['trip_number']) . ' ' : '' ?>
        <?= htmlspecialchars($trip['route_name']) ?>
    </h3>
    <div class="meta">
        <span style="color:<?= $statusLabel[2] ?>;font-weight:bold;"><?= $statusLabel[0] ?> <?= $statusLabel[1] ?></span>
        &nbsp;·&nbsp;
        <?= date('d.m.Y', strtotime($trip['scheduled_start'])) ?>
        &nbsp;·&nbsp;
        <?= date('H:i', strtotime($trip['scheduled_start'])) ?>
        –
        <?= date('H:i', strtotime($trip['scheduled_end'])) ?>
    </div>
</div>

<?php if (empty($stops)): ?>
    <p style="color:var(--muted);">Sellel reisil pole peatusi lisatud.</p>
<?php else: ?>
    <div class="card">
        <strong style="font-size:0.8rem;color:var(--muted);text-transform:uppercase;letter-spacing:1px;">
            Peatused (<?= count($stops) ?>)
        </strong>

        <div class="stop-list">
        <?php
        $now = time();
        $nextFound = false;
        foreach ($stops as $i => $stop):
            $isFirst = ($i === 0);
            $isLast  = ($i === count($stops) - 1);

            
            $depTs = !empty($stop['departure_time']) ? strtotime(date('Y-m-d') . ' ' . $stop['departure_time']) : null;
            $isNext = false;
            if (!$nextFound && $depTs && $depTs > $now && $trip['status'] === 'active') {
                $isNext    = true;
                $nextFound = true;
            }

            $classes = 'stop-item';
            if ($isFirst) $classes .= ' first-stop';
            if ($isLast)  $classes .= ' last-stop';
        ?>
            <div class="<?= $classes ?>">
                <div class="stop-dot"></div>
                <div>
                    <div class="stop-name"><?= htmlspecialchars($stop['station_name']) ?></div>
                    <div class="stop-zone">Tsoon <?= (int)$stop['zone_number'] ?></div>
                </div>
                <?php if (!empty($stop['departure_time'])): ?>
                    <div class="stop-time">
                        <?= date('H:i', strtotime($stop['departure_time'])) ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($isStaff && !empty($trip['notes'])): ?>
    <div class="card">
        <strong>Märkused</strong>
        <p style="margin-top:0.5rem;"><?= nl2br(htmlspecialchars($trip['notes'])) ?></p>
    </div>
<?php endif; ?>

<a class="btn secondary" href="<?= $backUrl ?>" style="margin-top:1rem;display:block;"><?= $backLabel ?></a>

<?php require __DIR__ . '/includes/footer.php'; ?>
