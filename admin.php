<?php
// admin.php - Tam, dayanıklı Excel -> MySQL yükleyici
require __DIR__ . '/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

// DB bağlantısı
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'excel_db';

$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($conn->connect_error) {
    die("Veritabanı bağlantı hatası: " . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

// Tablo oluştur (yoksa)
$conn->query("
CREATE TABLE IF NOT EXISTS veriler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    servis_fis_no VARCHAR(50) NOT NULL,
    tarih DATE,
    bildirilen_problem TEXT,
    sube_adi VARCHAR(100),
    garanti VARCHAR(50),
    iscilik_bedeli DECIMAL(10,2),
    malzeme_bedeli DECIMAL(10,2),
    kompresor_tamir DECIMAL(10,2),
    taseron_malzeme DECIMAL(10,2),
    satinalinan_malzeme DECIMAL(10,2),
    evap_degisim DECIMAL(10,2),
    malzeme_bedeli2 DECIMAL(10,2),
    toplam_fatura DECIMAL(10,2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// --- Yardımcı fonksiyonlar ---

/**
 * Hücreden gelen sayısal değeri güvenli float'a çevirir.
 * Nokta/virgül/thousand separator temizlenir.
 * Boş veya geçersiz ise NULL döner.
 */
function parse_number($val) {
    if ($val === null) return null;
    $val = trim((string)$val);
    if ($val === '') return null;

    // Bazı Excel hücreleri "1.234,56" veya "1,234.56" veya "1234,56" vs. olabilir.
    // En iyi yaklaşım: eğer virgül varsa ve nokta yok => virgül ondalık ayırıcı
    // eğer hem nokta hem virgül varsa, son karaktere göre karar ver.
    $v = $val;

    // Remove non-number except . and ,
    $v = preg_replace('/[^\d\-,\.]/u','',$v);

    // Eğer hem virgül hem nokta varsa:
    if (strpos($v, ',') !== false && strpos($v, '.') !== false) {
        // Sonuncu hangi karakterse onu ondalık ayırıcı kabul et
        $lastComma = strrpos($v, ',');
        $lastDot = strrpos($v, '.');
        if ($lastComma > $lastDot) {
            // comma is decimal separator
            $v = str_replace('.', '', $v); // remove thousand dots
            $v = str_replace(',', '.', $v); // comma -> dot
        } else {
            // dot is decimal separator
            $v = str_replace(',', '', $v); // remove thousand commas
        }
    } elseif (strpos($v, ',') !== false && strpos($v, '.') === false) {
        // only comma -> decimal separator (Turkish style)
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    } else {
        // only dot or none -> dot decimal ok; remove any thousand commas just in case
        $v = str_replace(',', '', $v);
    }

    // Now numeric?
    if ($v === '' || !is_numeric($v)) return null;
    return floatval($v);
}

/**
 * Hücreden gelen tarih değerini güvenli şekilde Y-m-d formatına çevirir.
 * - Excel serial number (numeric) -> Date::excelToTimestamp
 * - Yaygın formatlar: Y-m-d, d.m.Y, d/m/Y, m/d/Y
 * - strtotime fallback
 * Eğer çözülemezse NULL döner.
 */
function parse_date($val) {
    if ($val === null) return null;
    $val = trim((string)$val);
    if ($val === '') return null;

    // Eğer numeric (Excel serial) -> excelToTimestamp
    if (is_numeric($val)) {
        try {
            $ts = Date::excelToTimestamp($val);
            // Date::excelToTimestamp döndürmezse try-catch yeterli
            if ($ts && $ts > 0) return date('Y-m-d', $ts);
        } catch (Exception $e) {
            // ignore
        }
    }

    

    
    $formats = [
        'Y-m-d', 'd.m.Y', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'm-d-Y', 'Y.m.d'
    ];
    foreach ($formats as $fmt) {
        $d = DateTime::createFromFormat($fmt, $val);
        if ($d && $d->getTimestamp() > 0) {
            return $d->format('Y-m-d');
        }
    }

    
    $ts = strtotime($val);
    if ($ts && $ts > 0) {
        return date('Y-m-d', $ts);
    }

    // Eğer başarısız -> NULL (veya istersen bugünün tarihine al)
    return null;
}

/**
 * Hücre başlığını normalize eder (küçült, boşlukları kırpar, özel karakterleri kaldır)
 */
function normalize_header($h) {
    $h = mb_strtolower(trim((string)$h));
    $h = str_replace(["\r","\n","\t"], ' ', $h);
    $h = preg_replace('/\s+/', ' ', $h);
    $h = trim($h, " \t\n\r\0\x0B:;");
    return $h;
}


$message = null;
$exampleErrors = [];

if (isset($_POST['submit']) && isset($_FILES['excel'])) {
    $tmp = $_FILES['excel']['tmp_name'];
    if (!is_uploaded_file($tmp)) {
        $message = "Dosya yükleme hatası.";
    } else {
        try {
            $spreadsheet = IOFactory::load($tmp);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, true); // A..Z indexli

            if (count($rows) < 2) {
                $message = "Excel dosyasında yeterli satır yok.";
            } else {
                // Başlık satırını bul (1. satır varsayıldı)
                $headerRow = $rows[1];
                $map = []; // normalized header => column letter

                foreach ($headerRow as $col => $val) {
                    $key = normalize_header($val);
                    if ($key === '') continue;
                    $map[$key] = $col;
                }

                
                
                $expected = [
                    'servis fiş no' => ['servis fiş no','servis fis no','servis_fis_no','servis fisno','servis no'],
                    'tarih' => ['tarih','date'],
                    'bildirilen problem' => ['bildirilen problem','problem','ariza','arızalanan problem'],
                    'şube adı' => ['şube adı','sube adı','sube_adi','sube adi','şube'],
                    'garanti' => ['garanti'],
                    'işçilik bedeli' => ['işçilik bedeli','iscilik bedeli','iscilik','işçilik'],
                    'malzeme bedeli' => ['malzeme bedeli','malzeme','malzeme bedeli 1'],
                    'kompresör tamir' => ['kompresör tamir','kompresor tamir','kompresör','kompresor_tamir'],
                    'taşeron malzeme' => ['taşeron malzeme','taseron malzeme','taşeron'],
                    'satın alınan malzeme' => ['satın alınan malzeme','satinalinan malzeme','satın alınan','satinalinan'],
                    'evap değişim' => ['evap değişim','evap degisim','evap'],
                    'malzeme bedeli 2' => ['malzeme bedeli 2','malzeme bedeli2','malzeme 2'],
                    'toplam fatura' => ['toplam fatura','toplam','toplam_fatura']
                ];

                // oluşturulmuş sütun eşlemesi (expected_key => column letter|null)
                $colFor = [];
                foreach ($expected as $canonical => $aliases) {
                    $found = null;
                    foreach ($aliases as $a) {
                        $aNorm = normalize_header($a);
                        foreach ($map as $hNorm => $colLetter) {
                            // hNorm örn: "servis fiş no" ; alias aNorm "servis fis no" -> partial match
                            if ($hNorm === $aNorm || strpos($hNorm, $aNorm) !== false || strpos($aNorm, $hNorm) !== false) {
                                $found = $colLetter;
                                break 2;
                            }
                        }
                    }
                    $colFor[$canonical] = $found; // null ise bulunamadı
                }

                // Eğer kritik bir sütun (servis fiş no) yoksa, denemek için A,B,C vs kullanacağız
                if ($colFor['servis fiş no'] === null) {
                    // fallback: 1. sütunu servis fiş no kabul et
                    $colFor['servis fiş no'] = 'A';
                }
                if ($colFor['tarih'] === null) {
                    // fallback: 2. sütun tarih
                    $colFor['tarih'] = 'B';
                }
                if ($colFor['toplam fatura'] === null) {
                    $colFor['toplam fatura'] = 'M'; // fallback son sütun
                }

                // Prepared statement
                $stmt = $conn->prepare("
                    INSERT INTO veriler (
                        servis_fis_no, tarih, bildirilen_problem, sube_adi, garanti,
                        iscilik_bedeli, malzeme_bedeli, kompresor_tamir, taseron_malzeme,
                        satinalinan_malzeme, evap_degisim, malzeme_bedeli2, toplam_fatura
                    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                ");
                $types = "sssssdddddddd"; // 5 string + 8 decimal

                $conn->begin_transaction();
                $inserted = 0;
                $skipped = 0;
                $errors = [];

                $lastRowIndex = max(array_keys($rows));
                for ($r = 2; $r <= $lastRowIndex; $r++) {
                    if (!isset($rows[$r])) continue;
                    $row = $rows[$r];

                    // Hepsi boş mu kontrolü
                    $allEmpty = true;
                    foreach ($row as $cval) {
                        if (trim((string)$cval) !== '') { $allEmpty = false; break; }
                    }
                    if ($allEmpty) { $skipped++; continue; }

                    // Hücre değerlerini sütun eşlemesine göre al
                    $get = function($canonical) use ($row, $colFor) {
                        $col = $colFor[$canonical] ?? null;
                        if ($col && isset($row[$col])) return $row[$col];
                        return null;
                    };

                    $servis_fis_no = trim((string)$get('servis fiş no'));
                    $tarihRaw = $get('tarih');
                    $tarih = parse_date($tarihRaw);

                    $bildirilen_problem = $get('bildirilen problem');
                    $sube_adi = $get('şube adı');
                    $garanti = $get('garanti');

                    $iscilik_bedeli = parse_number($get('işçilik bedeli'));
                    $malzeme_bedeli = parse_number($get('malzeme bedeli'));
                    $kompresor_tamir = parse_number($get('kompresör tamir'));
                    $taseron_malzeme = parse_number($get('taşeron malzeme'));
                    $satinalinan_malzeme = parse_number($get('satın alınan malzeme'));
                    $evap_degisim = parse_number($get('evap değişim'));
                    $malzeme_bedeli2 = parse_number($get('malzeme bedeli 2'));
                    $toplam_fatura = parse_number($get('toplam fatura'));

                    // Eğer servis_fis_no boşsa atla (kimlik yok)
                    if ($servis_fis_no === '') {
                        $errors[] = "Satır $r: Servis Fiş No boş.";
                        continue;
                    }

                    // bind ve execute - bind_param requires variables (not expressions)
                    $bindOk = $stmt->bind_param(
                        $types,
                        $servis_fis_no, $tarih, $bildirilen_problem, $sube_adi, $garanti,
                        $iscilik_bedeli, $malzeme_bedeli, $kompresor_tamir, $taseron_malzeme,
                        $satinalinan_malzeme, $evap_degisim, $malzeme_bedeli2, $toplam_fatura
                    );

                    if ($bindOk === false) {
                        $errors[] = "Satır $r: bind_param hatası - " . $stmt->error;
                        continue;
                    }

                    if (!$stmt->execute()) {
                        $errors[] = "Satır $r: execute hatası - " . $stmt->error;
                        continue;
                    }

                    $inserted++;
                } // for rows

                if (count($errors) === 0) {
                    $conn->commit();
                    $message = "✅ Yükleme tamamlandı. Başarıyla eklenen: $inserted. Atlanan boş satır: $skipped.";
                } else {
                    $conn->rollback();
                    $message = "❌ Yükleme sırasında hatalar oluştu. Başarıyla eklenen: $inserted. Hata sayısı: " . count($errors);
                    // örnek 5 hata göster
                    $exampleErrors = array_slice($errors, 0, 5);
                }

                $stmt->close();
            }
        } catch (Exception $e) {
            if ($conn->in_transaction) $conn->rollback();
            $message = "Beklenmeyen hata: " . $e->getMessage();
        }
    }
}

// Silme işlemleri (aynı kod)
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $conn->query("DELETE FROM veriler WHERE id=$id");
    header("Location: admin.php");
    exit;
}

if (isset($_POST['delete_selected']) && !empty($_POST['selected'])) {
    $ids = implode(',', array_map('intval', $_POST['selected']));
    $conn->query("DELETE FROM veriler WHERE id IN ($ids)");
    $message = count($_POST['selected']) . " kayıt silindi!";
}

if (isset($_POST['delete_all'])) {
    $conn->query("TRUNCATE TABLE veriler");
    $message = "🚨 Tüm veriler silindi!";
}

// Verileri çek
$result = $conn->query("SELECT * FROM veriler ORDER BY id DESC");
$veriler = $result->fetch_all(MYSQLI_ASSOC);

// --- HTML arayüz ---
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>Admin Paneli - Excel Yönetimi</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background: #f4f6f8; font-family: Arial, sans-serif; }
.container { margin-top: 30px; }
.card { box-shadow: 0 4px 15px rgba(0,0,0,0.08); padding: 20px; border-radius: 10px; }
.file-input { border: 2px dashed #4CAF50; padding: 20px; text-align: center; border-radius: 8px; cursor: pointer; margin-bottom: 10px; }
.file-input:hover { background: #e8f5e9; }
</style>
</head>
<body>
<div class="container">
<div class="card">
    <h2 class="text-center">📊 Admin Paneli - Excel Yönetimi</h2>

    <?php if ($message): ?>
        <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <?php if (!empty($exampleErrors)): ?>
        <div class="alert alert-warning">
            <strong>Örnek hata (ilk 5):</strong>
            <ul>
                <?php foreach($exampleErrors as $er): ?>
                    <li><?php echo htmlspecialchars($er); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="mb-3">
        <label class="file-input">
            Excel Dosyası Yükle (.xlsx, .xls)
            <input type="file" name="excel" accept=".xlsx,.xls" style="display:none;" required>
        </label>
        <button type="submit" name="submit" class="btn btn-success mb-3">📥 Yükle</button>
    </form>

    <h4>Mevcut Veriler</h4>
    <input class="form-control mb-2" id="searchInput" placeholder="🔍 Ara...">

    <form method="post" id="bulkDeleteForm">
        <div class="mb-2">
            <button type="submit" name="delete_selected" class="btn btn-danger" onclick="return confirm('Seçili verileri silmek istiyor musunuz?')">🗑️ Seçili Sil</button>
            <button type="submit" name="delete_all" class="btn btn-warning" onclick="return confirm('Tüm veriler kalıcı olarak silinecek! Emin misiniz?')">⚠️ Tümünü Sil</button>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-hover table-sm align-middle">
                <thead class="table-light text-center">
                <tr>
                    <th><input type="checkbox" id="selectAll"></th>
                    <th>Servis Fiş No</th>
                    <th>Tarih</th>
                    <th>Bildirilen Problem</th>
                    <th>Şube Adı</th>
                    <th>Garanti</th>
                    <th>İşçilik Bedeli</th>
                    <th>Malzeme Bedeli</th>
                    <th>Kompresör Tamir</th>
                    <th>Taşeron Malzeme</th>
                    <th>Satın Alınan Malzeme</th>
                    <th>EVAP Değişim</th>
                    <th>Malzeme Bedeli 2</th>
                    <th>Toplam Fatura</th>
                    <th>İşlem</th>
                </tr>
                </thead>
                <tbody id="tableBody">
                <?php foreach ($veriler as $row): ?>
                    <tr>
                        <td><input type="checkbox" name="selected[]" value="<?= $row['id'] ?>"></td>
                        <td><?= htmlspecialchars($row['servis_fis_no']) ?></td>
                        <td><?= htmlspecialchars($row['tarih']) ?></td>
                        <td><?= htmlspecialchars($row['bildirilen_problem']) ?></td>
                        <td><?= htmlspecialchars($row['sube_adi']) ?></td>
                        <td><?= htmlspecialchars($row['garanti']) ?></td>
                        <td><?= $row['iscilik_bedeli'] ?></td>
                        <td><?= $row['malzeme_bedeli'] ?></td>
                        <td><?= $row['kompresor_tamir'] ?></td>
                        <td><?= $row['taseron_malzeme'] ?></td>
                        <td><?= $row['satinalinan_malzeme'] ?></td>
                        <td><?= $row['evap_degisim'] ?></td>
                        <td><?= $row['malzeme_bedeli2'] ?></td>
                        <td><?= $row['toplam_fatura'] ?></td>
                        <td><a class="btn btn-sm btn-danger" href="?delete=<?= $row['id'] ?>" onclick="return confirm('Silmek istiyor musunuz?')">Sil</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </form>

</div>
</div>

<script>
document.getElementById('searchInput').addEventListener('keyup', function(){
    const filter = this.value.toLowerCase();
    document.querySelectorAll('#tableBody tr').forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(filter) ? '' : 'none';
    });
});
document.getElementById('selectAll').addEventListener('change', function(){
    document.querySelectorAll('input[name="selected[]"]').forEach(cb => cb.checked = this.checked);
});
</script>
</body>
</html>
