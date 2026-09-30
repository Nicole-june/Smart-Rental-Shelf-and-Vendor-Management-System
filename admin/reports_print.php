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

$report = buildReportData($pdo, $type, ['date_from' => $dateFrom, 'date_to' => $dateTo]);

$rangeLabel = '';
if ($dateFrom !== '' || $dateTo !== '') {
    $rangeLabel = 'Range: ' . ($dateFrom !== '' ? date('d M Y', strtotime($dateFrom)) : 'earliest')
        . ' — ' . ($dateTo !== '' ? date('d M Y', strtotime($dateTo)) : 'latest');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= e($report['title']) ?> | Smart Rental</title>
    <style>
        :root {
            --color-brown: #6b4a34;
            --color-brown-dark: #4a3223;
            --color-beige: #efe4d5;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #3a2e26;
            margin: 32px;
        }
        .print-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2px solid var(--color-brown);
            padding-bottom: 14px;
            margin-bottom: 18px;
        }
        .print-header h1 {
            margin: 0;
            font-size: 20px;
            color: var(--color-brown-dark);
        }
        .print-header p {
            margin: 2px 0 0;
            font-size: 12px;
            color: #8a7a6d;
        }
        .print-meta {
            font-size: 12px;
            color: #8a7a6d;
            margin-bottom: 16px;
        }
        .print-summary {
            background-color: var(--color-beige);
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 18px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        th, td {
            text-align: left;
            padding: 8px 10px;
            border-bottom: 1px solid #ddccb0;
        }
        th {
            background-color: var(--color-beige);
            color: var(--color-brown-dark);
        }
        .print-btn {
            display: inline-block;
            margin-bottom: 20px;
            padding: 10px 20px;
            background-color: var(--color-brown);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
        }
        @media print {
            .print-btn { display: none; }
            body { margin: 0.5cm; }
        }
    </style>
</head>
<body>

    <button class="print-btn" onclick="window.print()">Print This Report / Save as PDF</button>

    <div class="print-header">
        <div>
            <h1>Smart Rental — <?= e($report['title']) ?></h1>
            <p>Shelf & Vendor Management System</p>
        </div>
        <p>Generated: <?= date('d M Y, g:i A') ?></p>
    </div>

    <?php if ($rangeLabel !== ''): ?>
        <div class="print-meta"><?= e($rangeLabel) ?></div>
    <?php endif; ?>

    <?php if ($report['summary']): ?>
        <div class="print-summary"><?= e($report['summary']) ?></div>
    <?php endif; ?>

    <?php if (empty($report['rows'])): ?>
        <p>No data found for this report.</p>
    <?php else: ?>
        <table>
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

</body>
</html>