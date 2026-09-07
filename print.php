<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/vendor/setasign/fpdf/fpdf.php';
require_once __DIR__ . '/vendor/autoload.php'; // for FPDI (composer require setasign/fpdi)

use setasign\Fpdi\Fpdi;

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$currentUserId = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'User';
$bookingId = (int)($_GET['id'] ?? 0);

if ($bookingId <= 0) {
    http_response_code(400);
    exit('Tempahan tidak sah.');
}

$stmt = $pdo->prepare(
    "SELECT vb.*, u.fullname AS requester_name, u.phone_no,
                d.department_name,
                v.plate_no, v.vehicle_name,
                du.fullname AS driver_name,
                ap.fullname AS approved_by_name
     FROM vehicle_bookings vb
     JOIN users u ON u.user_id = vb.user_id
     LEFT JOIN departments d ON d.department_id = u.department_id
     LEFT JOIN vehicles v ON v.vehicle_id = vb.vehicle_id
     LEFT JOIN drivers dr ON dr.driver_id = vb.driver_id
     LEFT JOIN users du ON du.user_id = dr.user_id
     LEFT JOIN users ap ON ap.user_id = vb.approved_by
     WHERE vb.booking_id = ?"
);
$stmt->execute([$bookingId]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking || ($role === 'User' && (int)$booking['user_id'] !== $currentUserId)) {
    http_response_code(404);
    exit('Tempahan tidak dijumpai.');
}

$departDate = date('d/m/Y', strtotime($booking['depart_datetime']));
$departTime = date('g.i', strtotime($booking['depart_datetime']));
$departMeridiem = date('A', strtotime($booking['depart_datetime'])) === 'AM' ? 'pagi' : 'petang';
$returnDate = $booking['return_datetime'] ? date('d/m/Y', strtotime($booking['return_datetime'])) : '';
$returnTime = $booking['return_datetime'] ? date('g.i', strtotime($booking['return_datetime'])) : '';
$returnMeridiem = $booking['return_datetime'] && date('A', strtotime($booking['return_datetime'])) === 'AM' ? 'pagi' : 'petang';

$formPurpose = trim($booking['purpose'] ?? '');
$createdDate = !empty($booking['created_at']) ? date('d/m/Y', strtotime($booking['created_at'])) : date('d/m/Y');

// ---------------------------------------------------------------- PDF CLASS ----
// Extends FPDI (which itself extends FPDF), so all your normal FPDF calls
// (Cell, MultiCell, SetFont, etc.) still work exactly the same.
class PDF extends Fpdi {
    function DottedLine($x1, $y, $x2, $spacing = 1.4, $dash = 0.6)
    {
        $this->SetLineWidth(0.2);
        $x = $x1;
        while ($x < $x2) {
            $xEnd = min($x + $dash, $x2);
            $this->Line($x, $y, $xEnd, $y);
            $x += $spacing;
        }
    }

    function FilledLine($x1, $y, $x2, $text, $utf8, $fontSize = 10)
    {
        $this->DottedLine($x1, $y, $x2);
        if ($text !== '' && $text !== null) {
            $this->SetFont('Arial', '', $fontSize);
            $this->SetXY($x1 + 1, $y - 5.5);
            $this->Cell($x2 - $x1 - 2, 5, $utf8($text));
        }
    }
}

$pdf = new PDF('P', 'mm', 'A4');
$pdf->SetAutoPageBreak(false);
$pdf->SetMargins(20, 20, 20);

$utf8 = fn($str) => iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $str ?? '');

$LEFT = 20; $RIGHT = 190; $LABEL_X = 20; $COLON_X = 78; $FIELD_X = 85;

// ============================================================== PAGE 1 =====
$pdf->AddPage();

$pdf->SetFont('Arial', 'B', 12);
$pdf->SetY(20);
$pdf->Cell(0, 6, $utf8('PERMOHONAN PENGGUNAAN KENDERAAN JABATAN'), 0, 1, 'C');
$pdf->Cell(0, 6, $utf8('PERBENDAHARAAN NEGERI SELANGOR'), 0, 1, 'C');

$pdf->Ln(6);
$pdf->SetFont('Arial', '', 11);
$intro = "Adalah saya bernama seperti di bawah memohon menggunakan Kenderaan "
       . "Perbendaharaan Negeri Selangor. Butir-butir permohonan adalah seperti berikut:";
$pdf->SetX($LEFT);
$pdf->MultiCell($RIGHT - $LEFT, 6, $utf8($intro), 0, 'J');
$pdf->Ln(4);

// NAMA PEMOHON
$y = $pdf->GetY();
$pdf->SetXY($LABEL_X, $y); $pdf->Cell(56, 6, $utf8('NAMA PEMOHON'));
$pdf->SetXY($COLON_X, $y); $pdf->Cell(4, 6, ':');
$pdf->FilledLine($FIELD_X, $y + 7, $RIGHT, $booking['requester_name'], $utf8);
$pdf->Ln(11);

// BAHAGIAN/UNIT
$y = $pdf->GetY();
$pdf->SetXY($LABEL_X, $y); $pdf->Cell(56, 6, $utf8('BAHAGIAN/UNIT'));
$pdf->SetXY($COLON_X, $y); $pdf->Cell(4, 6, ':');
$pdf->FilledLine($FIELD_X, $y + 7, $RIGHT, $booking['department_name'] ?? '', $utf8);
$pdf->Ln(11);

// NO. TELEFON/SAMBUNGAN
$y = $pdf->GetY();
$pdf->SetXY($LABEL_X, $y); $pdf->Cell(56, 6, $utf8('NO. TELEFON/SAMBUNGAN'));
$pdf->SetXY($COLON_X, $y); $pdf->Cell(4, 6, ':');
$pdf->FilledLine($FIELD_X, $y + 7, $RIGHT, $booking['phone_no'] ?? '', $utf8);
$pdf->Ln(11);

// TARIKH PERGI / MASA
$y = $pdf->GetY();
$pdf->SetXY($LABEL_X, $y); $pdf->Cell(56, 6, $utf8('TARIKH PERGI'));
$pdf->SetXY($COLON_X, $y); $pdf->Cell(4, 6, ':');
$pdf->FilledLine($FIELD_X, $y + 7, $FIELD_X + 45, $departDate, $utf8);
$pdf->SetXY($FIELD_X + 50, $y); $pdf->Cell(20, 6, $utf8('MASA:'));
$pdf->FilledLine($FIELD_X + 70, $y + 7, $RIGHT, "$departTime $departMeridiem", $utf8);
$pdf->Ln(11);

// TARIKH BALIK / MASA
$y = $pdf->GetY();
$pdf->SetXY($LABEL_X, $y); $pdf->Cell(56, 6, $utf8('TARIKH BALIK'));
$pdf->SetXY($COLON_X, $y); $pdf->Cell(4, 6, ':');
$pdf->FilledLine($FIELD_X, $y + 7, $FIELD_X + 45, $returnDate, $utf8);
$pdf->SetXY($FIELD_X + 50, $y); $pdf->Cell(20, 6, $utf8('MASA:'));
$pdf->FilledLine($FIELD_X + 70, $y + 7, $RIGHT, $returnDate ? "$returnTime $returnMeridiem" : '', $utf8);
$pdf->Ln(11);

// DESTINASI (wraps to 2 lines)
$y = $pdf->GetY();
$pdf->SetXY($LABEL_X, $y); $pdf->Cell(56, 6, $utf8('DESTINASI'));
$pdf->SetXY($COLON_X, $y); $pdf->Cell(4, 6, ':');
$destLines = explode("\n", wordwrap($booking['destination'] ?? '', 75, "\n"));
$pdf->FilledLine($FIELD_X, $y + 7, $RIGHT, $destLines[0] ?? '', $utf8);
$pdf->FilledLine($FIELD_X, $y + 14, $RIGHT, $destLines[1] ?? '', $utf8);
$pdf->Ln(18);

// TUJUAN
$y = $pdf->GetY();
$pdf->SetXY($LABEL_X, $y); $pdf->Cell(56, 6, $utf8('TUJUAN'));
$pdf->SetXY($COLON_X, $y); $pdf->Cell(4, 6, ':');
$purposeLines = explode("\n", wordwrap($formPurpose, 75, "\n"));
$pdf->FilledLine($FIELD_X, $y + 7, $RIGHT, $purposeLines[0] ?? '', $utf8);
$pdf->Ln(11);

// BILANGAN PENGGUNA (NAMA PENGGUNA)
$y = $pdf->GetY();
$pdf->SetXY($LABEL_X, $y);
$pdf->MultiCell(56, 6, $utf8("BILANGAN PENGGUNA\n(NAMA PENGGUNA)"));
$pdf->SetXY($COLON_X, $y); $pdf->Cell(4, 6, ':');

if (!empty($booking['passenger_memo_path'])) {
    $pdf->FilledLine($FIELD_X, $y + 7, $RIGHT, '*SEPERTI DI SENARAI LAMPIRAN', $utf8, 9);
} else {
    $penggunaList = $booking['passenger_names']
        ? (json_decode($booking['passenger_names'], true) ?: [])
        : [];
    $penggunaList = array_values($penggunaList);
    $paxLabel = $booking['passenger_total'] ? ($booking['passenger_total'] . ' Orang') : '';
    if ($paxLabel !== '') {
        $pdf->FilledLine($FIELD_X, $y + 7, $RIGHT, $paxLabel, $utf8);
        for ($i = 0; $i < 3; $i++) {
            $pdf->FilledLine($FIELD_X, $y + 14 + $i * 7, $RIGHT, $penggunaList[$i] ?? '', $utf8);
        }
    } else {
        for ($i = 0; $i < 4; $i++) {
            $pdf->FilledLine($FIELD_X, $y + 7 + $i * 7, $RIGHT, $penggunaList[$i] ?? '', $utf8);
        }
    }
}
$pdf->SetY($y + 7 + 4 * 7 + 4);

// Declaration
$pdf->Ln(2);
$pdf->SetX($LEFT);
$cert = "Disahkan bahawa permohonan ini adalah untuk urusan rasmi. Saya berjanji akan "
      . "memastikan kenderaan tersebut digunakan dengan cermat untuk tujuan yang "
      . "dinyatakan dan dijaga dengan selamat. Saya juga akan memastikan kenderaan "
      . "berkenaan dikembalikan kepada Perbendaharaan Negeri Selangor pada tarikh dan "
      . "masa yang dinyatakan dalam keadaan yang baik dan bersih serta selamat digunakan.";
$pdf->SetFont('Arial', '', 11);
$pdf->MultiCell($RIGHT - $LEFT, 6, $utf8($cert), 0, 'J');

// Signature blocks
$pdf->Ln(18);
$y = $pdf->GetY();
$col1_x = $LEFT; $col2_x = 112;
$pdf->DottedLine($col1_x, $y, $col1_x + 65);
$pdf->DottedLine($col2_x, $y, $col2_x + 65);
$pdf->SetFont('Arial', '', 10);
$pdf->SetXY($col1_x, $y + 2); $pdf->Cell(65, 6, $utf8('(Tandatangan/Cop Pemohon)'), 0, 0, 'C');
$pdf->SetXY($col2_x, $y + 2); $pdf->Cell(65, 6, $utf8('(Tandatangan/Cop Ketua Bahagian/Unit)'), 0, 0, 'C');

$pdf->Ln(20);
$pdf->SetFont('Arial', '', 11);
$y = $pdf->GetY();
$pdf->SetXY($col1_x, $y); $pdf->Cell(65, 6, $utf8('Tarikh: ' . $createdDate));
$pdf->SetXY($col2_x, $y); $pdf->Cell(65, 6, $utf8('Tarikh:'));

if (!empty($booking['passenger_memo_path'])) {
    $pdf->Ln(14);
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->SetX($LEFT);
    $pdf->Cell(0, 5, $utf8('* Nota: Memo senarai pengguna disertakan pada halaman berikut.'), 0, 1, 'C');
}

// ============================================================ MEMO PAGES ====
// Append the uploaded memo PDF (passenger_memo_path) as extra pages, if present
if (!empty($booking['passenger_memo_path'])) {
    $memoPath = __DIR__ . '/' . ltrim($booking['passenger_memo_path'], '/');

    if (is_file($memoPath)) {
        $pageCount = $pdf->setSourceFile($memoPath);
        for ($i = 1; $i <= $pageCount; $i++) {
            $tplId = $pdf->importPage($i);
            $size  = $pdf->getTemplateSize($tplId);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($tplId);
        }
    }
}

$pdf->Output('I', 'Borang_Permohonan_' . $booking['booking_no'] . '.pdf');