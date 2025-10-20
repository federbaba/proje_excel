<?php
$host = 'localhost';
$db   = 'excel_db';
$user = 'root';
$pass = '';
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("DB Bağlantı Hatası: " . $conn->connect_error);


$result_all = $conn->query("SELECT * FROM veriler ORDER BY id DESC");
$veriler_all = $result_all->fetch_all(MYSQLI_ASSOC);


$result_total_all = $conn->query("SELECT SUM(toplam_fatura) AS genel_toplam FROM veriler");
$genel_toplam = $result_total_all->fetch_assoc()['genel_toplam'];

// ➤ Şubeye göre toplam fatura (20.000 TL üstü filtrelenmiş grafik için)
$result_chart = $conn->query("
    SELECT sube_adi, SUM(toplam_fatura) AS toplam
    FROM veriler 
    GROUP BY sube_adi
    HAVING toplam >= 20000
    ORDER BY toplam DESC
");
$chartData = $result_chart->fetch_all(MYSQLI_ASSOC);

// ➤ Grafikteki Toplam (20.000 Üstü Şubeler)
$grafik_toplam = array_sum(array_column($chartData, 'toplam'));

$conn->close();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>Şube Bazlı Toplam Fatura</title>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.5/css/jquery.dataTables.min.css">
<style>
body{font-family:Arial;background:#f2f2f2;margin:0;padding:20px}
.container{background:white;padding:20px;border-radius:8px}
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

<div class="container">

<h2>📊 Şube Bazlı Toplam Fatura</h2>
<p><b>✅ Genel Toplam (Tüm Şubeler): <?= number_format($genel_toplam,2,",",".") ?> TL</b></p>
<p><b>✅ Grafikteki Toplam (20.000 TL Üstü Şubeler): <?= number_format($grafik_toplam,2,",",".") ?> TL</b></p>

<canvas id="chart" height="120"></canvas>

<br><hr><br>

<h2>📋 Tüm Servis Verileri</h2>
<table id="table" class="display">
    <thead>
        <tr>
            <th>ID</th>
            <th>Servis Fiş No</th>
            <th>Şube Adı</th>
            <th>Tarih</th>
            <th>Bildirilen Problem</th>
            <th>Toplam Fatura</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach($veriler_all as $v): ?>
        <tr>
            <td><?= $v['id'] ?></td>
            <td><?= $v['servis_fis_no'] ?></td>
            <td><?= $v['sube_adi'] ?></td>
            <td><?= $v['tarih'] ?></td>
            <td><?= $v['bildirilen_problem'] ?></td>
            <td><?= number_format($v['toplam_fatura'],2,",",".") ?> TL</td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/jquery.dataTables.min.js"></script>
<script>
$('#table').DataTable({language:{url:"//cdn.datatables.net/plug-ins/1.13.5/i18n/tr.json"}});

new Chart(document.getElementById('chart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($chartData,'sube_adi')) ?>,
        datasets: [{
            label: "Toplam Fatura (TL)",
            data: <?= json_encode(array_column($chartData,'toplam')) ?>,
            backgroundColor: "rgba(75, 192, 192, 0.7)",
            borderColor: "rgba(75, 192, 192, 1)",
            borderWidth: 1
        }]
    },
    options: {
        plugins:{legend:{display:false}},
        responsive:true,
        scales:{y:{beginAtZero:true}}
    }
});
</script>

</body>
</html>
