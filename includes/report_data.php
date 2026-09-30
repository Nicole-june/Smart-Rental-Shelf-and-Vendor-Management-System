<?php

function reportPct(int $part, int $total): float
{
    return $total > 0 ? round(($part / $total) * 100, 1) : 0.0;
}

/**
 * Turns a list of [label => numericValue] pairs into a ranked chart's
 * 'items' array, scaling bar width relative to the largest value.
 */
function rankedItems(array $labelToValue, callable $formatValue, int $limit = 8): array
{
    arsort($labelToValue);
    $top = array_slice($labelToValue, 0, $limit, true);
    $max = !empty($top) ? max($top) : 0;
    $items = [];
    foreach ($top as $label => $value) {
        $items[] = [
            'label'   => $label,
            'value'   => $formatValue($value),
            'percent' => $max > 0 ? round(($value / $max) * 100, 1) : 0,
        ];
    }
    return $items;
}

function buildReportData(PDO $pdo, string $type, array $filters): array
{
    $dateFrom = $filters['date_from'] ?? '';
    $dateTo   = $filters['date_to'] ?? '';

    switch ($type) {

        case 'vendors':
            $stmt = $pdo->query(
                "SELECT v.business_name, u.full_name, u.email, v.phone, v.status, v.date_joined,
                        (SELECT COUNT(*) FROM rentals r WHERE r.vendor_id = v.vendor_id AND r.rental_status = 'Active') AS active_rentals,
                        (SELECT COUNT(*) FROM products p WHERE p.vendor_id = v.vendor_id) AS product_count
                 FROM vendors v
                 INNER JOIN users u ON u.user_id = v.user_id
                 ORDER BY v.business_name ASC"
            );
            $data = $stmt->fetchAll();
            $rows = array_map(fn($v) => [
                $v['business_name'], $v['full_name'], $v['email'], $v['phone'],
                $v['status'], date('d M Y', strtotime($v['date_joined'])),
                (string) $v['active_rentals'], (string) $v['product_count'],
            ], $data);
            $total = count($data);
            $activeCount = count(array_filter($data, fn($v) => $v['status'] === 'Active'));

            $productsByVendor = [];
            $rentalsByVendor = [];
            foreach ($data as $v) {
                $productsByVendor[$v['business_name']] = (int) $v['product_count'];
                $rentalsByVendor[$v['business_name']] = (int) $v['active_rentals'];
            }

            return [
                'title'   => 'Vendor Report',
                'columns' => ['Business Name', 'Contact', 'Email', 'Phone', 'Status', 'Date Joined', 'Active Rentals', 'Products'],
                'rows'    => $rows,
                'summary' => count($rows) . ' vendor(s) total.',
                'charts'  => [
                    [
                        'kind' => 'segmented', 'title' => 'Vendor Status',
                        'segments' => [
                            ['label' => 'Active',   'percent' => reportPct($activeCount, $total),          'class' => 'bar-available'],
                            ['label' => 'Inactive', 'percent' => reportPct($total - $activeCount, $total), 'class' => 'bar-out-of-stock'],
                        ],
                    ],
                    [
                        'kind' => 'ranked', 'title' => 'Products per Vendor (Top 8)',
                        'items' => rankedItems($productsByVendor, fn($v) => $v . ' product(s)'),
                    ],
                    [
                        'kind' => 'ranked', 'title' => 'Active Rentals per Vendor (Top 8)',
                        'items' => rankedItems($rentalsByVendor, fn($v) => $v . ' rental(s)'),
                    ],
                ],
            ];

        case 'inventory':
            $stmt = $pdo->query(
                "SELECT p.product_name, v.business_name, c.category_name, p.quantity, p.minimum_stock,
                        p.buying_price, p.selling_price, p.status
                 FROM products p
                 INNER JOIN vendors v ON v.vendor_id = p.vendor_id
                 LEFT JOIN categories c ON c.category_id = p.category_id
                 ORDER BY p.product_name ASC"
            );
            $data = $stmt->fetchAll();
            $rows = array_map(fn($p) => [
                $p['product_name'], $p['business_name'], $p['category_name'] ?? '—',
                (string) $p['quantity'], (string) $p['minimum_stock'],
                'KES ' . number_format((float) $p['buying_price'], 2),
                'KES ' . number_format((float) $p['selling_price'], 2),
                $p['status'],
            ], $data);
            $lowCount = count(array_filter($data, fn($p) => $p['status'] !== 'Available'));
            $total = count($data);
            $availableCount = count(array_filter($data, fn($p) => $p['status'] === 'Available'));
            $lowStockCount = count(array_filter($data, fn($p) => $p['status'] === 'Low Stock'));
            $outOfStockCount = count(array_filter($data, fn($p) => $p['status'] === 'Out of Stock'));

            $qtyByProduct = [];
            foreach ($data as $p) {
                $label = $p['product_name'] . ' (' . $p['business_name'] . ')';
                $qtyByProduct[$label] = (int) $p['quantity'];
            }
            asort($qtyByProduct);
            $lowestStock = array_slice($qtyByProduct, 0, 8, true);
            $maxQty = !empty($qtyByProduct) ? max($qtyByProduct) : 1;
            $lowestStockItems = [];
            foreach ($lowestStock as $label => $qty) {
                $lowestStockItems[] = ['label' => $label, 'value' => $qty . ' units', 'percent' => $maxQty > 0 ? round(($qty / $maxQty) * 100, 1) : 0];
            }

            return [
                'title'   => 'Inventory Report',
                'columns' => ['Product', 'Vendor', 'Category', 'Qty', 'Min Stock', 'Buying Price', 'Selling Price', 'Status'],
                'rows'    => $rows,
                'summary' => count($rows) . ' product(s) total, ' . $lowCount . ' need attention (Low/Out of Stock).',
                'charts'  => [
                    [
                        'kind' => 'segmented', 'title' => 'Inventory Status',
                        'segments' => [
                            ['label' => 'Available',    'percent' => reportPct($availableCount, $total), 'class' => 'bar-available'],
                            ['label' => 'Low Stock',    'percent' => reportPct($lowStockCount, $total),   'class' => 'bar-low-stock'],
                            ['label' => 'Out of Stock', 'percent' => reportPct($outOfStockCount, $total), 'class' => 'bar-out-of-stock'],
                        ],
                    ],
                    [
                        'kind' => 'ranked', 'title' => 'Lowest Stock Levels (Top 8)',
                        'items' => $lowestStockItems,
                    ],
                ],
            ];

        case 'rentals':
            $sql = "SELECT r.start_date, r.end_date, r.monthly_rent, r.rental_status, r.payment_status,
                           v.business_name, s.shelf_code
                    FROM rentals r
                    INNER JOIN vendors v ON v.vendor_id = r.vendor_id
                    INNER JOIN shelves s ON s.shelf_id = r.shelf_id
                    WHERE 1 = 1";
            $params = [];
            if ($dateFrom !== '') { $sql .= " AND r.start_date >= :date_from"; $params['date_from'] = $dateFrom; }
            if ($dateTo !== '')   { $sql .= " AND r.start_date <= :date_to"; $params['date_to'] = $dateTo; }
            $sql .= " ORDER BY r.start_date DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $data = $stmt->fetchAll();
            $rows = array_map(fn($r) => [
                $r['business_name'], $r['shelf_code'],
                date('d M Y', strtotime($r['start_date'])), date('d M Y', strtotime($r['end_date'])),
                'KES ' . number_format((float) $r['monthly_rent'], 2),
                $r['rental_status'], $r['payment_status'],
            ], $data);

            $rentByVendor = [];
            foreach ($data as $r) {
                $rentByVendor[$r['business_name']] = ($rentByVendor[$r['business_name']] ?? 0) + (float) $r['monthly_rent'];
            }

            return [
                'title'   => 'Rental Report',
                'columns' => ['Vendor', 'Shelf', 'Start Date', 'End Date', 'Monthly Rent', 'Rental Status', 'Payment Status'],
                'rows'    => $rows,
                'summary' => count($rows) . ' rental(s) in this range.',
                'charts'  => [
                    [
                        'kind' => 'segmented', 'title' => 'Rental Status Breakdown',
                        'segments' => [
                            ['label' => 'Active',      'percent' => reportPct(count(array_filter($data, fn($r) => $r['rental_status'] === 'Active')), count($data)),      'class' => 'bar-available'],
                            ['label' => 'Expired',     'percent' => reportPct(count(array_filter($data, fn($r) => $r['rental_status'] === 'Expired')), count($data)),     'class' => 'bar-low-stock'],
                            ['label' => 'Terminated',  'percent' => reportPct(count(array_filter($data, fn($r) => $r['rental_status'] === 'Terminated')), count($data)),  'class' => 'bar-out-of-stock'],
                        ],
                    ],
                    [
                        'kind' => 'ranked', 'title' => 'Monthly Rent by Vendor (Top 8)',
                        'items' => rankedItems($rentByVendor, fn($v) => 'KES ' . number_format($v, 2)),
                    ],
                ],
            ];

        case 'payments':
            $sql = "SELECT p.payment_code, v.business_name, s.shelf_code, p.amount, p.payment_method,
                           p.payment_date, p.payment_status
                    FROM payments p
                    INNER JOIN vendors v ON v.vendor_id = p.vendor_id
                    INNER JOIN rentals r ON r.rental_id = p.rental_id
                    INNER JOIN shelves s ON s.shelf_id = r.shelf_id
                    WHERE 1 = 1";
            $params = [];
            if ($dateFrom !== '') { $sql .= " AND p.payment_date >= :date_from"; $params['date_from'] = $dateFrom; }
            if ($dateTo !== '')   { $sql .= " AND p.payment_date <= :date_to"; $params['date_to'] = $dateTo; }
            $sql .= " ORDER BY p.payment_date DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $data = $stmt->fetchAll();
            $rows = array_map(fn($p) => [
                $p['payment_code'], $p['business_name'], $p['shelf_code'],
                'KES ' . number_format((float) $p['amount'], 2), $p['payment_method'],
                $p['payment_date'] ? date('d M Y', strtotime($p['payment_date'])) : '—',
                $p['payment_status'],
            ], $data);
            $confirmedTotal = array_sum(array_map(
                fn($p) => $p['payment_status'] === 'Confirmed' ? (float) $p['amount'] : 0,
                $data
            ));

            $byDate = [];
            foreach ($data as $p) {
                if ($p['payment_status'] === 'Confirmed' && $p['payment_date']) {
                    $day = date('d M', strtotime($p['payment_date']));
                    $byDate[$day] = ($byDate[$day] ?? 0) + (float) $p['amount'];
                }
            }
            $byDate = array_slice($byDate, -10, null, true);
            $maxDay = !empty($byDate) ? max($byDate) : 1;
            $dateItems = [];
            foreach ($byDate as $day => $amount) {
                $dateItems[] = ['label' => $day, 'value' => 'KES ' . number_format($amount, 2), 'percent' => $maxDay > 0 ? round(($amount / $maxDay) * 100, 1) : 0];
            }

            $amountByVendor = [];
            foreach ($data as $p) {
                if ($p['payment_status'] === 'Confirmed') {
                    $amountByVendor[$p['business_name']] = ($amountByVendor[$p['business_name']] ?? 0) + (float) $p['amount'];
                }
            }

            return [
                'title'   => 'Payment Report',
                'columns' => ['Payment Code', 'Vendor', 'Shelf', 'Amount', 'Method', 'Date', 'Status'],
                'rows'    => $rows,
                'summary' => count($rows) . ' payment(s). Total confirmed: KES ' . number_format($confirmedTotal, 2) . '.',
                'charts'  => [
                    [
                        'kind' => 'segmented', 'title' => 'Payment Status Breakdown',
                        'segments' => [
                            ['label' => 'Confirmed', 'percent' => reportPct(count(array_filter($data, fn($p) => $p['payment_status'] === 'Confirmed')), count($data)), 'class' => 'bar-available'],
                            ['label' => 'Pending',   'percent' => reportPct(count(array_filter($data, fn($p) => $p['payment_status'] === 'Pending')), count($data)),   'class' => 'bar-low-stock'],
                            ['label' => 'Rejected',  'percent' => reportPct(count(array_filter($data, fn($p) => $p['payment_status'] === 'Rejected')), count($data)),  'class' => 'bar-out-of-stock'],
                        ],
                    ],
                    [
                        'kind' => 'ranked', 'title' => 'Confirmed Amount by Vendor (Top 8)',
                        'items' => rankedItems($amountByVendor, fn($v) => 'KES ' . number_format($v, 2)),
                    ],
                    [
                        'kind' => 'ranked', 'title' => 'Confirmed Amount by Day',
                        'items' => $dateItems,
                    ],
                ],
            ];

        case 'sales':
            $sql = "SELECT s.sale_date, v.business_name, pr.product_name, s.quantity_sold, s.total_amount
                    FROM sales s
                    INNER JOIN products pr ON pr.product_id = s.product_id
                    INNER JOIN vendors v ON v.vendor_id = s.vendor_id
                    WHERE 1 = 1";
            $params = [];
            if ($dateFrom !== '') { $sql .= " AND s.sale_date >= :date_from"; $params['date_from'] = $dateFrom; }
            if ($dateTo !== '')   { $sql .= " AND s.sale_date <= :date_to"; $params['date_to'] = $dateTo . ' 23:59:59'; }
            $sql .= " ORDER BY s.sale_date DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $data = $stmt->fetchAll();
            $rows = array_map(fn($s) => [
                date('d M Y, g:i A', strtotime($s['sale_date'])), $s['business_name'], $s['product_name'],
                (string) $s['quantity_sold'], 'KES ' . number_format((float) $s['total_amount'], 2),
            ], $data);
            $totalRevenue = array_sum(array_column($data, 'total_amount'));

            $revenueByVendor = [];
            $revenueByDay = [];
            $unitsByProduct = [];
            foreach ($data as $s) {
                $revenueByVendor[$s['business_name']] = ($revenueByVendor[$s['business_name']] ?? 0) + (float) $s['total_amount'];
                $day = date('d M', strtotime($s['sale_date']));
                $revenueByDay[$day] = ($revenueByDay[$day] ?? 0) + (float) $s['total_amount'];
                $unitsByProduct[$s['product_name']] = ($unitsByProduct[$s['product_name']] ?? 0) + (int) $s['quantity_sold'];
            }
            $revenueByDay = array_slice($revenueByDay, -10, null, true);
            $maxDay = !empty($revenueByDay) ? max($revenueByDay) : 1;
            $dayItems = [];
            foreach ($revenueByDay as $day => $amount) {
                $dayItems[] = ['label' => $day, 'value' => 'KES ' . number_format($amount, 2), 'percent' => $maxDay > 0 ? round(($amount / $maxDay) * 100, 1) : 0];
            }

            return [
                'title'   => 'Sales Report',
                'columns' => ['Date', 'Vendor', 'Product', 'Qty Sold', 'Total Amount'],
                'rows'    => $rows,
                'summary' => count($rows) . ' sale(s). Total revenue: KES ' . number_format((float) $totalRevenue, 2) . '.',
                'charts'  => [
                    [
                        'kind' => 'ranked', 'title' => 'Top Vendors by Revenue',
                        'items' => rankedItems($revenueByVendor, fn($v) => 'KES ' . number_format($v, 2)),
                    ],
                    [
                        'kind' => 'ranked', 'title' => 'Top Products by Units Sold',
                        'items' => rankedItems($unitsByProduct, fn($v) => $v . ' units'),
                    ],
                    [
                        'kind' => 'ranked', 'title' => 'Revenue by Day',
                        'items' => $dayItems,
                    ],
                ],
            ];

        case 'occupancy':
            $stmt = $pdo->query(
                "SELECT s.shelf_code, s.building_name, s.floor, s.location, s.status, v.business_name
                 FROM shelves s
                 LEFT JOIN rentals r ON r.shelf_id = s.shelf_id AND r.rental_status = 'Active'
                 LEFT JOIN vendors v ON v.vendor_id = r.vendor_id
                 ORDER BY s.building_name ASC, s.floor ASC, s.shelf_code ASC"
            );
            $data = $stmt->fetchAll();
            $rows = array_map(fn($s) => [
                $s['shelf_code'], $s['building_name'], (string) $s['floor'], $s['location'],
                $s['status'], $s['business_name'] ?? '—',
            ], $data);
            $occupied = count(array_filter($data, fn($s) => $s['status'] === 'Occupied'));
            $available = count(array_filter($data, fn($s) => $s['status'] === 'Available'));
            $maintenance = count(array_filter($data, fn($s) => $s['status'] === 'Maintenance'));

            $occupiedByBuilding = [];
            $totalByBuilding = [];
            foreach ($data as $s) {
                $b = $s['building_name'];
                $totalByBuilding[$b] = ($totalByBuilding[$b] ?? 0) + 1;
                if ($s['status'] === 'Occupied') {
                    $occupiedByBuilding[$b] = ($occupiedByBuilding[$b] ?? 0) + 1;
                }
            }
            $buildingItems = [];
            foreach ($totalByBuilding as $building => $total) {
                $occ = $occupiedByBuilding[$building] ?? 0;
                $pct = reportPct($occ, $total);
                $buildingItems[] = ['label' => $building, 'value' => $occ . ' / ' . $total . ' occupied', 'percent' => $pct];
            }
            usort($buildingItems, fn($a, $b) => $b['percent'] <=> $a['percent']);
            $buildingItems = array_slice($buildingItems, 0, 8);

            return [
                'title'   => 'Occupancy Report',
                'columns' => ['Shelf Code', 'Building', 'Floor', 'Location', 'Status', 'Occupied By'],
                'rows'    => $rows,
                'summary' => count($rows) . " shelves — {$occupied} Occupied, {$available} Available, {$maintenance} Maintenance.",
                'charts'  => [
                    [
                        'kind' => 'segmented', 'title' => 'Shelf Occupancy',
                        'segments' => [
                            ['label' => 'Occupied',    'percent' => reportPct($occupied, count($data)),    'class' => 'bar-occupied'],
                            ['label' => 'Available',   'percent' => reportPct($available, count($data)),   'class' => 'bar-available'],
                            ['label' => 'Maintenance', 'percent' => reportPct($maintenance, count($data)), 'class' => 'bar-maintenance'],
                        ],
                    ],
                    [
                        'kind' => 'ranked', 'title' => '% Occupied by Building',
                        'items' => $buildingItems,
                    ],
                ],
            ];

        default:
            return ['title' => 'Unknown Report', 'columns' => [], 'rows' => [], 'summary' => null, 'charts' => []];
    }
}