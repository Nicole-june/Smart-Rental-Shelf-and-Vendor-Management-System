<?php


$inventoryTab = $inventoryTab ?? 'products';

$pendingApprovalsCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM inventory_transactions WHERE status = 'Pending' AND requested_by IS NOT NULL"
)->fetchColumn();

$tabs = [
    'products'    => ['label' => 'Products',    'href' => 'products.php'],
    'categories'  => ['label' => 'Categories',  'href' => 'categories.php'],
    'stock_in'    => ['label' => 'Stock In',     'href' => 'stock_in.php'],
    'record_sale' => ['label' => 'Record Sale',  'href' => 'record_sale.php'],
    'history'     => ['label' => 'History',      'href' => 'inventory_history.php'],
    'approvals'   => [
        'label' => 'Approvals' . ($pendingApprovalsCount > 0 ? ' (' . $pendingApprovalsCount . ')' : ''),
        'href'  => 'inventory_approvals.php',
    ],
];
?>
<div class="sub-tabs">
    <?php foreach ($tabs as $key => $tab): ?>
        <a href="<?= e($tab['href']) ?>" class="sub-tab <?= $inventoryTab === $key ? 'active' : '' ?>">
            <?= e($tab['label']) ?>
        </a>
    <?php endforeach; ?>
</div>