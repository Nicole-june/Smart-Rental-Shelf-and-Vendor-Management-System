<?php

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/report_data.php';

requireRole('admin');

$type = $_GET['type'] ?? 'vendors';
$validTypes = ['vendors', 'inventory', 'rentals', 'payments', 'sales', 'occupancy'];
if (!in_array($type, $validTypes, true)) {
    $type = 'vendors';
}

$dateFrom = cleanInput($_GET['date_from'] ?? '');
$dateTo   = cleanInput($_GET['date_to'] ?? '');
$usesDateFilter = in_array($type, ['rentals', 'payments', 'sales'], true);

$report = buildReportData($pdo, $type, ['date_from' => $dateFrom, 'date_to' => $dateTo]);

$tabs = [
    'vendors'    => 'Vendor Report',
    'inventory'  => 'Inventory Report',
    'rentals'    => 'Rental Report',
    'payments'   => 'Payment Report',
    'sales'      => 'Sales Report',
    'occupancy'  => 'Occupancy Report',
];

// Build the print URL, carrying over current filters
$printParams = ['type' => $type];
if ($usesDateFilter) {
    $printParams['date_from'] = $dateFrom;
    $printParams['date_to']   = $dateTo;
}
$printUrl = 'reports_print.php?' . http_build_query($printParams);
$csvUrl   = 'reports_export_csv.php?' . http_build_query($printParams);

$pageTitle  = 'Reports';
$activePage = 'reports';
require_once __DIR__ . '/../includes/header.php';
?>

    <div class="sub-tabs">
        <?php foreach ($tabs as $key => $label): ?>
            <a href="reports.php?type=<?= e($key) ?>" class="sub-tab <?= $type === $key ? 'active' : '' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="page-toolbar">
        <?php if ($usesDateFilter): ?>
            <form method="GET" class="search-form">
                <input type="hidden" name="type" value="<?= e($type) ?>">
                <label class="text-muted" style="align-self:center;">From</label>
                <input type="date" name="date_from" value="<?= e($dateFrom) ?>" class="status-select">
                <label class="text-muted" style="align-self:center;">To</label>
                <input type="date" name="date_to" value="<?= e($dateTo) ?>" class="status-select">
                <button type="submit" class="btn btn-secondary">Filter</button>
                <?php if ($dateFrom !== '' || $dateTo !== ''): ?>
                    <a href="reports.php?type=<?= e($type) ?>" class="btn btn-link">Clear</a>
                <?php endif; ?>
            </form>
        <?php else: ?>
            <div></div>
        <?php endif; ?>

        <div style="display:flex; gap:10px;">
            <a href="<?= e($csvUrl) ?>" class="btn btn-secondary">Export CSV</a>
            <a href="<?= e($printUrl) ?>" target="_blank" class="btn btn-primary">Print / Save as PDF</a>
        </div>
    </div>

    <?php if ($report['summary']): ?>
        <div class="shelf-code-display"><?= e($report['summary']) ?></div>
    <?php endif; ?>

    <?php if (!empty($report['rows'])): ?>
        <div class="view-toggle">
            <button type="button" class="view-toggle-btn active" data-view="charts" onclick="switchReportView('charts')">📊 Chart View</button>
            <button type="button" class="view-toggle-btn" data-view="table" onclick="switchReportView('table')">📋 Table View</button>
        </div>
    <?php endif; ?>

    <?php if (!empty($report['charts']) && !empty($report['rows'])): ?>
        <div id="reportChartsView" class="charts-multi-grid">
            <?php foreach ($report['charts'] as $chart): ?>
                <?php if ($chart['kind'] === 'segmented' && !empty($chart['segments'])): ?>
                    <div class="chart-card">
                        <h3><?= e($chart['title']) ?></h3>
                        <div class="bar-chart">
                            <?php foreach ($chart['segments'] as $segment): ?>
                                <div class="bar-row">
                                    <span class="bar-label"><?= e($segment['label']) ?></span>
                                    <div class="bar-track">
                                        <div class="bar-fill <?= e($segment['class']) ?>" style="width: <?= e((string) $segment['percent']) ?>%;"></div>
                                    </div>
                                    <span class="bar-percent"><?= e((string) $segment['percent']) ?>%</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php elseif ($chart['kind'] === 'ranked' && !empty($chart['items'])): ?>
                    <div class="chart-card">
                        <h3><?= e($chart['title']) ?></h3>
                        <div class="bar-chart">
                            <?php foreach ($chart['items'] as $item): ?>
                                <div class="bar-row">
                                    <span class="bar-label"><?= e($item['label']) ?></span>
                                    <div class="bar-track">
                                        <div class="bar-fill bar-occupied" style="width: <?= e((string) $item['percent']) ?>%;"></div>
                                    </div>
                                    <span class="bar-percent"><?= e($item['value']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div id="reportTableView" class="table-card" style="<?= !empty($report['charts']) && !empty($report['rows']) ? 'display:none;' : '' ?>">
        <?php if (empty($report['rows'])): ?>
            <p class="chart-empty">No data found for this report.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <?php foreach ($report['columns'] as $col): ?>
                            <th><?= e($col) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['rows'] as $row): ?>
                        <tr>
                            <?php foreach ($row as $cell): ?>
                                <td><?= e((string) $cell) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <script>
        function switchReportView(view) {
            var chartsView = document.getElementById('reportChartsView');
            var tableView = document.getElementById('reportTableView');
            var buttons = document.querySelectorAll('.view-toggle-btn');

            buttons.forEach(function (btn) {
                btn.classList.toggle('active', btn.getAttribute('data-view') === view);
            });

            if (view === 'charts') {
                if (chartsView) chartsView.style.display = '';
                if (tableView) tableView.style.display = 'none';
            } else {
                if (chartsView) chartsView.style.display = 'none';
                if (tableView) tableView.style.display = '';
            }
        }
    </script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>