<?php
// book_vehicle.php — Borang Permohonan Tempahan Kenderaan Baharu
session_start();
require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$fullname      = $_SESSION['fullname'];
$role          = $_SESSION['role'];
$currentUserId = (int)$_SESSION['user_id'];

$email = $_SESSION['email'] ?? null;
if (!$email) {
    $stmt = $pdo->prepare("SELECT email FROM users WHERE user_id = ?");
    $stmt->execute([$currentUserId]);
    $email = $stmt->fetchColumn() ?: 'tiada-emel@selangor.gov.my';
}

$badgeColor = match($role) {
    'SuperAdmin' => 'badge-soft-error',
    'Admin'      => 'badge-soft-warning',
    default      => 'badge-soft-info',
};

function generateBookingNo(PDO $pdo): string {
    do {
        $no = 'VB' . date('ymd') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        $check = $pdo->prepare("SELECT 1 FROM vehicle_bookings WHERE booking_no = ?");
        $check->execute([$no]);
    } while ($check->fetchColumn());
    return $no;
}

$errorMsg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $departRaw = trim($_POST['depart_datetime'] ?? '');
        $returnRaw = trim($_POST['return_datetime'] ?? '');
        $tripType  = $_POST['trip_type'] ?? 'One Way';
        $origin    = trim($_POST['origin'] ?? '');
        $dest      = trim($_POST['destination'] ?? '');
        $pax       = (int)($_POST['passenger_total'] ?? 0);
        $purpose   = trim($_POST['purpose'] ?? '');

        if ($departRaw === '' || $origin === '' || $dest === '' || $purpose === '') {
            throw new RuntimeException('Sila lengkapkan semua maklumat wajib tempahan.');
        }
        if (!in_array($tripType, ['One Way', 'Return', 'Both'], true)) {
            throw new RuntimeException('Jenis perjalanan tidak sah.');
        }
        if ($tripType !== 'One Way') {
            if ($returnRaw === '') {
                throw new RuntimeException('Sila nyatakan tarikh & masa pulang.');
            }
            if (strtotime($returnRaw) <= strtotime($departRaw)) {
                throw new RuntimeException('Tarikh pulang mestilah selepas tarikh berangkat.');
            }
        }

        $bookingNo = generateBookingNo($pdo);

        // Kenderaan dan Pemandu ditinggalkan NULL (Diselaraskan oleh Admin/SuperAdmin)
        $stmt = $pdo->prepare(
            "INSERT INTO vehicle_bookings
             (booking_no, user_id, depart_datetime, return_datetime, trip_type, origin, destination,
              passenger_total, purpose, vehicle_id, driver_id, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, 'Pending')"
        );
        $stmt->execute([
            $bookingNo, $currentUserId, $departRaw, $returnRaw !== '' ? $returnRaw : null,
            $tripType, $origin, $dest, $pax > 0 ? $pax : null, $purpose
        ]);
        $newId = (int)$pdo->lastInsertId();

        $hist = $pdo->prepare("INSERT INTO booking_history (booking_id, action, remarks, action_by) VALUES (?, 'Dicipta', ?, ?)");
        $hist->execute([$newId, "Tempahan {$bookingNo} dicipta oleh pengguna.", $currentUserId]);

        $_SESSION['flash'] = ['type' => 'success', 'msg' => "Tempahan {$bookingNo} berjaya dihantar dan menunggu kelulusan pentadbir."];
        header("Location: bookings.php");
        exit();

    } catch (RuntimeException $e) {
        $errorMsg = $e->getMessage();
    } catch (PDOException $e) {
        $errorMsg = 'Ralat pangkalan data berlaku. Sila cuba lagi.';
    }
}

$pendingApprovals = $pdo->query("SELECT COUNT(*) FROM vehicle_bookings WHERE status = 'Pending'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ms" data-theme="light">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no" />
    <title>Kembara - Borang Tempahan Kenderaan</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet" />

    <link href="https://cdn.jsdelivr.net/npm/daisyui@5" rel="stylesheet" type="text/css" />
    <link href="https://cdn.jsdelivr.net/npm/daisyui@5/themes.css" rel="stylesheet" type="text/css" />
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

    <!-- Leaflet CSS & JS (OpenStreetMap) -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

    <style>
        :root, [data-theme] {
            --ta-canvas: var(--color-base-200);
            --ta-surface: var(--color-base-100);
            --ta-border: var(--color-base-300);
            --ta-ink: var(--color-base-content);
            --ta-muted: color-mix(in oklch, var(--color-base-content) 55%, transparent);
            --ta-brand: var(--color-primary);
            --ta-brand-50: color-mix(in oklch, var(--color-primary) 12%, var(--color-base-100));
        }

        .bg-white { background-color: var(--ta-surface) !important; }
        .text-slate-400, .text-slate-500 { color: var(--ta-muted) !important; }
        .text-slate-700, .text-slate-800 { color: var(--ta-ink) !important; }
        .hover\:bg-slate-50:hover, .hover\:bg-slate-100:hover { background-color: var(--ta-canvas) !important; }

        * { font-family: 'Outfit', ui-sans-serif, system-ui, sans-serif; }

        body {
            background: var(--ta-canvas);
            color: var(--ta-ink);
            zoom: 110%;
            transition: background-color 0.2s ease, color 0.2s ease;
        }

        .ta-card, .ta-sidebar, nav {
            background: var(--ta-surface) !important;
            border-color: var(--ta-border) !important;
            color: var(--ta-ink) !important;
        }

        .ta-nav-link {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            border-radius: 0.5rem;
            padding: 0.55rem 0.75rem;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--ta-muted);
            transition: all .15s ease;
        }
        .ta-nav-link:hover { background: var(--ta-canvas); color: var(--ta-ink); }
        .ta-nav-link.active { background: var(--ta-brand-50); color: var(--ta-brand); font-weight: 600; }
        .ta-nav-icon { display: inline-flex; width: 1.25rem; height: 1.25rem; flex-shrink: 0; }

        .ta-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            border-radius: 9999px;
            padding: 0.15rem 0.65rem;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .badge-soft-error   { background: color-mix(in oklch, var(--color-error) 18%, var(--color-base-100));   color: var(--color-error); }
        .badge-soft-warning { background: color-mix(in oklch, var(--color-warning) 18%, var(--color-base-100)); color: var(--color-warning); }
        .badge-soft-info    { background: color-mix(in oklch, var(--color-info) 18%, var(--color-base-100));    color: var(--color-info); }

        #map {
            height: 380px;
            width: 100%;
            border-radius: 0.75rem;
            z-index: 10;
        }
    </style>
</head>
<body class="min-h-screen">

    <!-- Sidebar -->
    <aside id="sidenav-main" class="fixed inset-y-0 left-0 z-[70] w-64 hidden xl:flex xl:flex-col overflow-y-auto ta-sidebar border-r" style="border-color:var(--ta-border)">
        <div class="h-16 flex items-center px-6 border-b" style="border-color:var(--ta-border)">
            <a class="flex items-center gap-2.5" href="dashboard.php">
                <img src="assets/img/logo.png" alt="Logo Kembara" class="h-8 w-auto object-contain shrink-0" />
                <span class="font-bold tracking-tight text-[1.05rem]">Kembara</span>
            </a>
        </div>

        <div class="flex-1 px-4 py-5">
            <p class="px-3 mb-2 text-[0.65rem] font-semibold uppercase tracking-widest text-slate-400">Menu Utama</p>
            <ul class="flex flex-col gap-1">
                <li>
                    <a class="ta-nav-link" href="dashboard.php">
                        <svg class="ta-nav-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5M3.75 3h16.5M21.75 3v11.25A2.25 2.25 0 0119.5 16.5H17.25m-10.5 0h6m-6 0v3.75A1.5 1.5 0 007.5 21.75h9a1.5 1.5 0 001.5-1.5V16.5m-10.5 0h10.5" /></svg>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li>
                    <a class="ta-nav-link active" href="bookings.php">
                        <svg class="ta-nav-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5m-13.5-6h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm3-3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z" /></svg>
                        <span>Tempahan</span>
                    </a>
                </li>
                <li>
                    <a class="ta-nav-link" href="vehicles.php">
                        <svg class="ta-nav-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 0h-12" /></svg>
                        <span>Kenderaan</span>
                    </a>
                </li>
                <li>
                    <a class="ta-nav-link" href="drivers.php">
                        <svg class="ta-nav-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" /></svg>
                        <span>Pemandu</span>
                    </a>
                </li>
                <?php if ($role === 'SuperAdmin'): ?>
                <li>
                    <a class="ta-nav-link" href="users.php">
                        <svg class="ta-nav-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
                        <span>Pengguna</span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </aside>

    <main class="xl:ml-64 relative min-h-screen pb-24 xl:pb-0">
        <!-- Navbar Atas dengan Breadcrumb Klik -->
        <nav class="sticky top-0 z-50 flex items-center justify-between gap-4 px-4 sm:px-6 py-3 bg-white border-b" style="border-color:var(--ta-border)">
            <div class="flex items-center gap-3">
                <div>
                    <h6 class="font-bold text-base leading-tight">Borang Tempahan Kenderaan</h6>
                    <p class="text-xs leading-tight" style="color:var(--ta-muted)">
                        <a href="dashboard.php" class="hover:underline opacity-70">Dashboard</a>
                        <span class="mx-1 opacity-40">/</span>
                        <a href="bookings.php" class="hover:underline opacity-70">Tempahan</a>
                        <span class="mx-1 opacity-40">/</span>
                        <span class="font-semibold text-primary">Borang Baharu</span>
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <button type="button" id="theme-toggle" class="btn btn-ghost btn-circle text-slate-500 hover:bg-slate-100 border-0 h-10 w-10 min-h-0">
                    <span id="theme-toggle-icon"></span>
                </button>

                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost rounded-full pl-1 pr-2 py-1 flex items-center gap-2 h-auto min-h-0">
                        <div class="avatar placeholder">
                            <div class="rounded-full w-8 h-8 flex items-center justify-center font-bold text-xs uppercase text-white" style="background:var(--ta-brand)">
                                <?= htmlspecialchars(substr($fullname, 0, 1)) ?>
                            </div>
                        </div>
                        <span class="text-sm font-semibold hidden sm:inline-block"><?= htmlspecialchars($fullname) ?></span>
                    </div>
                    <ul tabindex="0" class="dropdown-content z-[99] menu p-3 shadow-lg ta-card rounded-2xl w-64 mt-2 border" style="border-color: var(--ta-border);">
                        <li class="px-3 py-2 border-b mb-1" style="border-color:var(--ta-border)">
                            <div class="flex items-center justify-between gap-2">
                                <p class="font-bold text-sm truncate"><?= htmlspecialchars($fullname) ?></p>
                                <span class="ta-badge <?= $badgeColor ?>"><?= htmlspecialchars($role) ?></span>
                            </div>
                            <p class="text-xs text-slate-400 mt-1 truncate"><?= htmlspecialchars($email) ?></p>
                        </li>
                        <li><a href="profile.php" class="py-2.5 text-xs font-medium">Profil Saya</a></li>
                        <div class="divider my-1"></div>
                        <li><a href="logout.php" class="py-2.5 text-xs font-semibold text-red-600">Log Keluar</a></li>
                    </ul>
                </div>
            </div>
        </nav>

        <div class="w-full px-4 sm:px-6 py-6 mx-auto max-w-5xl">
            <?php if ($errorMsg): ?>
                <div class="mb-4 p-4 rounded-xl bg-error/15 text-error text-sm font-medium border border-error/30">
                    <?= htmlspecialchars($errorMsg) ?>
                </div>
            <?php endif; ?>

            <form action="book_vehicle.php" method="POST">
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
                    
                    <!-- Ruang Kiri: Peta Lokasi Interaktif (Wow Factor) -->
                    <div class="lg:col-span-7 flex flex-col gap-4">
                        <div class="ta-card p-5 rounded-2xl border">
                            <div class="flex items-center justify-between mb-3">
                                <div>
                                    <h6 class="font-bold text-base">Pilih Lokasi Pada Peta</h6>
                                    <p class="text-xs text-slate-400">Klik pada peta untuk menetapkan lokasi Asal atau Destinasi secara automatik.</p>
                                </div>
                                <div class="join">
                                    <button type="button" id="btn-mode-origin" class="btn btn-xs join-item btn-primary" onclick="setPinMode('origin')">Asal</button>
                                    <button type="button" id="btn-mode-dest" class="btn btn-xs join-item btn-outline" onclick="setPinMode('dest')">Destinasi</button>
                                </div>
                            </div>
                            
                            <div id="map"></div>
                            
                            <p class="text-[11px] text-slate-400 mt-2 italic">* Peta mengesan koordinat dan menukar alamat lokasi pilihan anda secara automatik.</p>
                        </div>
                    </div>

                    <!-- Ruang Kanan: Borang Maklumat Tempahan -->
                    <div class="lg:col-span-5 flex flex-col gap-4">
                        <div class="ta-card p-5 rounded-2xl border flex flex-col gap-4">
                            <h6 class="font-bold text-base border-b pb-2" style="border-color:var(--ta-border)">Maklumat Perjalanan</h6>

                            <div>
                                <label class="text-xs font-semibold block mb-1">Jenis Perjalanan</label>
                                <select name="trip_type" id="trip-type" class="select select-bordered w-full text-sm" onchange="toggleReturnField()">
                                    <option value="One Way">Sehala</option>
                                    <option value="Return">Pergi Balik</option>
                                </select>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="text-xs font-semibold block mb-1">Tarikh & Masa Berangkat</label>
                                    <input type="datetime-local" name="depart_datetime" required class="input input-bordered w-full text-xs" />
                                </div>
                                <div id="return-field-wrap" class="hidden">
                                    <label class="text-xs font-semibold block mb-1">Tarikh & Masa Pulang</label>
                                    <input type="datetime-local" name="return_datetime" id="return-field" class="input input-bordered w-full text-xs" />
                                </div>
                            </div>

                            <div>
                                <label class="text-xs font-semibold block mb-1">Asal (Origin)</label>
                                <input type="text" id="origin-input" name="origin" required class="input input-bordered w-full text-sm" placeholder="Klik pada peta atau taip lokasi..." />
                            </div>

                            <div>
                                <label class="text-xs font-semibold block mb-1">Destinasi</label>
                                <input type="text" id="dest-input" name="destination" required class="input input-bordered w-full text-sm" placeholder="Klik pada peta atau taip lokasi..." />
                            </div>

                            <div>
                                <label class="text-xs font-semibold block mb-1">Bilangan Penumpang</label>
                                <input type="number" name="passenger_total" min="1" value="1" required class="input input-bordered w-full text-sm" />
                            </div>

                            <div>
                                <label class="text-xs font-semibold block mb-1">Tujuan Perjalanan</label>
                                <textarea name="purpose" required rows="3" class="textarea textarea-bordered w-full text-sm" placeholder="Nyatakan urusan rasmi..."></textarea>
                            </div>

                            <div class="pt-2 flex items-center justify-end gap-2 border-t" style="border-color:var(--ta-border)">
                                <a href="bookings.php" class="btn btn-ghost btn-sm">Batal</a>
                                <button type="submit" class="btn btn-sm text-white border-0" style="background:var(--ta-brand)">Hantar Tempahan</button>
                            </div>
                        </div>
                    </div>

                </div>
            </form>

            <footer class="pt-8 pb-2">
                <div class="text-sm leading-normal text-center text-slate-400">
                    © <?= date('Y') ?> Kembara &middot; Perbendaharaan Negeri Selangor
                </div>
            </footer>
        </div>
    </main>

    <script>
        function toggleReturnField() {
            const tripType = document.getElementById('trip-type').value;
            const wrap = document.getElementById('return-field-wrap');
            if (tripType === 'One Way') {
                wrap.classList.add('hidden');
            } else {
                wrap.classList.remove('hidden');
            }
        }

        // --- Skrip Leaflet Map (OpenStreetMap Reverse Geocoding) ---
        let pinMode = 'origin'; // 'origin' atau 'dest'
        let originMarker = null;
        let destMarker = null;

        // Pusat Laluan: Bangunan SUK Selangor, Shah Alam
        const defaultLat = 3.0733;
        const defaultLng = 101.5185;

        const map = L.map('map').setView([defaultLat, defaultLng], 13);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap'
        }).addTo(map);

        function setPinMode(mode) {
            pinMode = mode;
            const btnOrigin = document.getElementById('btn-mode-origin');
            const btnDest = document.getElementById('btn-mode-dest');

            if (mode === 'origin') {
                btnOrigin.className = 'btn btn-xs join-item btn-primary';
                btnDest.className = 'btn btn-xs join-item btn-outline';
            } else {
                btnOrigin.className = 'btn btn-xs join-item btn-outline';
                btnDest.className = 'btn btn-xs join-item btn-primary';
            }
        }

        // Reverse Geocoding dengan Nominatim OpenStreetMap API
        async function fetchAddress(lat, lng, targetInputId) {
            const input = document.getElementById(targetInputId);
            input.value = "Mendapatkan alamat...";
            try {
                const response = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}`);
                const data = await response.json();
                if (data && data.display_name) {
                    input.value = data.display_name;
                } else {
                    input.value = `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
                }
            } catch (err) {
                input.value = `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
            }
        }

        map.on('click', function(e) {
            const lat = e.latlng.lat;
            const lng = e.latlng.lng;

            if (pinMode === 'origin') {
                if (originMarker) map.removeLayer(originMarker);
                originMarker = L.marker([lat, lng]).addTo(map).bindPopup("Lokasi Asal").openPopup();
                fetchAddress(lat, lng, 'origin-input');
                setPinMode('dest'); // Tukar mod automatik ke destinasi selepas pilih asal
            } else {
                if (destMarker) map.removeLayer(destMarker);
                destMarker = L.marker([lat, lng]).addTo(map).bindPopup("Lokasi Destinasi").openPopup();
                fetchAddress(lat, lng, 'dest-input');
            }
        });
    </script>

    <!-- Skrip Tema Terang/Gelap -->
    <script>
        const themeToggleBtn = document.getElementById('theme-toggle');
        const themeToggleIcon = document.getElementById('theme-toggle-icon');

        const LIGHT_THEME = 'light';
        const DARK_THEME  = 'dracula';

        const moonIcon = `<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" /></svg>`;
        const sunIcon = `<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" /></svg>`;

        function applyTheme(theme) {
            document.documentElement.setAttribute('data-theme', theme);
            localStorage.setItem('theme', theme);
            themeToggleIcon.innerHTML = theme === DARK_THEME ? sunIcon : moonIcon;
        }

        const savedTheme = localStorage.getItem('theme');
        const systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        const initialTheme = savedTheme || (systemPrefersDark ? DARK_THEME : LIGHT_THEME);

        applyTheme(initialTheme);

        themeToggleBtn.addEventListener('click', () => {
            const currentTheme = document.documentElement.getAttribute('data-theme');
            const newTheme = currentTheme === DARK_THEME ? LIGHT_THEME : DARK_THEME;
            applyTheme(newTheme);
        });
    </script>
</body>
</html>