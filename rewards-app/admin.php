<?php
session_start();
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$adminId = $_SESSION['admin_id'];
$adminUsername = $_SESSION['admin_username'] ?? '';
$adminRole = $_SESSION['admin_role'] ?? 'cashier';

$successMessage = '';
$errorMessage = '';
$selectedCustomer = null;
$transactions = [];
$searchTerm = trim($_GET['search'] ?? '');

function get_customer_balance(mysqli $conn, int $customerId): int {
    $stmt = $conn->prepare('SELECT COALESCE(SUM(points), 0) AS balance FROM points_transactions WHERE customer_id = ?');
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $balance = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($balance['balance'] ?? 0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['logout'])) {
        session_destroy();
        header('Location: login.php');
        exit;
    }

    $action = $_POST['action'] ?? '';
    $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;

    if ($customerId > 0) {
        $stmt = $conn->prepare('SELECT id, name, phone FROM customers WHERE id = ?');
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $selectedCustomer = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$selectedCustomer) {
            $errorMessage = 'Customer not found.';
        }
    } else {
        $errorMessage = 'No customer selected.';
    }

    if ($selectedCustomer) {
        $currentBalance = get_customer_balance($conn, $selectedCustomer['id']);

        if ($action === 'add_points') {
            $points = max(0, (int)($_POST['points_amount'] ?? 0));
            if ($points <= 0) {
                $errorMessage = 'Enter a positive number of points to add.';
            } else {
                $stmt = $conn->prepare('INSERT INTO points_transactions (customer_id, admin_id, transaction_type, points) VALUES (?, ?, "add", ?)');
                $stmt->bind_param('iii', $selectedCustomer['id'], $adminId, $points);
                $stmt->execute();
                $stmt->close();
                $successMessage = "Added {$points} points.";
            }
        } elseif ($action === 'subtract_points') {
            $points = max(0, (int)($_POST['points_amount'] ?? 0));
            if ($points <= 0) {
                $errorMessage = 'Enter a positive number of points to subtract.';
            } elseif ($currentBalance - $points < 0) {
                $errorMessage = 'Cannot reduce below zero points.';
            } else {
                $negativePoints = -$points;
                $stmt = $conn->prepare('INSERT INTO points_transactions (customer_id, admin_id, transaction_type, points) VALUES (?, ?, "subtract", ?)');
                $stmt->bind_param('iii', $selectedCustomer['id'], $adminId, $negativePoints);
                $stmt->execute();
                $stmt->close();
                $successMessage = "Subtracted {$points} points.";
            }
        } elseif ($action === 'redeem_points') {
            $redeemValue = 200;
            if ($currentBalance < $redeemValue) {
                $errorMessage = 'Customer does not have enough points to redeem.';
            } else {
                $negativePoints = -$redeemValue;
                $stmt = $conn->prepare('INSERT INTO points_transactions (customer_id, admin_id, transaction_type, points) VALUES (?, ?, "redeem", ?)');
                $stmt->bind_param('iii', $selectedCustomer['id'], $adminId, $negativePoints);
                $stmt->execute();
                $stmt->close();
                $successMessage = 'Redeemed 200 points for $5 reward.';
            }
        } elseif ($action === 'edit_points') {
            if ($adminRole !== 'superadmin') {
                $errorMessage = 'You do not have permission to edit points.';
            } else {
                $newBalance = max(0, (int)$_POST['new_balance']);
                $difference = $newBalance - $currentBalance;
                if ($difference === 0) {
                    $errorMessage = 'The balance is already set to that value.';
                } else {
                    $stmt = $conn->prepare('INSERT INTO points_transactions (customer_id, admin_id, transaction_type, points) VALUES (?, ?, "edit", ?)');
                    $stmt->bind_param('iii', $selectedCustomer['id'], $adminId, $difference);
                    $stmt->execute();
                    $stmt->close();
                    $successMessage = "Adjusted balance to {$newBalance} points.";
                }
            }
        }

        if ($successMessage) {
            $currentBalance = get_customer_balance($conn, $selectedCustomer['id']);
        }
    }

    if (isset($_POST['search_term'])) {
        $searchTerm = trim($_POST['search_term']);
    }
}

if ($searchTerm !== '') {
    $like = '%' . $searchTerm . '%';
    $stmt = $conn->prepare('SELECT id, name, phone FROM customers WHERE phone LIKE ? OR name LIKE ? ORDER BY join_date DESC LIMIT 1');
    $stmt->bind_param('ss', $like, $like);
    $stmt->execute();
    $selectedCustomer = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($selectedCustomer) {
    $selectedCustomer['balance'] = get_customer_balance($conn, $selectedCustomer['id']);

    $stmt = $conn->prepare('SELECT pt.created_at, pt.transaction_type, pt.points, a.username FROM points_transactions pt LEFT JOIN admins a ON pt.admin_id = a.id WHERE pt.customer_id = ? ORDER BY pt.created_at DESC LIMIT 5');
    $stmt->bind_param('i', $selectedCustomer['id']);
    $stmt->execute();
    $transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Dashboard summary
$totalCustomers = 0;
$totalPointsGiven = 0;
$totalPointsRedeemed = 0;
$outstandingBalance = 0;

$result = $conn->query('SELECT COUNT(*) AS total FROM customers');
if ($result) {
    $totalCustomers = (int)$result->fetch_assoc()['total'];
    $result->close();
}

$result = $conn->query("SELECT COALESCE(SUM(points),0) AS total_add FROM points_transactions WHERE points > 0");
if ($result) {
    $totalPointsGiven = (int)$result->fetch_assoc()['total_add'];
    $result->close();
}

$result = $conn->query("SELECT COALESCE(SUM(ABS(points)),0) AS total_redeemed FROM points_transactions WHERE transaction_type = 'redeem'");
if ($result) {
    $totalPointsRedeemed = (int)$result->fetch_assoc()['total_redeemed'];
    $result->close();
}

$result = $conn->query('SELECT COALESCE(SUM(points),0) AS balance FROM points_transactions');
if ($result) {
    $outstandingBalance = (int)$result->fetch_assoc()['balance'];
    $result->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rewards Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-900 text-white min-h-screen">
    <header class="bg-slate-800/80 backdrop-blur border-b border-slate-700">
        <div class="max-w-6xl mx-auto px-6 py-4 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold">Rewards Dashboard</h1>
                <p class="text-sm text-slate-300">Logged in as <span class="font-semibold text-blue-300"><?= htmlspecialchars($adminUsername) ?></span> (<?= htmlspecialchars($adminRole) ?>)</p>
            </div>
            <form method="POST">
                <button type="submit" name="logout" class="px-4 py-2 bg-red-600 hover:bg-red-500 rounded-xl text-sm font-semibold">Logout</button>
            </form>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-6 py-8 space-y-8">
        <section class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="bg-slate-800/60 p-4 rounded-2xl">
                <p class="text-sm text-slate-400">Total Customers</p>
                <p class="text-3xl font-bold text-blue-400"><?= $totalCustomers ?></p>
            </div>
            <div class="bg-slate-800/60 p-4 rounded-2xl">
                <p class="text-sm text-slate-400">Total Points Given</p>
                <p class="text-3xl font-bold text-green-400"><?= $totalPointsGiven ?></p>
            </div>
            <div class="bg-slate-800/60 p-4 rounded-2xl">
                <p class="text-sm text-slate-400">Total Points Redeemed</p>
                <p class="text-3xl font-bold text-red-400"><?= $totalPointsRedeemed ?></p>
            </div>
            <div class="bg-slate-800/60 p-4 rounded-2xl">
                <p class="text-sm text-slate-400">Outstanding Balance</p>
                <p class="text-3xl font-bold text-blue-300"><?= $outstandingBalance ?></p>
            </div>
        </section>

        <section class="bg-slate-800/60 rounded-3xl p-6 space-y-6">
            <h2 class="text-xl font-semibold">Customer Lookup</h2>
            <form method="POST" class="flex flex-col md:flex-row gap-4">
                <input type="text" name="search_term" value="<?= htmlspecialchars($searchTerm) ?>" placeholder="Search by phone or name" class="flex-1 px-4 py-3 rounded-xl bg-slate-900/60 border border-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                <button type="submit" class="px-6 py-3 bg-blue-600 hover:bg-blue-500 rounded-xl font-semibold">Search</button>
            </form>

            <?php if ($errorMessage): ?>
                <div class="p-4 bg-red-500/20 border border-red-500 text-red-200 rounded-xl">
                    <?= htmlspecialchars($errorMessage) ?>
                </div>
            <?php endif; ?>
            <?php if ($successMessage): ?>
                <div class="p-4 bg-green-500/20 border border-green-500 text-green-200 rounded-xl">
                    <?= htmlspecialchars($successMessage) ?>
                </div>
            <?php endif; ?>

            <?php if ($selectedCustomer): ?>
                <div class="grid gap-6 md:grid-cols-2">
                    <div class="space-y-4">
                        <div class="bg-slate-900/60 rounded-2xl p-5">
                            <p class="text-lg font-semibold"><?= htmlspecialchars($selectedCustomer['name']) ?></p>
                            <p class="text-sm text-slate-300">Phone: <?= htmlspecialchars($selectedCustomer['phone']) ?></p>
                            <p class="mt-2 text-3xl font-bold text-green-400"><?= $selectedCustomer['balance'] ?> pts</p>
                        </div>

                        <div class="bg-slate-900/60 rounded-2xl p-5 space-y-4">
                            <h3 class="font-semibold">Manage Points</h3>
                            <div class="space-y-3">
                                <form method="POST" class="flex flex-col md:flex-row md:items-end gap-3">
                                    <input type="hidden" name="customer_id" value="<?= $selectedCustomer['id'] ?>">
                                    <?php if ($searchTerm !== ''): ?>
                                        <input type="hidden" name="search_term" value="<?= htmlspecialchars($searchTerm) ?>">
                                    <?php endif; ?>
                                    <div class="flex-1">
                                        <label class="block text-sm text-slate-300 mb-1">Add Points</label>
                                        <input type="number" name="points_amount" min="1" class="w-full px-3 py-2 rounded-lg bg-slate-800 border border-slate-700" placeholder="e.g. 25">
                                    </div>
                                    <button name="action" value="add_points" class="px-4 py-2 bg-blue-600 hover:bg-blue-500 rounded-lg">Add</button>
                                </form>

                                <form method="POST" class="flex flex-col md:flex-row md:items-end gap-3">
                                    <input type="hidden" name="customer_id" value="<?= $selectedCustomer['id'] ?>">
                                    <?php if ($searchTerm !== ''): ?>
                                        <input type="hidden" name="search_term" value="<?= htmlspecialchars($searchTerm) ?>">
                                    <?php endif; ?>
                                    <div class="flex-1">
                                        <label class="block text-sm text-slate-300 mb-1">Subtract Points</label>
                                        <input type="number" name="points_amount" min="1" class="w-full px-3 py-2 rounded-lg bg-slate-800 border border-slate-700" placeholder="e.g. 10">
                                    </div>
                                    <button name="action" value="subtract_points" class="px-4 py-2 bg-red-600 hover:bg-red-500 rounded-lg">Subtract</button>
                                </form>

                                <?php $redeemDisabled = $selectedCustomer['balance'] < 200; ?>
                                <form method="POST" class="flex flex-col md:flex-row md:items-center gap-3">
                                    <input type="hidden" name="customer_id" value="<?= $selectedCustomer['id'] ?>">
                                    <?php if ($searchTerm !== ''): ?>
                                        <input type="hidden" name="search_term" value="<?= htmlspecialchars($searchTerm) ?>">
                                    <?php endif; ?>
                                    <button name="action" value="redeem_points" class="px-4 py-2 rounded-lg font-semibold <?= $redeemDisabled ? 'bg-green-600/40 cursor-not-allowed text-slate-300' : 'bg-green-600 hover:bg-green-500' ?>" <?= $redeemDisabled ? 'disabled' : '' ?>>Redeem 200 pts</button>
                                    <?php if ($redeemDisabled): ?>
                                        <span class="text-sm text-slate-300">Needs <?= 200 - $selectedCustomer['balance'] ?> more points</span>
                                    <?php endif; ?>
                                </form>

                                <?php if ($adminRole === 'superadmin'): ?>
                                    <form method="POST" class="flex flex-col md:flex-row md:items-end gap-3">
                                        <input type="hidden" name="customer_id" value="<?= $selectedCustomer['id'] ?>">
                                        <?php if ($searchTerm !== ''): ?>
                                            <input type="hidden" name="search_term" value="<?= htmlspecialchars($searchTerm) ?>">
                                        <?php endif; ?>
                                        <div class="flex-1 md:w-48">
                                            <label class="block text-sm text-slate-300 mb-1">Set Custom Balance</label>
                                            <input type="number" name="new_balance" min="0" class="w-full px-3 py-2 rounded-lg bg-slate-800 border border-slate-700" placeholder="e.g. 150">
                                        </div>
                                        <button name="action" value="edit_points" class="px-4 py-2 bg-purple-600 hover:bg-purple-500 rounded-lg">Set Balance</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="bg-slate-900/60 rounded-2xl p-5">
                        <h3 class="font-semibold mb-4">Recent Transactions</h3>
                        <div class="space-y-3">
                            <?php if (empty($transactions)): ?>
                                <p class="text-sm text-slate-400">No transactions yet.</p>
                            <?php else: ?>
                                <?php foreach ($transactions as $txn): ?>
                                    <div class="p-3 rounded-xl bg-slate-800 border border-slate-700">
                                        <div class="flex justify-between text-sm text-slate-300">
                                            <span><?= htmlspecialchars(ucfirst($txn['transaction_type'])) ?></span>
                                            <span><?= date('M d, Y g:i A', strtotime($txn['created_at'])) ?></span>
                                        </div>
                                        <div class="mt-2 flex justify-between items-center">
                                            <span class="text-xl font-semibold <?= $txn['points'] >= 0 ? 'text-green-400' : 'text-red-400' ?>"><?= $txn['points'] >= 0 ? '+' : '' ?><?= $txn['points'] ?></span>
                                            <span class="text-xs text-slate-400">Handled by <?= htmlspecialchars($txn['username'] ?? 'System') ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <p class="text-sm text-slate-400">Search for a customer by phone number or name to manage their rewards.</p>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
