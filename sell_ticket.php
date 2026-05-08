<?php
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/code_generator.php';

if(isset($_GET['get_stations']) && !empty($_GET['trip_id'])) {
    $tid = (int)$_GET['trip_id'];
    $s = mysqli_prepare($connection, "
        SELECT s.id, s.name, s.zone_id, z.name AS zone_name, z.zone_number
        FROM trip_stops ts
        JOIN stations s ON s.id = ts.station_id
        JOIN zones z ON z.id = s.zone_id
        WHERE ts.trip_id = ?
        ORDER BY ts.stop_order ASC
    ");
    mysqli_stmt_bind_param($s, 'i', $tid);
    mysqli_stmt_execute($s);
    $rows = [];
    while($r = mysqli_fetch_assoc(mysqli_stmt_get_result($s))) $rows[] = $r;
    header('Content-Type: application/json');
    echo json_encode($rows);
    exit;
}

require_role(['conductor', 'admin']);

if(!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

$message = '';
$messageType = '';
$createdTickets = [];

function generate_ticket_code($connection) {
    do {
        $code = '$$62' . rand(10,99) . '2' . rand(1000,9999);
        $stmt = mysqli_prepare($connection, "SELECT id FROM tickets WHERE ticket_code = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 's', $code);
        mysqli_stmt_execute($stmt);
        $exists = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    } while($exists);
    return $code;
}

function calc_price($code, $zoneDiff, $isFree, $isPersonalTrip = false) {
    if($isFree || $isPersonalTrip) return 0.00;
    $full = 1.40 + ($zoneDiff * 0.40);
    $disc = 1.20 + ($zoneDiff * 0.20);

    switch($code) {
        case 'FULL':                                             return round($full * 100) / 100;
        case 'DISCOUNT':                                         return round($disc * 100) / 100;
        case 'SPECIAL_FREE': case 'PET_FREE': case 'KID_FREE':  return 0.00;
        case 'BIKE':
            $p = round($full * 0.5 * 10) / 10;
            return max(1.00, min(4.00, $p));
        case 'DAY':                return round($full * 3.4 * 10) / 10;
        case 'DAY_DISCOUNT':       return round($disc * 3.4 * 10) / 10;
        case 'PERIOD_7':           return round($full * 6 * 100) / 100;
        case 'PERIOD_7_DISCOUNT':  return round($disc * 6 * 100) / 100;
        case 'PERIOD_30':          return round($full * 27 * 100) / 100;
        case 'PERIOD_30_DISCOUNT': return round($disc * 27 * 100) / 100;
        default:                   return 0.00;
    }
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $active_trip_id = $_SESSION['active_trip_id'] ?? null;

    if(!$active_trip_id && !empty($_POST['trip_id'])) {
        $active_trip_id = (int)$_POST['trip_id'];
    }

    $sell_mode_raw      = $_POST['sell_mode'] ?? 'station_text';
    $sell_mode          = (str_starts_with($sell_mode_raw, 'station')) ? 'station' : 'zone';
    $ticket_category_id = (int)($_POST['ticket_category_id'] ?? 0);
    $is_personalized    = isset($_POST['is_personalized']) ? 1 : 0;
    $public_code        = trim($_POST['public_code'] ?? '');
    $is_free_sale       = ($_SESSION['role'] === 'admin' && isset($_POST['is_free_sale'])) ? 1 : 0;
    $is_personal_trip   = ($_SESSION['work_mode'] ?? '') === 'personal';
    $qty                = max(1, min(10, (int)($_POST['qty'] ?? 1)));

    
    if($is_personal_trip && empty($_SESSION['cart'])) {
        $is_personalized = 1;
        $meStmt = mysqli_prepare($connection, "SELECT id, full_name, username FROM users WHERE id = ? LIMIT 1");
        mysqli_stmt_bind_param($meStmt, 'i', $_SESSION['user_id']);
        mysqli_stmt_execute($meStmt);
        $meUser = mysqli_fetch_assoc(mysqli_stmt_get_result($meStmt));
        $public_code = ''; 
    }

    $catStmt = mysqli_prepare($connection, "SELECT * FROM ticket_categories WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($catStmt, 'i', $ticket_category_id);
    mysqli_stmt_execute($catStmt);
    $category = mysqli_fetch_assoc(mysqli_stmt_get_result($catStmt));

    if($message === '' && !$category) {
        $message = 'Vali pileti liik.';
        $messageType = 'error';
    }

    $periodTicketCodes = [
    'DAY',
    'DAY_DISCOUNT',
    'PERIOD_7',
    'PERIOD_7_DISCOUNT',
    'PERIOD_30',
    'PERIOD_30_DISCOUNT'
];

if($category && in_array($category['code'], $periodTicketCodes, true)) {
    $active_trip_id = null;
}

    $user_id_locked       = null;
    $passengerName_locked = null;

    if(!empty($_SESSION['cart'])) {
        $first                = $_SESSION['cart'][0];
        $sell_mode            = $first['sell_mode'];
        $is_personalized      = $first['is_personalized'];
        $user_id_locked       = $first['user_id'];
        $passengerName_locked = $first['passenger_name'];
    }

    $from_zone_number = null;
    $to_zone_number   = null;
    $from_zone_id     = null;
    $to_zone_id       = null;
    $from_station_id  = null;
    $to_station_id    = null;
    $from_name        = null;
    $to_name          = null;

    if($message === '') {
        if($sell_mode === 'zone') {
            $from_zone_id_post = (int)($_POST['from_zone_id'] ?? 0);
            $to_zone_id_post   = (int)($_POST['to_zone_id'] ?? 0);

            if(!empty($_SESSION['cart'])) {
                $from_zone_id_post = $_SESSION['cart'][0]['from_zone_id'];
                $to_zone_id_post   = $_SESSION['cart'][0]['to_zone_id'];
            }

            $zStmt = mysqli_prepare($connection, "SELECT id, name, zone_number FROM zones WHERE id = ? LIMIT 1");

            mysqli_stmt_bind_param($zStmt, 'i', $from_zone_id_post);
            mysqli_stmt_execute($zStmt);
            $fromZone = mysqli_fetch_assoc(mysqli_stmt_get_result($zStmt));

            mysqli_stmt_bind_param($zStmt, 'i', $to_zone_id_post);
            mysqli_stmt_execute($zStmt);
            $toZone = mysqli_fetch_assoc(mysqli_stmt_get_result($zStmt));

            if(!$fromZone || !$toZone) {
                $message = 'Vali algtsoon ja lõpptsoon.';
                $messageType = 'error';
            } else {
                $from_zone_id     = $fromZone['id'];
                $to_zone_id       = $toZone['id'];
                $from_zone_number = $fromZone['zone_number'];
                $to_zone_number   = $toZone['zone_number'];
                $from_name        = 'Tsoon ' . $fromZone['zone_number'] . ' (' . $fromZone['name'] . ')';
                $to_name          = 'Tsoon ' . $toZone['zone_number'] . ' (' . $toZone['name'] . ')';
            }
        } else {
            $from_station_id_post = (int)($_POST['from_station_id'] ?? 0);
            $to_station_id_post   = (int)($_POST['to_station_id'] ?? 0);
            $from_text = trim($_POST['from_station_text'] ?? '');
            $to_text   = trim($_POST['to_station_text'] ?? '');

            if(!empty($_SESSION['cart'])) {
                $from_station_id_post = $_SESSION['cart'][0]['from_station_id'];
                $to_station_id_post   = $_SESSION['cart'][0]['to_station_id'];
                $from_text = '';
                $to_text   = '';
            }

            $sStmt = mysqli_prepare($connection, "
                SELECT s.id, s.name, s.zone_id, z.zone_number
                FROM stations s
                JOIN zones z ON z.id = s.zone_id
                WHERE s.id = ?
                LIMIT 1
            ");

            if($from_station_id_post > 0) {
                mysqli_stmt_bind_param($sStmt, 'i', $from_station_id_post);
                mysqli_stmt_execute($sStmt);
                $fromS = mysqli_fetch_assoc(mysqli_stmt_get_result($sStmt));
            } elseif($from_text !== '') {
                $ftStmt = mysqli_prepare($connection, "
                    SELECT s.id, s.name, s.zone_id, z.zone_number
                    FROM stations s
                    JOIN zones z ON z.id = s.zone_id
                    WHERE LOWER(s.name) LIKE LOWER(?)
                    LIMIT 1
                ");
                $like = '%' . $from_text . '%';
                mysqli_stmt_bind_param($ftStmt, 's', $like);
                mysqli_stmt_execute($ftStmt);
                $fromS = mysqli_fetch_assoc(mysqli_stmt_get_result($ftStmt));
            } else {
                $fromS = null;
            }

            if($to_station_id_post > 0) {
                mysqli_stmt_bind_param($sStmt, 'i', $to_station_id_post);
                mysqli_stmt_execute($sStmt);
                $toS = mysqli_fetch_assoc(mysqli_stmt_get_result($sStmt));
            } elseif($to_text !== '') {
                $ttStmt = mysqli_prepare($connection, "
                    SELECT s.id, s.name, s.zone_id, z.zone_number
                    FROM stations s
                    JOIN zones z ON z.id = s.zone_id
                    WHERE LOWER(s.name) LIKE LOWER(?)
                    LIMIT 1
                ");
                $like2 = '%' . $to_text . '%';
                mysqli_stmt_bind_param($ttStmt, 's', $like2);
                mysqli_stmt_execute($ttStmt);
                $toS = mysqli_fetch_assoc(mysqli_stmt_get_result($ttStmt));
            } else {
                $toS = null;
            }

            if(!$fromS || !$toS) {
                $message = 'Vali algpeatus ja lõpppeatus.';
                $messageType = 'error';
            } else {
                $from_station_id  = $fromS['id'];
                $to_station_id    = $toS['id'];
                $from_zone_id     = $fromS['zone_id'];
                $to_zone_id       = $toS['zone_id'];
                $from_zone_number = $fromS['zone_number'];
                $to_zone_number   = $toS['zone_number'];
                $from_name        = $fromS['name'];
                $to_name          = $toS['name'];
            }
        }
    }

    $user_id       = null;
    $passengerName = 'Isikustamata';

    if($message === '') {
        if(!empty($_SESSION['cart'])) {
            $user_id       = $user_id_locked;
            $passengerName = $passengerName_locked ?? 'Isikustamata';
        } elseif($is_personalized) {
            if($public_code === '') {
                $message = 'Isikustatud pileti jaoks sisesta kasutaja ID-kood.';
                $messageType = 'error';
            } else {
                $uStmt = mysqli_prepare($connection, "SELECT id, full_name, username, role FROM users WHERE public_code = ? LIMIT 1");
                mysqli_stmt_bind_param($uStmt, 's', $public_code);
                mysqli_stmt_execute($uStmt);
                $user = mysqli_fetch_assoc(mysqli_stmt_get_result($uStmt));

                if(!$user) {
                    $message = 'Kasutajat ei leitud.';
                    $messageType = 'error';
                } else {
                    $user_id       = (int)$user['id'];
                    $passengerName = $user['full_name'] ?: $user['username'];
                    $is_staff_passenger = in_array($user['role'], ['conductor', 'admin']);
                }
            }
        }
    }

    if($message === '') {
        $zoneDiff  = abs((int)$from_zone_number - (int)$to_zone_number);
        $price     = calc_price($category['code'], $zoneDiff, $is_free_sale, $is_personal_trip || ($is_staff_passenger ?? false));

        
        if($is_personal_trip && empty($user_id) && isset($meUser)) {
            $user_id       = (int)$meUser['id'];
            $passengerName = $meUser['full_name'] ?: $meUser['username'];
            $is_personalized = 1;
        }
        $trip_id   = $active_trip_id ? (int)$active_trip_id : null;
        $routeText = $from_name . ' → ' . $to_name;

        for($i = 0; $i < $qty; $i++) {
            $_SESSION['cart'][] = [
                'sell_mode'          => $sell_mode,
                'from_station_id'    => $from_station_id,
                'from_name'          => $from_name,
                'from_zone_id'       => $from_zone_id,
                'to_station_id'      => $to_station_id,
                'to_name'            => $to_name,
                'to_zone_id'         => $to_zone_id,
                'ticket_category_id' => $ticket_category_id,
                'category_name'      => $category['name'],
                'category_code'      => $category['code'],
                'validity_type'      => $category['validity_type'] ?? 'trip',
                'validity_days'      => (int)$category['validity_days'],
                'ticket_format'      => 'qr',
                'is_personalized'    => $is_personalized,
                'user_id'            => $user_id,
                'passenger_name'     => $passengerName,
                'is_free_sale'       => $is_free_sale,
                'trip_id'            => $trip_id,
                'route_text'         => $routeText,
                'price'              => $price,
            ];
        }

        $message = $qty . ' pilet' . ($qty > 1 ? 'it' : '') . ' korvi lisatud.';
        $messageType = 'success';
    }
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove') {
    $idx = (int)($_POST['cart_index'] ?? -1);
    if(isset($_SESSION['cart'][$idx])) {
        array_splice($_SESSION['cart'], $idx, 1);
    }
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear') {
    $_SESSION['cart'] = [];
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_all') {
    if(empty($_SESSION['cart'])) {
        $message = 'Ostukorv on tühi.';
        $messageType = 'error';
    } else {
        $soldByUserId = (int)$_SESSION['user_id'];
        $travelDate   = date('Y-m-d');
        $validFrom    = date('Y-m-d H:i:s');
        $errors       = 0;
        $cartCount    = count($_SESSION['cart']);

        if($cartCount > 1) {
            do {
                $groupCode = '$$GRP' . rand(10,99) . rand(1000,9999);
                $gStmt = mysqli_prepare($connection, "SELECT id FROM tickets WHERE group_code = ? LIMIT 1");
                mysqli_stmt_bind_param($gStmt, 's', $groupCode);
                mysqli_stmt_execute($gStmt);
                $gExists = mysqli_fetch_assoc(mysqli_stmt_get_result($gStmt));
            } while($gExists);
        } else {
            $groupCode = null;
        }

        $groupQrUrl = null;

        foreach($_SESSION['cart'] as $item) {
            $ticketCode = generate_ticket_code($connection);

            if($groupCode !== null && $groupQrUrl === null) {
                $groupQrUrl = save_ticket_code_image($ticketCode, $item['ticket_format']);
            }

            $codeImageUrl = ($groupCode !== null)
                ? $groupQrUrl
                : save_ticket_code_image($ticketCode, $item['ticket_format']);

            if($item['validity_type'] === 'day') {
                $validUntil = date('Y-m-d H:i:s', strtotime('+24 hours'));
            } elseif($item['validity_type'] === 'period') {
                $validUntil = date('Y-m-d H:i:s', strtotime('+' . $item['validity_days'] . ' days'));
            } else {
                $validUntil = date('Y-m-d H:i:s', strtotime('+24 hours'));
            }

            $ins = mysqli_prepare($connection, "
                INSERT INTO tickets (
                    ticket_code, trip_id, user_id, passenger_name,
                    from_station, to_station, travel_date, price, status,
                    ticket_format, is_personalized, ticket_category_id,
                    from_station_id, to_station_id, from_zone_id, to_zone_id,
                    route_text, valid_from, valid_until, code_image_url,
                    sold_by_user_id, is_free_sale, group_code
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Kehtib', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $tripId = $item['trip_id'];
            $userId = $item['user_id'];
            $price  = $item['price'];
            $isPers = $item['is_personalized'];
            $catId  = $item['ticket_category_id'];
            $isFree = $item['is_free_sale'];

            mysqli_stmt_bind_param($ins, 'siissssdsiiiiiisssiiis',
                $ticketCode,
                $tripId,
                $userId,
                $item['passenger_name'],
                $item['from_name'],
                $item['to_name'],
                $travelDate,
                $price,
                $item['ticket_format'],
                $isPers,
                $catId,
                $item['from_station_id'],
                $item['to_station_id'],
                $item['from_zone_id'],
                $item['to_zone_id'],
                $item['route_text'],
                $validFrom,
                $validUntil,
                $codeImageUrl,
                $soldByUserId,
                $isFree,
                $groupCode
            );

            if(mysqli_stmt_execute($ins)) {
                $createdTickets[] = [
                    'id'         => mysqli_insert_id($connection),
                    'code'       => $ticketCode,
                    'route'      => $item['route_text'],
                    'category'   => $item['category_name'],
                    'passenger'  => $item['passenger_name'],
                    'price'      => $price,
                    'image_url'  => $codeImageUrl,
                    'group_code' => $groupCode,
                ];
            } else {
                $errors++;
            }
        }

        if($errors === 0) {
            $_SESSION['cart'] = [];
            $message = count($createdTickets) . ' piletit loodud.';
            $messageType = 'success';
        } else {
            $message = $errors . ' pileti loomine ebaõnnestus: ' . mysqli_error($connection);
            $messageType = 'error';
        }
    }
}

$trips = mysqli_query($connection, "
    SELECT id, trip_number, route_name, scheduled_start
    FROM trips
    WHERE status IN ('planned','active')
    ORDER BY scheduled_start ASC
");

$tripStopsByTrip = [];

$tripStopsRes = mysqli_query($connection, "
    SELECT
        t.id AS trip_id,
        s.id,
        s.name,
        s.zone_id,
        z.name AS zone_name,
        z.zone_number,
        ts.stop_order
    FROM trips t
    JOIN trip_stops ts ON ts.trip_id = t.id
    JOIN stations s ON s.id = ts.station_id
    JOIN zones z ON z.id = s.zone_id
    WHERE t.status IN ('planned','active')
    ORDER BY t.id ASC, ts.stop_order ASC
");

while($row = mysqli_fetch_assoc($tripStopsRes)) {
    $tripStopsByTrip[(int)$row['trip_id']][] = [
        'id' => (int)$row['id'],
        'name' => $row['name'],
        'zone_id' => (int)$row['zone_id'],
        'zone_name' => $row['zone_name'],
        'zone_number' => (int)$row['zone_number'],
        'stop_order' => (int)$row['stop_order'],
    ];
}

$stationsRaw = [];
$activeTripId = (int)($_SESSION['active_trip_id'] ?? 0);

if($activeTripId > 0) {
    $sStmt = mysqli_prepare($connection, "
        SELECT 
            s.id,
            s.name,
            s.zone_id,
            z.name AS zone_name,
            z.zone_number,
            ts.stop_order,
            ts.departure_time
        FROM trip_stops ts
        JOIN stations s ON s.id = ts.station_id
        JOIN zones z ON z.id = s.zone_id
        WHERE ts.trip_id = ?
        ORDER BY ts.stop_order ASC
    ");
    mysqli_stmt_bind_param($sStmt, 'i', $activeTripId);
    mysqli_stmt_execute($sStmt);
    $sRes = mysqli_stmt_get_result($sStmt);
} else {
    $sRes = mysqli_query($connection, "
        SELECT 
            s.id,
            s.name,
            s.zone_id,
            z.name AS zone_name,
            z.zone_number,
            s.stop_order,
            NULL AS departure_time
        FROM stations s
        JOIN zones z ON z.id = s.zone_id
        ORDER BY s.stop_order ASC, s.id ASC
    ");
}

while($r = mysqli_fetch_assoc($sRes)) {
    $stationsRaw[] = $r;
}

$zonesRaw = [];

if($activeTripId > 0) {
    $zStmt = mysqli_prepare($connection, "
        SELECT DISTINCT
            z.id,
            z.name,
            z.zone_number
        FROM trip_stops ts
        JOIN stations s ON s.id = ts.station_id
        JOIN zones z ON z.id = s.zone_id
        WHERE ts.trip_id = ?
        ORDER BY z.zone_number ASC
    ");
    mysqli_stmt_bind_param($zStmt, 'i', $activeTripId);
    mysqli_stmt_execute($zStmt);
    $zRes = mysqli_stmt_get_result($zStmt);
} else {
    $zRes = mysqli_query($connection, "
        SELECT id, name, zone_number
        FROM zones
        ORDER BY zone_number ASC
    ");
}

while($r = mysqli_fetch_assoc($zRes)) {
    $zonesRaw[] = $r;
}

$categories = mysqli_query($connection, "SELECT * FROM ticket_categories ORDER BY id ASC");

$cartHasItems       = count($_SESSION['cart']) > 0;
$cartSellMode       = $cartHasItems ? $_SESSION['cart'][0]['sell_mode'] : 'station';
$cartIsPersonalized = $cartHasItems ? $_SESSION['cart'][0]['is_personalized'] : false;

$prefillCode = trim($_GET['prefill_code'] ?? '');
$prefillUser = null;

if($prefillCode !== '' && !$cartHasItems) {
    $pfStmt = mysqli_prepare($connection, "SELECT id, full_name, username, public_code FROM users WHERE public_code = ? LIMIT 1");
    mysqli_stmt_bind_param($pfStmt, 's', $prefillCode);
    mysqli_stmt_execute($pfStmt);
    $prefillUser = mysqli_fetch_assoc(mysqli_stmt_get_result($pfStmt));
}

require __DIR__ . '/includes/header.php';
?>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

<a class="btn secondary" href="dashboard.php" style="margin-bottom:1.5rem;display:block;">← Tagasi</a>
<h2>Müü pilet</h2>

<?php if(($_SESSION['work_mode'] ?? '') === 'personal'): ?>
  <div style="padding:0.75rem 1rem; background:#f0fdf4; border:1px solid #86efac; border-radius:8px; margin-bottom:1rem; color:#166534; font-size:14px;">
    ✔ <strong>Isiklik sõit</strong> – pilet isikustatakse automaatselt sinu nimele ja hind on <strong>0,00 €</strong>.
  </div>
<?php endif; ?>

<?php if($message): ?>
  <div class="message <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<?php if(!empty($createdTickets)): ?>
  <?php
    $printId = $createdTickets[0]['id'];
    $total = array_sum(array_column($createdTickets, 'price'));
  ?>
  <div class="card">
    <h3>✓ Loodud piletid</h3>

    <?php foreach($createdTickets as $ct): ?>
      <div style="border-top:1px solid #eee;padding:10px 0;">
        <strong><?= htmlspecialchars($ct['code']) ?></strong> —
        <?= htmlspecialchars($ct['category']) ?> —
        <?= htmlspecialchars($ct['route']) ?><br>
        <small><?= htmlspecialchars($ct['passenger']) ?></small> —
        <strong><?= number_format($ct['price'], 2) ?> €</strong>
      </div>
    <?php endforeach; ?>

    <p style="margin-top:12px;font-size:20px;font-weight:bold;">
      Kokku: <?= number_format($total, 2) ?> €
    </p>

    <div style="margin-top:10px;display:flex;gap:8px;">
      <a class="btn secondary" href="print_ticket.php?id=<?= $printId ?>&format=a4" target="_blank">🖨️ A4</a>
      <a class="btn secondary" href="print_ticket.php?id=<?= $printId ?>&format=receipt" target="_blank">🖨️ Tšekk</a>
    </div>
  </div>
<?php endif; ?>

<?php if($cartHasItems): ?>
  <div class="card" style="margin-bottom:1.5rem;">
    <h3>🛒 Ostukorv</h3>
    <?php $cartTotal = 0; foreach($_SESSION['cart'] as $idx => $item): $cartTotal += $item['price']; ?>
      <div style="display:flex;justify-content:space-between;align-items:center;border-top:1px solid #eee;padding:8px 0;gap:8px;">
        <div style="flex:1;">
          <strong><?= htmlspecialchars($item['category_name']) ?></strong><br>
          <small><?= htmlspecialchars($item['route_text']) ?> — <?= htmlspecialchars($item['passenger_name']) ?></small>
        </div>
        <strong><?= number_format($item['price'], 2) ?> €</strong>
        <form method="POST" style="margin:0;">
          <input type="hidden" name="action" value="remove">
          <input type="hidden" name="cart_index" value="<?= $idx ?>">
          <button type="submit" style="background:none;border:none;color:#c0392b;cursor:pointer;font-size:20px;line-height:1;">✕</button>
        </form>
      </div>
    <?php endforeach; ?>

    <div style="margin-top:14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
      <span style="font-size:22px;font-weight:bold;">Kokku: <?= number_format($cartTotal, 2) ?> €</span>
      <div style="display:flex;gap:8px;">
        <form method="POST" style="margin:0;">
          <input type="hidden" name="action" value="clear">
          <button type="submit" class="btn secondary">Tühjenda</button>
        </form>
        <form method="POST" style="margin:0;">
          <input type="hidden" name="action" value="create_all">
          <button type="submit" class="btn">✓ Loo <?= count($_SESSION['cart']) ?> pilet<?= count($_SESSION['cart']) !== 1 ? 'it' : '' ?></button>
        </form>
      </div>
    </div>
  </div>
<?php endif; ?>

<form method="POST">
  <input type="hidden" name="action" value="add">

  <?php if(empty($_SESSION['active_trip_id'])): ?>
    <div id="trip_select_block">
      <label>Reis</label>
      <select name="trip_id" id="trip_id_select">
        <option value="">— Vali reis —</option>
        <?php while($tr = mysqli_fetch_assoc($trips)): ?>
          <option value="<?= (int)$tr['id'] ?>">
            <?= htmlspecialchars(($tr['trip_number'] ? '#'.$tr['trip_number'].' ' : '') . $tr['route_name']) ?>
            — <?= date('d.m.Y H:i', strtotime($tr['scheduled_start'])) ?>
          </option>
        <?php endwhile; ?>
      </select>
    </div>
  <?php else: ?>
    <?php
  $activeTrip = null;
  $atStmt = mysqli_prepare($connection, "SELECT trip_number, route_name FROM trips WHERE id = ? LIMIT 1");
  mysqli_stmt_bind_param($atStmt, 'i', $_SESSION['active_trip_id']);
  mysqli_stmt_execute($atStmt);
  $activeTrip = mysqli_fetch_assoc(mysqli_stmt_get_result($atStmt));
?>
<p style="color:#888;font-size:14px;margin-bottom:4px;">
  Aktiivne reis: <strong>
    <?= $activeTrip ? htmlspecialchars(($activeTrip['trip_number'] ? '#'.$activeTrip['trip_number'].' ' : '') . $activeTrip['route_name']) : '#'.$_SESSION['active_trip_id'] ?>
  </strong>
</p>
<a href="select_work_mode.php" style="font-size:13px;color:#1565c0;display:inline-block;margin-bottom:12px;">↩ Vaheta reisi</a>
  <?php endif; ?>

  <?php if(!$cartHasItems): ?>
    <label>Müügi viis</label>
    <div class="sell-mode-grid">
      <label class="sell-mode-card">
        <input type="radio" name="sell_mode" value="station_text" id="mode_station_search" checked>
        <span class="sell-mode-icon">🔍</span>
        <span>
          <strong>Otsi peatust</strong>
          <small>Nime järgi</small>
        </span>
      </label>

      <label class="sell-mode-card">
        <input type="radio" name="sell_mode" value="station_dropdown" id="mode_station_dropdown">
        <span class="sell-mode-icon">📋</span>
        <span>
          <strong>Vali peatus</strong>
          <small>Rippmenüüst</small>
        </span>
      </label>

      <label class="sell-mode-card">
        <input type="radio" name="sell_mode" value="zone" id="mode_zone">
        <span class="sell-mode-icon">🗺️</span>
        <span>
          <strong>Tsoonid</strong>
          <small>Tsoonide järgi</small>
        </span>
      </label>
    </div>
  <?php else: ?>
    <input type="hidden" name="sell_mode" value="<?= htmlspecialchars($cartSellMode) ?>">
    <p style="color:#888;font-size:13px;margin-bottom:8px;">Müügi viis: <strong>
      <?= $cartSellMode === 'zone' ? 'Tsoonide järgi' : 'Peatuste järgi' ?>
    </strong></p>
  <?php endif; ?>

  <div id="block_station_search" style="<?= ($cartHasItems && $cartSellMode === 'zone') ? 'display:none;' : '' ?>">
    <?php if(!$cartHasItems): ?>
      <label>Algpeatus</label>
      <input type="text" id="from_station_text_input" name="from_station_text"
             placeholder="Kirjuta peatuse nimi..." autocomplete="off" autocorrect="off"
             style="margin-bottom:4px;">
      <div id="from_suggestions" style="border:1px solid #ddd;background:#fff;display:none;max-height:180px;overflow-y:auto;margin-bottom:1rem;"></div>
      <input type="hidden" name="from_station_id" id="from_station_id_search" value="0">

      <label>Lõpppeatus</label>
      <input type="text" id="to_station_text_input" name="to_station_text"
             placeholder="Kirjuta peatuse nimi..." autocomplete="off" autocorrect="off"
             style="margin-bottom:4px;">
      <div id="to_suggestions" style="border:1px solid #ddd;background:#fff;display:none;max-height:180px;overflow-y:auto;margin-bottom:1rem;"></div>
      <input type="hidden" name="to_station_id" id="to_station_id_search" value="0">
    <?php else: ?>
      <p style="color:#888;font-size:13px;">
        <?= htmlspecialchars($_SESSION['cart'][0]['from_name']) ?> →
        <?= htmlspecialchars($_SESSION['cart'][0]['to_name']) ?>
      </p>
      <input type="hidden" name="from_station_id" value="<?= (int)$_SESSION['cart'][0]['from_station_id'] ?>">
      <input type="hidden" name="to_station_id" value="<?= (int)$_SESSION['cart'][0]['to_station_id'] ?>">
    <?php endif; ?>
  </div>

  <div id="block_station_dropdown" style="display:none;">
    <?php if(!$cartHasItems): ?>
      <label>Algpeatus</label>
      <select name="from_station_id" id="from_station_select">
        <option value="0">— Vali peatus —</option>
        <?php foreach($stationsRaw as $s): ?>
          <option value="<?= (int)$s['id'] ?>">
            <?= htmlspecialchars($s['name']) ?> (tsoon <?= (int)$s['zone_number'] ?>)
          </option>
        <?php endforeach; ?>
      </select>

      <label>Lõpppeatus</label>
      <select name="to_station_id" id="to_station_select">
        <option value="0">— Vali peatus —</option>
        <?php foreach($stationsRaw as $s): ?>
          <option value="<?= (int)$s['id'] ?>">
            <?= htmlspecialchars($s['name']) ?> (tsoon <?= (int)$s['zone_number'] ?>)
          </option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
  </div>

  <div id="block_zone" style="display:none;">
    <?php if(!$cartHasItems): ?>
      <label>Algtsoon</label>
      <div id="from_zone_buttons" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:1rem;">
        <?php foreach($zonesRaw as $z): ?>
          <button type="button" class="zone-btn-from"
                  data-id="<?= (int)$z['id'] ?>"
                  data-zone="<?= (int)$z['zone_number'] ?>"
                  onclick="selectZone('from', <?= (int)$z['id'] ?>, <?= (int)$z['zone_number'] ?>, '<?= htmlspecialchars($z['name'], ENT_QUOTES) ?>')"
                  style="padding:10px 18px;border:2px solid #ccc;background:#f9f9f9;cursor:pointer;font-size:15px;">
            Tsoon <?= (int)$z['zone_number'] ?><br>
            <small style="color:#888;"><?= htmlspecialchars($z['name']) ?></small>
          </button>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="from_zone_id" id="from_zone_id_hidden" value="0">

      <label>Lõpptsoon</label>
      <div id="to_zone_buttons" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:1rem;">
        <?php foreach($zonesRaw as $z): ?>
          <button type="button" class="zone-btn-to"
                  data-id="<?= (int)$z['id'] ?>"
                  data-zone="<?= (int)$z['zone_number'] ?>"
                  onclick="selectZone('to', <?= (int)$z['id'] ?>, <?= (int)$z['zone_number'] ?>, '<?= htmlspecialchars($z['name'], ENT_QUOTES) ?>')"
                  style="padding:10px 18px;border:2px solid #ccc;background:#f9f9f9;cursor:pointer;font-size:15px;">
            Tsoon <?= (int)$z['zone_number'] ?><br>
            <small style="color:#888;"><?= htmlspecialchars($z['name']) ?></small>
          </button>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="to_zone_id" id="to_zone_id_hidden" value="0">
      <div id="zone_selection_label" style="font-size:14px;color:#1565c0;margin-bottom:1rem;min-height:1.4em;"></div>
    <?php else: ?>
      <p style="color:#888;font-size:13px;">
        <?= htmlspecialchars($_SESSION['cart'][0]['from_name']) ?> →
        <?= htmlspecialchars($_SESSION['cart'][0]['to_name']) ?>
      </p>
      <input type="hidden" name="from_zone_id" value="<?= (int)$_SESSION['cart'][0]['from_zone_id'] ?>">
      <input type="hidden" name="to_zone_id" value="<?= (int)$_SESSION['cart'][0]['to_zone_id'] ?>">
    <?php endif; ?>
  </div>

  <label>Pileti liik</label>
  <select name="ticket_category_id" id="ticket_category_id" required>
    <?php while($cat = mysqli_fetch_assoc($categories)): ?>
      <option value="<?= (int)$cat['id'] ?>" data-code="<?= htmlspecialchars($cat['code']) ?>">
        <?= htmlspecialchars($cat['name']) ?>
      </option>
    <?php endwhile; ?>
  </select>

  <label>Kogus</label>
  <input type="number" name="qty" id="qty" value="1" min="1" max="10" style="width:80px;">

  <label class="check-row" <?= $cartHasItems ? 'style="opacity:0.5;pointer-events:none;"' : '' ?>>
    <input type="checkbox" name="is_personalized" id="is_personalized" value="1"
           <?= ($cartHasItems && $cartIsPersonalized) ? 'checked disabled'
             : ($cartHasItems ? 'disabled'
             : (($prefillUser || isset($_POST['is_personalized'])) ? 'checked' : '')) ?>>
    Isikusta pilet
  </label>

  <?php $showPersBlock = ($cartHasItems && $cartIsPersonalized) || (!$cartHasItems && ($prefillUser || isset($_POST['is_personalized']))); ?>
  <div id="personalized_block" style="<?= ($cartHasItems && $cartIsPersonalized) ? 'display:block;opacity:0.5;pointer-events:none;' : ($showPersBlock ? 'display:block;' : 'display:none;') ?>">
    <?php if($cartHasItems && $cartIsPersonalized): ?>
      <p style="font-size:14px;color:#2e7d32;">
  ✓ Reisija:
  <strong>
    <?= htmlspecialchars($_SESSION['cart'][0]['passenger_name']) ?>
    <?php
      $staffRoles = ['conductor', 'admin'];

      $isStaffPassenger = false;

      if(!empty($_SESSION['cart'][0]['user_id'])) {
          $spStmt = mysqli_prepare($connection, "SELECT role FROM users WHERE id = ? LIMIT 1");
          mysqli_stmt_bind_param($spStmt, 'i', $_SESSION['cart'][0]['user_id']);
          mysqli_stmt_execute($spStmt);
          $spUser = mysqli_fetch_assoc(mysqli_stmt_get_result($spStmt));

          if($spUser && in_array($spUser['role'], $staffRoles, true)) {
              $isStaffPassenger = true;
          }
      }

      if($isStaffPassenger) {
          echo ' - PERSONAL';
      }
    ?>
  </strong>
</p>
    <?php else: ?>
      <label>Kasutaja ID-kood</label>
      <input type="text" name="public_code" id="public_code"
             value="<?= htmlspecialchars($prefillUser ? $prefillUser['public_code'] : ($_POST['public_code'] ?? '')) ?>"
             placeholder="Sisesta või skänneeri kood"
             autocomplete="off" autocorrect="off" spellcheck="false">
      <div id="public_code_status" style="font-size:13px;margin-top:-0.8rem;margin-bottom:1rem;min-height:1.2em;">
        <?php if($prefillUser): ?>
          <span style="color:#2e7d32;">✓ <?= htmlspecialchars($prefillUser['full_name'] ?: $prefillUser['username']) ?></span>
        <?php endif; ?>
      </div>
      <button type="button" class="btn secondary" id="btn_scan_user" style="margin-bottom:1rem;">
        📷 Tuvasta kasutaja kaameraga
      </button>
    <?php endif; ?>
  </div>

  <?php if($_SESSION['role'] === 'admin'): ?>
    <label class="check-row">
      <input type="checkbox" name="is_free_sale" value="1"> Müün tasuta
    </label>
  <?php endif; ?>

  <div id="price_preview" style="margin:12px 0;padding:10px;background:#f5f5f5;">
    Hind kokku: <strong id="price_preview_val" style="font-size:18px;">—</strong>
  </div>

  <button class="btn" type="submit">+ Lisa korvi</button>
</form>

<div class="card" style="margin-top:1.5rem;">
  <strong>Hinnatabel</strong>
  <p id="price_zone_label" style="color:#888;font-size:13px;margin:4px 0 10px;">Vali marsruut, et näha hindu</p>
  <table id="price_table" style="width:100%;border-collapse:collapse;display:none;">
    <thead>
      <tr style="border-bottom:2px solid #ccc;">
        <th style="text-align:left;padding:6px;">Pilet</th>
        <th style="text-align:right;padding:6px;">Hind</th>
      </tr>
    </thead>
    <tbody id="price_table_body"></tbody>
  </table>
</div>

<div id="scan_user_container" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.92);z-index:9999;flex-direction:column;align-items:center;justify-content:center;">
  <p style="color:#fff;font-size:16px;margin-bottom:1rem;">Suuna kaamera kasutaja QR-koodile</p>
  <div id="scan_user_reader" style="width:min(90vw,400px);"></div>
  <button type="button" class="btn secondary" id="btn_scan_user_stop" style="margin-top:1.5rem;font-size:18px;padding:12px 32px;">✕ Sulge kaamera</button>
</div>

<style>
.sell-mode-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 1.4rem;
}
.sell-mode-card {
  position: relative;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 16px;
  border: 1px solid var(--border);
  border-radius: 12px;
  background: 
  cursor: pointer;
  transition: 0.15s ease;
  font-weight: normal;
  min-height: 70px;
}
.sell-mode-card:hover {
  border-color: var(--accent);
  background: 
}
.sell-mode-card input {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}
.sell-mode-icon {
  width: 38px;
  height: 38px;
  border-radius: 10px;
  background: 
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
}
.sell-mode-card strong {
  display: block;
  font-size: 14px;
  color: var(--text);
}
.sell-mode-card small {
  display: block;
  margin-top: 2px;
  font-size: 12px;
  color: var(--muted);
}
.sell-mode-card:has(input:checked) {
  border-color: var(--accent);
  background: 
  box-shadow: 0 0 0 2px rgba(21, 101, 192, 0.08);
}
.sell-mode-card:has(input:checked) .sell-mode-icon {
  background: var(--accent);
  color: 
}
@media (max-width: 700px) {
  .sell-mode-grid {
    grid-template-columns: 1fr;
  }
}
</style>

<script>
const stationsData = <?= json_encode($stationsRaw) ?>;
const zonesData    = <?= json_encode($zonesRaw) ?>;
const tripStopsByTrip = <?= json_encode($tripStopsByTrip) ?>;

const modeSearch   = document.getElementById('mode_station_search');
const modeDropdown = document.getElementById('mode_station_dropdown');
const modeZone     = document.getElementById('mode_zone');
const blockSearch   = document.getElementById('block_station_search');
const blockDropdown = document.getElementById('block_station_dropdown');
const blockZone     = document.getElementById('block_zone');
const tripSelect = document.getElementById('trip_id_select');
const sellModeGrid = document.querySelector('.sell-mode-grid');

function hardTripUiGuard() {
  const tripChosen = !tripSelect || tripSelect.value !== '';

  if(!tripChosen) {
    if(sellModeGrid) sellModeGrid.style.display = 'none';

    if(blockSearch) blockSearch.style.display = 'none';
    if(blockDropdown) blockDropdown.style.display = 'none';
    if(blockZone) blockZone.style.display = 'block';

    if(modeZone) modeZone.checked = true;
    return;
  }

  if(sellModeGrid) sellModeGrid.style.display = '';
}

function switchMode() {
  const isDropdown = modeDropdown && modeDropdown.checked;
  const isZone     = modeZone && modeZone.checked;
  const isSearch   = !isDropdown && !isZone;

  if(blockSearch)   blockSearch.style.display   = isSearch   ? 'block' : 'none';
  if(blockDropdown) blockDropdown.style.display  = isDropdown ? 'block' : 'none';
  if(blockZone)     blockZone.style.display      = isZone     ? 'block' : 'none';

  const fSearch = document.getElementById('from_station_id_search');
  const tSearch = document.getElementById('to_station_id_search');
  if(fSearch && !isSearch) fSearch.value = 0;
  if(tSearch && !isSearch) tSearch.value = 0;

  updateUI();
}

if(modeSearch)   modeSearch.addEventListener('change', switchMode);
if(modeDropdown) modeDropdown.addEventListener('change', switchMode);
if(modeZone)     modeZone.addEventListener('change', switchMode);

const fromSelect = document.getElementById('from_station_select');
const toSelect   = document.getElementById('to_station_select');
if(fromSelect) fromSelect.addEventListener('change', updateUI);
if(toSelect)   toSelect.addEventListener('change', updateUI);

let selectedFromId = 0, selectedToId = 0;

function buildSuggestions(query, containerId, hiddenId, side) {
  const box = document.getElementById(containerId);
  if(!query || query.length < 1) {
    box.style.display = 'none';
    return;
  }

  const q = query.toLowerCase();
  const matches = stationsData.filter(s => s.name.toLowerCase().includes(q));

  if(!matches.length) {
    box.innerHTML = '<div style="padding:8px 12px;color:#999;">Peatust ei leitud</div>';
    box.style.display = 'block';
    return;
  }

  box.innerHTML = matches.map(s =>
    `<div onclick="pickStation('${side}', ${s.id}, ${s.zone_number}, '${String(s.name).replace(/'/g,"\\'")}', '${containerId}', '${hiddenId}')"
          style="padding:10px 14px;cursor:pointer;border-bottom:1px solid #f0f0f0;font-size:15px;"
          onmouseover="this.style.background='#f0f4ff'" onmouseout="this.style.background=''">
      ${s.name} <small style="color:#999;">(Tsoon ${s.zone_number})</small>
    </div>`
  ).join('');

  box.style.display = 'block';
}

function pickStation(side, id, zone, name, containerId, hiddenId) {
  document.getElementById(containerId).style.display = 'none';
  document.getElementById(hiddenId).value = id;
  const inputId = side === 'from' ? 'from_station_text_input' : 'to_station_text_input';
  document.getElementById(inputId).value = name;

  if(side === 'from') selectedFromId = id;
  else selectedToId = id;

  updateUI();
}

const fromInput = document.getElementById('from_station_text_input');
const toInput   = document.getElementById('to_station_text_input');

if(fromInput) {
  fromInput.addEventListener('input', function() {
    selectedFromId = 0;
    const h = document.getElementById('from_station_id_search');
    if(h) h.value = 0;
    buildSuggestions(this.value, 'from_suggestions', 'from_station_id_search', 'from');
    updateUI();
  });
  fromInput.addEventListener('blur', function() {
    setTimeout(() => {
      const b = document.getElementById('from_suggestions');
      if(b) b.style.display = 'none';
    }, 200);
  });
}

if(toInput) {
  toInput.addEventListener('input', function() {
    selectedToId = 0;
    const h = document.getElementById('to_station_id_search');
    if(h) h.value = 0;
    buildSuggestions(this.value, 'to_suggestions', 'to_station_id_search', 'to');
    updateUI();
  });
  toInput.addEventListener('blur', function() {
    setTimeout(() => {
      const b = document.getElementById('to_suggestions');
      if(b) b.style.display = 'none';
    }, 200);
  });
}

let selectedFromZoneId = 0, selectedFromZoneNum = 0;
let selectedToZoneId   = 0, selectedToZoneNum   = 0;

function selectZone(side, id, zoneNum, name) {
  if(side === 'from') {
    selectedFromZoneId  = id;
    selectedFromZoneNum = zoneNum;
    document.getElementById('from_zone_id_hidden').value = id;

    document.querySelectorAll('.zone-btn-from').forEach(b => {
      b.style.borderColor = b.dataset.id == id ? '#1565c0' : '#ccc';
      b.style.background  = b.dataset.id == id ? '#e3f0ff' : '#f9f9f9';
    });
  } else {
    selectedToZoneId  = id;
    selectedToZoneNum = zoneNum;
    document.getElementById('to_zone_id_hidden').value = id;

    document.querySelectorAll('.zone-btn-to').forEach(b => {
      b.style.borderColor = b.dataset.id == id ? '#1565c0' : '#ccc';
      b.style.background  = b.dataset.id == id ? '#e3f0ff' : '#f9f9f9';
    });
  }

  const lbl = document.getElementById('zone_selection_label');
  if(lbl && selectedFromZoneId && selectedToZoneId) {
    const diff = Math.abs(selectedFromZoneNum - selectedToZoneNum);
    lbl.textContent = 'Tsoon ' + selectedFromZoneNum + ' → Tsoon ' + selectedToZoneNum + ' (vahe: ' + diff + ')';
  } else if(lbl) {
    lbl.textContent = '';
  }

  updateUI();
}

function r2(v)   { return Math.round(v * 100) / 100; }
function r10c(v) { return Math.round(v * 10) / 10; }
function fmt(v)  { return v.toFixed(2).replace('.', ',') + ' €'; }

function calcOne(code, zoneDiff) {
  const full = 1.40 + zoneDiff * 0.40;
  const disc = 1.20 + zoneDiff * 0.20;

  switch(code) {
    case 'FULL':               return r2(full);
    case 'DISCOUNT':           return r2(disc);
    case 'SPECIAL_FREE': case 'PET_FREE': case 'KID_FREE': return 0;
    case 'BIKE':               return Math.max(1, Math.min(4, r10c(full * 0.5)));
    case 'DAY':                return r10c(full * 3.4);
    case 'DAY_DISCOUNT':       return r10c(disc * 3.4);
    case 'PERIOD_7':           return r2(full * 6);
    case 'PERIOD_7_DISCOUNT':  return r2(disc * 6);
    case 'PERIOD_30':          return r2(full * 27);
    case 'PERIOD_30_DISCOUNT': return r2(disc * 27);
    default:                   return 0;
  }
}

function getZoneDiff() {
  const isZone     = modeZone && modeZone.checked;
  const isDropdown = modeDropdown && modeDropdown.checked;

  if(isZone) {
    if(!selectedFromZoneId || !selectedToZoneId) return null;
    return Math.abs(selectedFromZoneNum - selectedToZoneNum);
  }

  if(isDropdown) {
    const fId = parseInt(fromSelect?.value || 0);
    const tId = parseInt(toSelect?.value || 0);
    if(!fId || !tId) return null;

    const fS = stationsData.find(s => s.id == fId);
    const tS = stationsData.find(s => s.id == tId);
    if(!fS || !tS) return null;

    return Math.abs(parseInt(fS.zone_number) - parseInt(tS.zone_number));
  }

  const fHid = document.getElementById('from_station_id_search');
  const tHid = document.getElementById('to_station_id_search');
  const fId  = fHid ? parseInt(fHid.value) : 0;
  const tId  = tHid ? parseInt(tHid.value) : 0;

  if(!fId || !tId) return null;

  const fS = stationsData.find(s => s.id == fId);
  const tS = stationsData.find(s => s.id == tId);
  if(!fS || !tS) return null;

  return Math.abs(parseInt(fS.zone_number) - parseInt(tS.zone_number));
}

const PERIOD_TICKET_CODES = [
  'DAY',
  'DAY_DISCOUNT',
  'PERIOD_7',
  'PERIOD_7_DISCOUNT',
  'PERIOD_30',
  'PERIOD_30_DISCOUNT'
];

function isPeriodTicket(code) {
  return PERIOD_TICKET_CODES.includes(code);
}

function isSingleTicket(code) {
  return code && !isPeriodTicket(code);
}

function resetRouteInputs(clearLists = false) {
  const fromSel   = document.getElementById('from_station_select');
  const toSel     = document.getElementById('to_station_select');
  const fromInput = document.getElementById('from_station_text_input');
  const toInput   = document.getElementById('to_station_text_input');
  const fromHid   = document.getElementById('from_station_id_search');
  const toHid     = document.getElementById('to_station_id_search');

  if(clearLists) {
    stationsData.length = 0;

    if(fromSel) fromSel.innerHTML = '<option value="0">— Vali peatus —</option>';
    if(toSel)   toSel.innerHTML   = '<option value="0">— Vali peatus —</option>';

    const fromBox = document.getElementById('from_zone_buttons');
    const toBox   = document.getElementById('to_zone_buttons');

    if(fromBox) fromBox.innerHTML = '';
    if(toBox)   toBox.innerHTML   = '';
  }

  if(fromSel) fromSel.value = '0';
  if(toSel)   toSel.value   = '0';

  if(fromInput) fromInput.value = '';
  if(toInput)   toInput.value   = '';

  if(fromHid) fromHid.value = '0';
  if(toHid)   toHid.value   = '0';

  selectedFromId = 0;
  selectedToId = 0;

  selectedFromZoneId = 0;
  selectedFromZoneNum = 0;
  selectedToZoneId = 0;
  selectedToZoneNum = 0;

  const fromZone = document.getElementById('from_zone_id_hidden');
  const toZone   = document.getElementById('to_zone_id_hidden');

  if(fromZone) fromZone.value = '0';
  if(toZone)   toZone.value   = '0';

  const lbl = document.getElementById('zone_selection_label');
  if(lbl) lbl.textContent = '';
}

function renderTripStations(stops) {
  const fromSel = document.getElementById('from_station_select');
  const toSel   = document.getElementById('to_station_select');

  const opts = '<option value="0">— Vali peatus —</option>' +
    stops.map(s => `
      <option value="${s.id}">
        ${s.name} (tsoon ${s.zone_number})
      </option>
    `).join('');

  if(fromSel) fromSel.innerHTML = opts;
  if(toSel)   toSel.innerHTML   = opts;
}

function renderTripZones(stops) {
  const zones = [];

  stops.forEach(s => {
    if(!zones.some(z => String(z.id) === String(s.zone_id))) {
      zones.push({
        id: s.zone_id,
        name: s.zone_name,
        zone_number: s.zone_number
      });
    }
  });

  const fromBox = document.getElementById('from_zone_buttons');
  const toBox   = document.getElementById('to_zone_buttons');

  const makeBtn = (z, side) => `
    <button type="button"
            class="zone-btn-${side}"
            data-id="${z.id}"
            data-zone="${z.zone_number}"
            onclick="selectZone('${side}', ${z.id}, ${z.zone_number}, '${String(z.name).replace(/'/g, "\\'")}')"
            style="padding:10px 18px;border:2px solid #ccc;background:#f9f9f9;cursor:pointer;font-size:15px;">
      Tsoon ${z.zone_number}<br>
      <small style="color:#888;">${z.name}</small>
    </button>
  `;

  if(fromBox) fromBox.innerHTML = zones.map(z => makeBtn(z, 'from')).join('');
  if(toBox)   toBox.innerHTML   = zones.map(z => makeBtn(z, 'to')).join('');
}

function toggleTripSelect() {
  const catSel = document.getElementById('ticket_category_id');
  const tripSelectEl = document.getElementById('trip_id_select');

  if(!catSel) return;

  const tripChosen = !tripSelectEl || tripSelectEl.value !== '';

  Array.from(catSel.options).forEach(opt => {
    const code = opt.dataset.code || '';
    const single = isSingleTicket(code);

    opt.hidden   = single ? !tripChosen : tripChosen;
    opt.disabled = single ? !tripChosen : tripChosen;
  });

  if(catSel.options[catSel.selectedIndex]?.hidden || catSel.options[catSel.selectedIndex]?.disabled) {
    const first = Array.from(catSel.options).find(o => !o.hidden && !o.disabled);
    if(first) catSel.value = first.value;
  }

  if(!tripChosen) {
    if(sellModeGrid) sellModeGrid.style.display = 'none';

    if(blockSearch) blockSearch.style.display = 'none';
    if(blockDropdown) blockDropdown.style.display = 'none';
    if(blockZone) blockZone.style.display = 'block';

    if(modeZone) modeZone.checked = true;

    updateUI();
    return;
  }

  if(sellModeGrid) sellModeGrid.style.display = '';

  switchMode();
  updateUI();
}

document.getElementById('trip_id_select')?.addEventListener('change', function() {
  const tripId = this.value;

  const fromSel = document.getElementById('from_station_select');
  const toSel   = document.getElementById('to_station_select');
  const fromBox = document.getElementById('from_zone_buttons');
  const toBox   = document.getElementById('to_zone_buttons');

  stationsData.length = 0;

  if(fromSel) fromSel.innerHTML = '<option value="0">— Vali peatus —</option>';
  if(toSel)   toSel.innerHTML   = '<option value="0">— Vali peatus —</option>';
  if(fromBox) fromBox.innerHTML = '';
  if(toBox)   toBox.innerHTML   = '';

  selectedFromId = 0;
  selectedToId = 0;
  selectedFromZoneId = 0;
  selectedFromZoneNum = 0;
  selectedToZoneId = 0;
  selectedToZoneNum = 0;

  if(!tripId) {
    toggleTripSelect();
    return;
  }

  const data = tripStopsByTrip[tripId] || [];

  if(data.length === 0) {
    if(fromSel) fromSel.innerHTML = '<option value="0">Sellel reisil pole peatusi</option>';
    if(toSel)   toSel.innerHTML   = '<option value="0">Sellel reisil pole peatusi</option>';
    toggleTripSelect();
    return;
  }

  data.forEach(s => stationsData.push(s));

  const stationOptions =
    '<option value="0">— Vali peatus —</option>' +
    data.map(s => `<option value="${s.id}">${s.name} (tsoon ${s.zone_number})</option>`).join('');

  if(fromSel) fromSel.innerHTML = stationOptions;
  if(toSel)   toSel.innerHTML   = stationOptions;

  const zones = [];

  data.forEach(s => {
    if(!zones.some(z => String(z.id) === String(s.zone_id))) {
      zones.push({
        id: s.zone_id,
        name: s.zone_name,
        zone_number: s.zone_number
      });
    }
  });

  const zoneButtonsFrom = zones.map(z => `
    <button type="button"
            class="zone-btn-from"
            data-id="${z.id}"
            data-zone="${z.zone_number}"
            onclick="selectZone('from', ${z.id}, ${z.zone_number}, '${String(z.name).replace(/'/g, "\\'")}')"
            style="padding:10px 18px;border:2px solid #ccc;background:#f9f9f9;cursor:pointer;font-size:15px;">
      Tsoon ${z.zone_number}<br>
      <small style="color:#888;">${z.name}</small>
    </button>
  `).join('');

  const zoneButtonsTo = zones.map(z => `
    <button type="button"
            class="zone-btn-to"
            data-id="${z.id}"
            data-zone="${z.zone_number}"
            onclick="selectZone('to', ${z.id}, ${z.zone_number}, '${String(z.name).replace(/'/g, "\\'")}')"
            style="padding:10px 18px;border:2px solid #ccc;background:#f9f9f9;cursor:pointer;font-size:15px;">
      Tsoon ${z.zone_number}<br>
      <small style="color:#888;">${z.name}</small>
    </button>
  `).join('');

  if(fromBox) fromBox.innerHTML = zoneButtonsFrom;
  if(toBox)   toBox.innerHTML   = zoneButtonsTo;

  toggleTripSelect();
});

function updateUI() {
  const catSel   = document.getElementById('ticket_category_id');
  const qtyInput = document.getElementById('qty');
  const code     = catSel?.options[catSel.selectedIndex]?.dataset?.code || '';
  const zoneDiff = getZoneDiff();
  const qty      = Math.max(1, parseInt(qtyInput?.value) || 1);

  const previewVal = document.getElementById('price_preview_val');

  if(zoneDiff === null) {
    if(previewVal) previewVal.textContent = '—';
    document.getElementById('price_zone_label').textContent = 'Vali marsruut, et näha hindu';
    document.getElementById('price_table').style.display = 'none';
    return;
  }

  let unit  = calcOne(code, zoneDiff);
  let total = unit * qty;

  const staffText = document.getElementById('public_code_status')?.textContent || '';
  const isStaffPassenger = staffText.includes('PERSONAL');

if(isStaffPassenger) {
  unit = 0;
  total = 0;
}

  if(previewVal) {
  if(isStaffPassenger) {
    previewVal.innerHTML = 'Teenindad teist personali — pilet on <strong>0,00 €</strong>';
  } else {
    previewVal.textContent = qty > 1 ? fmt(unit) + ' × ' + qty + ' = ' + fmt(total) : fmt(total);
  }
}

  const rows = [
    { name: 'Üksikpilet (täis)',      code: 'FULL' },
    { name: 'Üksikpilet (soodus)',     code: 'DISCOUNT' },
    { name: 'Päevapilet (täis)',       code: 'DAY' },
    { name: 'Päevapilet (soodus)',     code: 'DAY_DISCOUNT' },
    { name: '7p periood (täis)',       code: 'PERIOD_7' },
    { name: '7p periood (soodus)',     code: 'PERIOD_7_DISCOUNT' },
    { name: '30p periood (täis)',      code: 'PERIOD_30' },
    { name: '30p periood (soodus)',    code: 'PERIOD_30_DISCOUNT' },
    { name: 'Jalgratas',               code: 'BIKE' },
  ];

  document.getElementById('price_zone_label').textContent = 'Tsoonide vahe: ' + zoneDiff;

  const tbody = document.getElementById('price_table_body');
  tbody.innerHTML = rows.map(r => {
    const p = calcOne(r.code, zoneDiff);
    return `<tr style="border-top:1px solid #eee;${r.code === code ? 'background:#eaf4ff;font-weight:bold;' : ''}">
      <td style="padding:6px;">${r.name}</td>
      <td style="text-align:right;padding:6px;">${fmt(p)}</td>
    </tr>`;
  }).join('');

  document.getElementById('price_table').style.display = 'table';
}

document.getElementById('ticket_category_id')?.addEventListener('change', function() {
  updateUI();
  toggleTripSelect();
});

document.getElementById('qty')?.addEventListener('change', updateUI);

updateUI();
toggleTripSelect();

const persCheck = document.getElementById('is_personalized');
const persBlock = document.getElementById('personalized_block');
const pubInput  = document.getElementById('public_code');
const pubStatus = document.getElementById('public_code_status');

if(persCheck) {
  persCheck.addEventListener('change', function() {
    persBlock.style.display = persCheck.checked ? 'block' : 'none';
    if(persCheck.checked && pubInput) {
      pubInput.value = '';
      pubInput.focus();
    }
  });
}

if(pubInput) {
  let debounceTimer = null;

  function doLookup() {
    const code = pubInput.value.trim();
    if(!code) return;

    pubStatus.style.color = '#888';
    pubStatus.textContent = 'Otsin...';

    fetch('lookup_user.php?code=' + encodeURIComponent(code))
      .then(r => r.json())
      .then(data => {
        if(data.found) {
          if(data.is_staff) {
            pubStatus.style.color = '#92400e';
            pubStatus.textContent = '⚠️ ' + data.name + ' — PERSONAL (sõit tasuta)';
          } else {
            pubStatus.style.color = '#2e7d32';
            pubStatus.textContent = '✓ ' + data.name;
          }
        } else {
          pubStatus.style.color = '#c62828';
          pubStatus.textContent = '✕ Kasutajat ei leitud';
          pubInput.select();
        }
      })
      .catch(() => {
        pubStatus.style.color = '#c62828';
        pubStatus.textContent = '✕ Ühenduse viga';
      });
  }

  pubInput.addEventListener('keydown', e => {
    if(e.key === 'Enter') {
      e.preventDefault();
      clearTimeout(debounceTimer);
      doLookup();
    }
  });

  pubInput.addEventListener('input', () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(doLookup, 600);
  });
}

const btnScanUser = document.getElementById('btn_scan_user');
const btnScanStop = document.getElementById('btn_scan_user_stop');
let html5QrUser = null;

function stopUserScan() {
  if(html5QrUser) {
    html5QrUser.stop().catch(() => {});
    html5QrUser = null;
  }

  document.getElementById('scan_user_container').style.display = 'none';

  if(btnScanUser) {
    btnScanUser.style.display = 'block';
  }
}

if(btnScanUser) {
  btnScanUser.addEventListener('click', function() {
    document.getElementById('scan_user_container').style.display = 'flex';
    this.style.display = 'none';

    html5QrUser = new Html5Qrcode('scan_user_reader');

    html5QrUser.start(
      { facingMode: 'environment' },
      { fps: 10, qrbox: { width: 280, height: 280 } },
      (decodedText) => {
        if(pubInput) pubInput.value = decodedText;
        stopUserScan();
        if(pubInput) pubInput.dispatchEvent(new Event('input'));
      },
      () => {}
    ).catch(() => {
      stopUserScan();
    });
  });
}

if(btnScanStop) {
  btnScanStop.addEventListener('click', stopUserScan);
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
