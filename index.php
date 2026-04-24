<?php
// ─── ENV ────────────────────────────────────────────────────────────────────
function loadEnv(string $path): void {
    if (!file_exists($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}
loadEnv(__DIR__ . '/.env');

$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbPort = $_ENV['DB_PORT'] ?? '5432';
$dbName = $_ENV['DB_NAME'] ?? 'sakai';
$dbUser = $_ENV['DB_USER'] ?? 'postgres';
$dbPass = $_ENV['DB_PASS'] ?? '';

// ─── DB ─────────────────────────────────────────────────────────────────────
$db = null; $dbError = null;
try {
    $db = new PDO("pgsql:host=$dbHost;port=$dbPort;dbname=$dbName", $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) { $dbError = $e->getMessage(); }

// ─── QUERIES ────────────────────────────────────────────────────────────────
$summary = $byResult = $byCategory = $accuracy = $details = [];
$progress = 0;

if ($db) {
    $summary = $db->query("
        SELECT
            COUNT(*)                                                                                 AS total,
            COUNT(dt_start)                                                                          AS dimulai,
            COUNT(dt_end)                                                                            AS selesai,
            COALESCE(ROUND(AVG(EXTRACT(EPOCH FROM (dt_end-dt_start)))::NUMERIC,3),0)                AS avg_detik,
            COALESCE(ROUND(MIN(EXTRACT(EPOCH FROM (dt_end-dt_start)))::NUMERIC,3),0)                AS min_detik,
            COALESCE(ROUND(MAX(EXTRACT(EPOCH FROM (dt_end-dt_start)))::NUMERIC,3),0)                AS max_detik,
            COALESCE(ROUND(PERCENTILE_CONT(0.5) WITHIN GROUP
                (ORDER BY EXTRACT(EPOCH FROM (dt_end-dt_start)))::NUMERIC,3),0)                     AS p50_detik
        FROM test_question WHERE id != 0
    ")->fetch(PDO::FETCH_ASSOC);

    $progress = ($summary['total'] > 0)
        ? (int) round($summary['selesai'] / $summary['total'] * 100) : 0;

    $byResult = $db->query("
        SELECT result, COUNT(*) AS jumlah,
            ROUND(AVG(EXTRACT(EPOCH FROM (dt_end-dt_start)))::NUMERIC,3) AS avg_detik,
            ROUND(MIN(EXTRACT(EPOCH FROM (dt_end-dt_start)))::NUMERIC,3) AS min_detik,
            ROUND(MAX(EXTRACT(EPOCH FROM (dt_end-dt_start)))::NUMERIC,3) AS max_detik
        FROM test_question
        WHERE id != 0 AND dt_end IS NOT NULL AND result IS NOT NULL
        GROUP BY result ORDER BY avg_detik
    ")->fetchAll(PDO::FETCH_ASSOC);

    $byCategory = $db->query("
        SELECT category, COUNT(*) AS total, COUNT(dt_end) AS selesai,
            COALESCE(ROUND(AVG(EXTRACT(EPOCH FROM (dt_end-dt_start)))::NUMERIC,3),0) AS avg_detik
        FROM test_question WHERE id != 0
        GROUP BY category ORDER BY category
    ")->fetchAll(PDO::FETCH_ASSOC);

    $accuracy = $db->query("
        SELECT expected_result, result AS actual_result, COUNT(*) AS jumlah
        FROM test_question WHERE id != 0 AND result IS NOT NULL
        GROUP BY expected_result, result ORDER BY expected_result, result
    ")->fetchAll(PDO::FETCH_ASSOC);

    $details = $db->query("
        SELECT id, question, answer, category, expected_result, result,
            ROUND(EXTRACT(EPOCH FROM (dt_end-dt_start))::NUMERIC,3) AS latency_detik,
            TO_CHAR(dt_start,'HH24:MI:SS') AS start_time,
            TO_CHAR(dt_end,  'HH24:MI:SS') AS end_time
        FROM test_question WHERE id != 0 ORDER BY id
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// ─── CHART & ACCURACY DATA ──────────────────────────────────────────────────
$resultColors = [
    'filtered'       => '#fd7e14',
    'out_of_context' => '#ffc107',
    'blocked'        => '#dc3545',
    'academic'       => '#198754',
    'study'          => '#0d6efd',
];

$chartResult  = ['labels' => [], 'counts' => [], 'colors' => []];
$chartLatency = ['labels' => [], 'avg'    => [], 'colors' => []];

foreach ($byResult as $r) {
    $lbl   = $r['result'] ?? 'unknown';
    $color = $resultColors[$lbl] ?? '#6c757d';
    $chartResult['labels'][]  = $lbl;
    $chartResult['counts'][]  = (int) $r['jumlah'];
    $chartResult['colors'][]  = $color;
    $chartLatency['labels'][] = $lbl;
    $chartLatency['avg'][]    = (float) $r['avg_detik'];
    $chartLatency['colors'][] = $color;
}

// Confusion matrix + accuracy per baris
$allJalur = ['filtered','out_of_context','blocked','academic','study'];
$matrix   = [];
$expectedRows = [];
foreach ($accuracy as $a) {
    $matrix[$a['expected_result']][$a['actual_result']] = (int) $a['jumlah'];
    $expectedRows[$a['expected_result']] = true;
}
ksort($expectedRows);

// Overall accuracy
$totalCorrect = 0; $totalDone = 0;
foreach ($matrix as $exp => $actuals) {
    foreach ($actuals as $act => $cnt) {
        $totalDone += $cnt;
        if ($exp === $act) $totalCorrect += $cnt;
    }
}
$overallAccuracy = $totalDone > 0 ? round($totalCorrect / $totalDone * 100, 1) : 0;

// Per-row accuracy
$rowAccuracy = [];
foreach (array_keys($expectedRows) as $exp) {
    $rowTotal   = array_sum($matrix[$exp] ?? []);
    $rowCorrect = $matrix[$exp][$exp] ?? 0;
    $rowAccuracy[$exp] = $rowTotal > 0 ? round($rowCorrect / $rowTotal * 100, 1) : 0;
}

// QA map untuk modal (full text)
$qaMap = [];
foreach ($details as $d) {
    $qaMap[(int)$d['id']] = [
        'question' => $d['question'] ?? '',
        'answer'   => $d['answer']   ?? '',
        'category' => $d['category'] ?? '',
        'expected' => $d['expected_result'] ?? '',
        'result'   => $d['result'] ?? '',
        'latency'  => $d['latency_detik'] ?? '',
    ];
}
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Sakai Performance Dashboard</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
  <style>
    body { background:#f1f3f5; font-size:.9rem; }
    .page-header { background:#fff; border-bottom:1px solid #dee2e6; }
    .stat-card { border-left:4px solid; background:#fff; border-radius:6px; }
    .stat-card.c-blue   { border-color:#0d6efd; }
    .stat-card.c-green  { border-color:#198754; }
    .stat-card.c-orange { border-color:#fd7e14; }
    .stat-card.c-cyan   { border-color:#0dcaf0; }
    .stat-card.c-gray   { border-color:#6c757d; }
    .stat-value { font-size:1.9rem; font-weight:700; line-height:1.1; }
    .stat-label { font-size:.72rem; color:#6c757d; text-transform:uppercase; letter-spacing:.06em; }
    .card-box { background:#fff; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,.07); }
    .badge-result { font-size:.72rem; padding:.25em .55em; border-radius:4px; color:#fff; display:inline-block; }
    .badge-result.dark-text { color:#000 !important; }
    .accuracy-badge { font-size:1.1rem; font-weight:700; }
    pre.answer-pre { white-space:pre-wrap; word-break:break-word; font-family:inherit; font-size:.9rem; background:#f8f9fa; border-radius:6px; padding:12px; max-height:300px; overflow-y:auto; }
  </style>
</head>
<body>

<div class="page-header px-4 py-3 mb-4 d-flex justify-content-between align-items-center">
  <div>
    <h5 class="mb-0 fw-bold"><i class="bi bi-speedometer2 text-primary me-2"></i>Sakai Performance Test</h5>
    <small class="text-muted">Departemen Teknik Komputer ITS — Latency Benchmark</small>
  </div>
  <a href="?" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</a>
</div>

<div class="container-fluid px-4">
<?php if ($dbError): ?>
  <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($dbError) ?></div>
<?php else: ?>

  <!-- Progress -->
  <?php if (!empty($summary['total'])): ?>
  <div class="mb-4">
    <div class="d-flex justify-content-between small text-muted mb-1">
      <span><?= number_format($summary['selesai']) ?> / <?= number_format($summary['total']) ?> pertanyaan diproses</span>
      <span class="fw-semibold"><?= $progress ?>%</span>
    </div>
    <div class="progress" style="height:6px">
      <div class="progress-bar bg-success" style="width:<?= $progress ?>%"></div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Stat cards -->
  <div class="row g-3 mb-4">
    <?php $cards = [
      ['label'=>'Total Test',   'value'=>number_format($summary['total']   ?? 0), 'unit'=>'',  'class'=>'c-blue'],
      ['label'=>'Selesai',      'value'=>number_format($summary['selesai'] ?? 0), 'unit'=>'',  'class'=>'c-green'],
      ['label'=>'Avg Latency',  'value'=>$summary['avg_detik'] ?? 0,               'unit'=>'s', 'class'=>'c-orange'],
      ['label'=>'P50 (Median)', 'value'=>$summary['p50_detik'] ?? 0,               'unit'=>'s', 'class'=>'c-cyan'],
      ['label'=>'Min Latency',  'value'=>$summary['min_detik'] ?? 0,               'unit'=>'s', 'class'=>'c-gray'],
    ];
    foreach ($cards as $c): ?>
    <div class="col-6 col-sm-4 col-xl-2">
      <div class="stat-card <?= $c['class'] ?> p-3 h-100">
        <div class="stat-value"><?= $c['value'] ?><small class="fs-6 fw-normal text-muted"><?= $c['unit'] ? ' '.$c['unit'] : '' ?></small></div>
        <div class="stat-label mt-1"><?= $c['label'] ?></div>
      </div>
    </div>
    <?php endforeach; ?>
    <!-- Accuracy card -->
    <div class="col-6 col-sm-4 col-xl-2">
      <div class="stat-card c-green p-3 h-100">
        <div class="stat-value text-success"><?= $overallAccuracy ?><small class="fs-6 fw-normal text-muted"> %</small></div>
        <div class="stat-label mt-1">Akurasi Klasifikasi</div>
      </div>
    </div>
  </div>

  <!-- Charts -->
  <div class="row g-3 mb-4">
    <?php if (!empty($byResult) || !empty($byCategory)): ?>
      <div class="col-lg-6">
      <div class="card-box p-3">
        <h6 class="fw-semibold mb-3">Breakdown per Jalur</h6>
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-light">
            <tr><th>Jalur</th><th class="text-end">Jml</th><th class="text-end">Avg (s)</th><th class="text-end">Min</th><th class="text-end">Max</th></tr>
          </thead>
          <tbody>
          <?php foreach ($byResult as $r):
            $color  = $resultColors[$r['result']] ?? '#6c757d';
            $isDark = $r['result'] === 'out_of_context';
          ?>
          <tr>
            <td><span class="badge-result <?= $isDark?'dark-text':'' ?>" style="background:<?= $color ?>"><?= htmlspecialchars($r['result']??'—') ?></span></td>
            <td class="text-end"><?= number_format($r['jumlah']) ?></td>
            <td class="text-end fw-semibold"><?= $r['avg_detik'] ?></td>
            <td class="text-end text-muted"><?= $r['min_detik'] ?></td>
            <td class="text-end text-muted"><?= $r['max_detik'] ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
    <!-- Bar: avg latency per jalur -->
    <div class="col-md-6">
      <div class="card-box p-3 h-100">
        <h6 class="fw-semibold mb-3">Avg Latency per Jalur <small class="text-muted fw-normal">(detik)</small></h6>
        <?php if (!empty($chartLatency['labels'])): ?>
        <canvas id="chartLatency" style="max-height:230px"></canvas>
        <?php else: ?>
        <p class="text-muted text-center py-5">Belum ada data</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Breakdown tables -->
  <div class="row g-3 mb-4">
    <!-- Donut: distribusi hasil + akurasi -->
    <div class="col-lg-6">
      <div class="card-box p-3 h-100">
        <div class="d-flex justify-content-between align-items-start mb-3">
          <h6 class="fw-semibold mb-0">Distribusi Hasil</h6>
          <?php if ($overallAccuracy > 0): ?>
          <div class="text-end">
            <div class="accuracy-badge text-success"><?= $overallAccuracy ?>%</div>
            <div style="font-size:.7rem;color:#6c757d">jawaban benar</div>
          </div>
          <?php endif; ?>
        </div>
        <?php if (!empty($chartResult['labels'])): ?>
        <canvas id="chartResult" style="max-height:230px"></canvas>
        <?php else: ?>
        <p class="text-muted text-center py-5">Belum ada data</p>
        <?php endif; ?>
      </div>
    </div>
    <?php if (!empty($byResult) || !empty($byCategory)): ?>
      <div class="col-lg-6">
        <div class="card-box p-3">
          <h6 class="fw-semibold mb-3">Breakdown per Kategori</h6>
          <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
              <tr><th>Kategori</th><th class="text-end">Total</th><th class="text-end">Selesai</th><th class="text-end">Avg (s)</th></tr>
            </thead>
            <tbody>
            <?php foreach ($byCategory as $c): ?>
            <tr>
              <td><?= htmlspecialchars($c['category']??'—') ?></td>
              <td class="text-end"><?= $c['total'] ?></td>
              <td class="text-end"><?= $c['selesai'] ?></td>
              <td class="text-end fw-semibold"><?= $c['avg_detik'] ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- Confusion matrix -->
  <?php if (!empty($accuracy)): ?>
  <div class="card-box p-3 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h6 class="fw-semibold mb-0">Akurasi Klasifikasi — Expected vs Actual
        <small class="text-muted fw-normal">(hijau = benar, merah = salah)</small>
      </h6>
      <span class="badge bg-success fs-6"><?= $overallAccuracy ?>% benar</span>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-bordered text-center mb-0" style="width:auto">
        <thead class="table-light">
          <tr>
            <th class="text-start" style="min-width:130px">Expected ↓ / Actual →</th>
            <?php foreach ($allJalur as $aj): ?>
            <th class="small" style="min-width:100px"><?= $aj ?></th>
            <?php endforeach; ?>
            <th class="text-end" style="min-width:90px">% Benar</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach (array_keys($expectedRows) as $exp):
          $color  = $resultColors[$exp] ?? '#6c757d';
          $isDark = $exp === 'out_of_context';
          $pct    = $rowAccuracy[$exp] ?? 0;
          $pctCls = $pct >= 90 ? 'text-success fw-bold' : ($pct >= 70 ? 'text-warning fw-bold' : 'text-danger fw-bold');
        ?>
        <tr>
          <td class="text-start">
            <span class="badge-result <?= $isDark?'dark-text':'' ?>" style="background:<?= $color ?>"><?= $exp ?></span>
          </td>
          <?php foreach ($allJalur as $aj):
            $val     = $matrix[$exp][$aj] ?? 0;
            $cellCls = $val > 0 ? ($exp===$aj ? 'table-success fw-bold' : 'table-danger') : '';
          ?>
          <td class="<?= $cellCls ?>"><?= $val > 0 ? number_format($val) : '<span class="text-muted">·</span>' ?></td>
          <?php endforeach; ?>
          <td class="<?= $pctCls ?>"><?= $pct ?>%</td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- Detail table -->
  <div class="card-box p-3 mb-4">
    <h6 class="fw-semibold mb-3">Detail Pertanyaan
      <small class="text-muted fw-normal">(baris kuning = salah klasifikasi)</small>
    </h6>
    <div class="table-responsive">
      <table id="tblDetail" class="table table-sm table-hover align-middle w-100">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Pertanyaan</th>
            <th>Jawaban</th>
            <th>Kategori</th>
            <th>Expected</th>
            <th>Actual</th>
            <th class="text-end">Latency (s)</th>
            <th>Mulai</th>
            <th>Selesai</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($details as $d):
          $result   = $d['result']          ?? null;
          $expected = $d['expected_result'] ?? null;
          $color    = $result   ? ($resultColors[$result]   ?? '#6c757d') : null;
          $ecolor   = $expected ? ($resultColors[$expected] ?? '#6c757d') : null;
          $mismatch = $result && $expected && $result !== $expected;
          $isDarkR  = $result   === 'out_of_context';
          $isDarkE  = $expected === 'out_of_context';
          $answerShort = mb_strimwidth(str_replace(["\n","\r"], ' ', $d['answer'] ?? ''), 0, 80, '…');
        ?>
        <tr class="<?= $mismatch ? 'table-warning' : '' ?>">
          <td class="text-muted"><?= $d['id'] ?></td>
          <td style="max-width:200px" class="small">
            <?= htmlspecialchars(mb_strimwidth($d['question'] ?? '', 0, 60, '…')) ?>
          </td>
          <td style="max-width:220px" class="small text-muted">
            <?= htmlspecialchars($answerShort ?: '—') ?>
          </td>
          <td class="small text-muted"><?= htmlspecialchars($d['category'] ?? '—') ?></td>
          <td>
            <?php if ($ecolor): ?>
            <span class="badge-result <?= $isDarkE?'dark-text':'' ?>" style="background:<?= $ecolor ?>"><?= htmlspecialchars($expected) ?></span>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td>
            <?php if ($color): ?>
            <span class="badge-result <?= $isDarkR?'dark-text':'' ?>" style="background:<?= $color ?>"><?= htmlspecialchars($result) ?></span>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td class="text-end small">
            <?php if ($d['latency_detik'] !== null):
              $lat     = (float) $d['latency_detik'];
              $latCls  = $lat > 2 ? 'text-danger fw-semibold' : ($lat > 1 ? 'text-warning' : '');
            ?><span class="<?= $latCls ?>"><?= $d['latency_detik'] ?></span>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td class="small text-muted"><?= htmlspecialchars($d['start_time'] ?? '—') ?></td>
          <td class="small text-muted"><?= htmlspecialchars($d['end_time']   ?? '—') ?></td>
          <td>
            <button class="btn btn-sm btn-outline-secondary py-0 btn-view" data-id="<?= $d['id'] ?>">
              <i class="bi bi-eye"></i>
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php endif; ?>
</div>

<!-- Modal detail Q&A -->
<div class="modal fade" id="modalQA" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title fw-bold"><i class="bi bi-chat-left-text me-2"></i>Detail Q&A <span id="mdId" class="text-muted fw-normal"></span></h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <div class="d-flex gap-2 mb-2 align-items-center">
            <span class="fw-semibold small text-uppercase text-muted">Pertanyaan</span>
            <span id="mdCategory" class="badge bg-secondary" style="font-size:.7rem"></span>
            <span id="mdExpected" class="badge" style="font-size:.7rem"></span>
            <span id="mdResult"   class="badge" style="font-size:.7rem"></span>
            <span id="mdLatency" class="ms-auto small text-muted"></span>
          </div>
          <pre class="answer-pre" id="mdQuestion"></pre>
        </div>
        <div>
          <div class="fw-semibold small text-uppercase text-muted mb-2">Jawaban AI</div>
          <pre class="answer-pre" id="mdAnswer"></pre>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script>
const resultColors = <?= json_encode($resultColors) ?>;
const qaMap = <?= json_encode($qaMap, JSON_UNESCAPED_UNICODE) ?>;

<?php if (!empty($chartResult['labels'])): ?>
new Chart(document.getElementById('chartResult'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode($chartResult['labels']) ?>,
    datasets: [{ data: <?= json_encode($chartResult['counts']) ?>,
      backgroundColor: <?= json_encode($chartResult['colors']) ?>, borderWidth:2, borderColor:'#fff' }]
  },
  options: {
    cutout: '58%',
    plugins: { legend: { position:'bottom', labels:{ boxWidth:12, font:{size:12} } } }
  }
});
<?php endif; ?>

<?php if (!empty($chartLatency['labels'])): ?>
new Chart(document.getElementById('chartLatency'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($chartLatency['labels']) ?>,
    datasets: [{
      label: 'Avg (s)',
      data: <?= json_encode($chartLatency['avg']) ?>,
      backgroundColor: <?= json_encode(array_map(fn($c) => $c.'cc', $chartLatency['colors'])) ?>,
      borderColor:     <?= json_encode($chartLatency['colors']) ?>,
      borderWidth: 1, borderRadius: 4
    }]
  },
  options: {
    indexAxis: 'y',
    plugins: { legend: { display:false } },
    scales: {
      x: { beginAtZero:true, title:{ display:true, text:'Detik' }, grid:{ color:'#e9ecef' } },
      y: { grid:{ display:false } }
    }
  }
});
<?php endif; ?>

// DataTable
$('#tblDetail').DataTable({
  pageLength: 25,
  order: [[0,'asc']],
  columnDefs: [{ orderable:false, targets:[1,2,9] }],
  language: {
    search:'Cari:', lengthMenu:'Tampilkan _MENU_ baris',
    info:'Menampilkan _START_–_END_ dari _TOTAL_ baris',
    infoEmpty:'Tidak ada data', paginate:{ previous:'‹', next:'›' }
  }
});

// Modal Q&A
const modalQA = new bootstrap.Modal('#modalQA');
$('#tblDetail').on('click', '.btn-view', function () {
  const id = parseInt($(this).data('id'));
  const d  = qaMap[id];
  if (!d) return;

  document.getElementById('mdId').textContent = '#' + id;
  document.getElementById('mdQuestion').textContent = d.question || '—';
  document.getElementById('mdAnswer').textContent   = d.answer   || '(belum ada jawaban)';
  document.getElementById('mdCategory').textContent = d.category || '—';
  document.getElementById('mdLatency').textContent  = d.latency ? d.latency + ' s' : '';

  const expEl = document.getElementById('mdExpected');
  expEl.textContent = 'exp: ' + (d.expected || '—');
  expEl.style.background = resultColors[d.expected] || '#6c757d';

  const resEl = document.getElementById('mdResult');
  resEl.textContent = 'actual: ' + (d.result || '—');
  resEl.style.background = resultColors[d.result] || '#adb5bd';

  modalQA.show();
});
</script>
</body>
</html>
