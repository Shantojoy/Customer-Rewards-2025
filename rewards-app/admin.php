<?php
session_start();
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$adminId = (int)$_SESSION['admin_id'];
$adminUsername = $_SESSION['admin_username'] ?? '';
$adminRole = $_SESSION['admin_role'] ?? 'cashier';

$successMessages = [];
$errorMessages = [];
$transactions = [];
$searchTerm = trim($_GET['search'] ?? '');
$selectedCustomerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
if (!$selectedCustomerId && isset($_GET['customer_id'])) {
    $selectedCustomerId = (int)$_GET['customer_id'];
}

function add_success(string $message) {
    global $successMessages;
    $successMessages[] = $message;
}

function add_error(string $message) {
    global $errorMessages;
    $errorMessages[] = $message;
}

function validate_phone(string $phone): ?string {
    $digits = preg_replace('/\D+/', '', $phone);
    if (strlen($digits) < 7 || strlen($digits) > 15) {
        return null;
    }
    return $digits;
}

function get_customer_balance(mysqli $conn, int $customerId): int {
    $stmt = $conn->prepare('SELECT COALESCE(SUM(points), 0) AS balance FROM points_transactions WHERE customer_id = ?');
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $balance = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($balance['balance'] ?? 0);
}

function fetch_customer(mysqli $conn, int $customerId): ?array {
    $stmt = $conn->prepare('SELECT id, name, phone, email, join_date FROM customers WHERE id = ?');
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $customer ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['logout'])) {
        session_destroy();
        header('Location: login.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if (isset($_POST['search_term'])) {
        $searchTerm = trim($_POST['search_term']);
    }

    switch ($action) {
        case 'add_points':
        case 'subtract_points':
        case 'redeem_points':
        case 'edit_points':
        case 'log_visit':
            $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
            if ($customerId <= 0) {
                add_error('No customer selected.');
                break;
            }
            $customer = fetch_customer($conn, $customerId);
            if (!$customer) {
                add_error('Customer not found.');
                break;
            }
            $selectedCustomerId = $customerId;
            $currentBalance = get_customer_balance($conn, $customerId);

            if ($action === 'add_points') {
                $points = max(0, (int)($_POST['points_amount'] ?? 0));
                if ($points <= 0) {
                    add_error('Enter a positive number of points to add.');
                } else {
                    $stmt = $conn->prepare('INSERT INTO points_transactions (customer_id, admin_id, transaction_type, points) VALUES (?, ?, "add", ?)');
                    $stmt->bind_param('iii', $customerId, $adminId, $points);
                    $stmt->execute();
                    $stmt->close();
                    add_success("Added {$points} points to {$customer['name']}.");
                }
            } elseif ($action === 'subtract_points') {
                $points = max(0, (int)($_POST['points_amount'] ?? 0));
                if ($points <= 0) {
                    add_error('Enter a positive number of points to subtract.');
                } elseif ($currentBalance - $points < 0) {
                    add_error('Cannot reduce below zero points.');
                } else {
                    $negativePoints = -$points;
                    $stmt = $conn->prepare('INSERT INTO points_transactions (customer_id, admin_id, transaction_type, points) VALUES (?, ?, "subtract", ?)');
                    $stmt->bind_param('iii', $customerId, $adminId, $negativePoints);
                    $stmt->execute();
                    $stmt->close();
                    add_success("Subtracted {$points} points from {$customer['name']}.");
                }
            } elseif ($action === 'redeem_points') {
                $redeemValue = 200;
                if ($currentBalance < $redeemValue) {
                    add_error('Customer does not have enough points to redeem.');
                } else {
                    $negativePoints = -$redeemValue;
                    $stmt = $conn->prepare('INSERT INTO points_transactions (customer_id, admin_id, transaction_type, points) VALUES (?, ?, "redeem", ?)');
                    $stmt->bind_param('iii', $customerId, $adminId, $negativePoints);
                    $stmt->execute();
                    $stmt->close();
                    add_success('Redeemed 200 points for $5 reward.');
                }
            } elseif ($action === 'edit_points') {
                if ($adminRole !== 'superadmin') {
                    add_error('You do not have permission to edit points.');
                } else {
                    $newBalance = max(0, (int)($_POST['new_balance'] ?? 0));
                    $difference = $newBalance - $currentBalance;
                    if ($difference === 0) {
                        add_error('The balance is already set to that value.');
                    } else {
                        $stmt = $conn->prepare('INSERT INTO points_transactions (customer_id, admin_id, transaction_type, points) VALUES (?, ?, "edit", ?)');
                        $stmt->bind_param('iii', $customerId, $adminId, $difference);
                        $stmt->execute();
                        $stmt->close();
                        add_success("Adjusted balance to {$newBalance} points.");
                    }
                }
            } elseif ($action === 'log_visit') {
                if ($adminRole !== 'superadmin') {
                    add_error('Only superadmins can log customer visits.');
                } else {
                    $stmt = $conn->prepare('INSERT INTO customer_visits (customer_id, admin_id) VALUES (?, ?)');
                    $stmt->bind_param('ii', $customerId, $adminId);
                    $stmt->execute();
                    $stmt->close();
                    add_success("Logged a new visit for {$customer['name']}.");
                }
            }
            break;

        case 'create_customer':
            if ($adminRole !== 'superadmin') {
                add_error('Only superadmins can create customer records.');
                break;
            }
            $name = trim($_POST['customer_name'] ?? '');
            $phoneRaw = trim($_POST['customer_phone'] ?? '');
            $email = trim($_POST['customer_email'] ?? '');
            $phone = validate_phone($phoneRaw);
            if ($name === '' || !$phone) {
                add_error('Provide a customer name and a valid phone number.');
                break;
            }
            $stmt = $conn->prepare('SELECT id FROM customers WHERE phone = ?');
            $stmt->bind_param('s', $phone);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                add_error('A customer with that phone number already exists.');
                $stmt->close();
                break;
            }
            $stmt->close();
            $stmt = $conn->prepare('INSERT INTO customers (name, phone, email) VALUES (?, ?, NULLIF(?, ""))');
            $emailParam = $email;
            $stmt->bind_param('sss', $name, $phone, $emailParam);
            $stmt->execute();
            $newCustomerId = $stmt->insert_id;
            $stmt->close();
            $selectedCustomerId = $newCustomerId;
            add_success('Customer created successfully.');
            break;

        case 'update_customer':
            if ($adminRole !== 'superadmin') {
                add_error('Only superadmins can update customer records.');
                break;
            }
            $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
            $name = trim($_POST['customer_name'] ?? '');
            $phoneRaw = trim($_POST['customer_phone'] ?? '');
            $email = trim($_POST['customer_email'] ?? '');
            $phone = validate_phone($phoneRaw);
            if ($customerId <= 0 || $name === '' || !$phone) {
                add_error('Provide a customer name and valid phone number.');
                break;
            }
            $customer = fetch_customer($conn, $customerId);
            if (!$customer) {
                add_error('Customer not found.');
                break;
            }
            $stmt = $conn->prepare('SELECT id FROM customers WHERE phone = ? AND id <> ?');
            $stmt->bind_param('si', $phone, $customerId);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                add_error('Another customer already uses that phone number.');
                $stmt->close();
                break;
            }
            $stmt->close();
            $stmt = $conn->prepare('UPDATE customers SET name = ?, phone = ?, email = NULLIF(?, "") WHERE id = ?');
            $emailParam = $email;
            $stmt->bind_param('sssi', $name, $phone, $emailParam, $customerId);
            $stmt->execute();
            $stmt->close();
            $selectedCustomerId = $customerId;
            add_success('Customer details updated.');
            break;

        case 'delete_customer':
            if ($adminRole !== 'superadmin') {
                add_error('Only superadmins can delete customer records.');
                break;
            }
            $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
            if ($customerId <= 0) {
                add_error('Invalid customer selection.');
                break;
            }
            $customer = fetch_customer($conn, $customerId);
            if (!$customer) {
                add_error('Customer not found.');
                break;
            }
            $stmt = $conn->prepare('DELETE FROM customers WHERE id = ?');
            $stmt->bind_param('i', $customerId);
            $stmt->execute();
            $stmt->close();
            if ($selectedCustomerId === $customerId) {
                $selectedCustomerId = 0;
            }
            add_success('Customer deleted.');
            break;

        case 'create_admin':
        case 'update_admin':
        case 'delete_admin':
            if ($adminRole !== 'superadmin') {
                add_error('Only superadmins can manage admin accounts.');
                break;
            }
            if ($action === 'create_admin') {
                $username = trim($_POST['admin_username'] ?? '');
                $password = trim($_POST['admin_password'] ?? '');
                $role = $_POST['admin_role'] ?? 'cashier';
                if ($username === '' || $password === '') {
                    add_error('Provide a username and password for the new admin.');
                    break;
                }
                if (!in_array($role, ['superadmin', 'cashier'], true)) {
                    $role = 'cashier';
                }
                $stmt = $conn->prepare('SELECT id FROM admins WHERE username = ?');
                $stmt->bind_param('s', $username);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows > 0) {
                    add_error('An admin with that username already exists.');
                    $stmt->close();
                    break;
                }
                $stmt->close();
                $passwordHash = hash('sha256', $password);
                $stmt = $conn->prepare('INSERT INTO admins (username, password, role) VALUES (?, ?, ?)');
                $stmt->bind_param('sss', $username, $passwordHash, $role);
                $stmt->execute();
                $stmt->close();
                add_success('Admin account created.');
            } elseif ($action === 'update_admin') {
                $targetAdminId = isset($_POST['admin_id']) ? (int)$_POST['admin_id'] : 0;
                $username = trim($_POST['admin_username'] ?? '');
                $role = $_POST['admin_role'] ?? 'cashier';
                $password = trim($_POST['admin_password'] ?? '');
                if ($targetAdminId <= 0 || $username === '') {
                    add_error('Provide a username for the admin account.');
                    break;
                }
                if (!in_array($role, ['superadmin', 'cashier'], true)) {
                    $role = 'cashier';
                }
                $stmt = $conn->prepare('SELECT id FROM admins WHERE username = ? AND id <> ?');
                $stmt->bind_param('si', $username, $targetAdminId);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows > 0) {
                    add_error('Another admin already uses that username.');
                    $stmt->close();
                    break;
                }
                $stmt->close();
                if ($password !== '') {
                    $passwordHash = hash('sha256', $password);
                    $stmt = $conn->prepare('UPDATE admins SET username = ?, role = ?, password = ? WHERE id = ?');
                    $stmt->bind_param('sssi', $username, $role, $passwordHash, $targetAdminId);
                } else {
                    $stmt = $conn->prepare('UPDATE admins SET username = ?, role = ? WHERE id = ?');
                    $stmt->bind_param('ssi', $username, $role, $targetAdminId);
                }
                $stmt->execute();
                $stmt->close();
                if ($targetAdminId === $adminId) {
                    $_SESSION['admin_username'] = $username;
                    $_SESSION['admin_role'] = $role;
                    $adminUsername = $username;
                    $adminRole = $role;
                }
                add_success('Admin account updated.');
            } elseif ($action === 'delete_admin') {
                $targetAdminId = isset($_POST['admin_id']) ? (int)$_POST['admin_id'] : 0;
                if ($targetAdminId <= 0) {
                    add_error('Invalid admin selected.');
                    break;
                }
                if ($targetAdminId === $adminId) {
                    add_error('You cannot delete your own account while logged in.');
                    break;
                }
                $stmt = $conn->prepare('DELETE FROM admins WHERE id = ?');
                $stmt->bind_param('i', $targetAdminId);
                $stmt->execute();
                $stmt->close();
                add_success('Admin account deleted.');
            }
            break;

        default:
            break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['search'])) {
    $searchTerm = trim($_GET['search']);
}

if ($searchTerm !== '') {
    $like = '%' . $searchTerm . '%';
    $stmt = $conn->prepare('SELECT id, name, phone, email, join_date FROM customers WHERE phone LIKE ? OR name LIKE ? ORDER BY join_date DESC LIMIT 1');
    $stmt->bind_param('ss', $like, $like);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    if ($result) {
        $selectedCustomerId = (int)$result['id'];
    }
    $stmt->close();
}

$selectedCustomer = null;
$visitHistory = [];
$visitCount = 0;
if ($selectedCustomerId) {
    $selectedCustomer = fetch_customer($conn, $selectedCustomerId);
    if ($selectedCustomer) {
        $selectedCustomer['balance'] = get_customer_balance($conn, $selectedCustomerId);

        $stmt = $conn->prepare('SELECT COUNT(*) AS total_visits FROM customer_visits WHERE customer_id = ?');
        $stmt->bind_param('i', $selectedCustomerId);
        $stmt->execute();
        $visitCount = (int)$stmt->get_result()->fetch_assoc()['total_visits'];
        $stmt->close();

        $stmt = $conn->prepare('SELECT cv.visit_date, a.username FROM customer_visits cv LEFT JOIN admins a ON cv.admin_id = a.id WHERE cv.customer_id = ? ORDER BY cv.visit_date DESC LIMIT 20');
        $stmt->bind_param('i', $selectedCustomerId);
        $stmt->execute();
        $visitHistory = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $stmt = $conn->prepare('SELECT pt.created_at, pt.transaction_type, pt.points, a.username FROM points_transactions pt LEFT JOIN admins a ON pt.admin_id = a.id WHERE pt.customer_id = ? ORDER BY pt.created_at DESC LIMIT 5');
        $stmt->bind_param('i', $selectedCustomerId);
        $stmt->execute();
        $transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

// Dashboard summary metrics
$totalCustomers = 0;
$totalPointsGiven = 0;
$totalPointsRedeemed = 0;
$outstandingBalance = 0;

$result = $conn->query('SELECT COUNT(*) AS total FROM customers');
if ($result) {
    $totalCustomers = (int)$result->fetch_assoc()['total'];
    $result->close();
}

$result = $conn->query('SELECT COALESCE(SUM(points),0) AS total_add FROM points_transactions WHERE points > 0');
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

$admins = [];
$adminResult = $conn->query('SELECT id, username, role, created_at FROM admins ORDER BY created_at DESC');
if ($adminResult) {
    $admins = $adminResult->fetch_all(MYSQLI_ASSOC);
    $adminResult->close();
}

$customers = [];
$customerQuery = $conn->query(
    "SELECT c.id, c.name, c.phone, c.email, c.join_date, " .
    "COALESCE(v.visit_count, 0) AS visit_count, COALESCE(v.last_visit, NULL) AS last_visit, " .
    "COALESCE(p.balance, 0) AS balance " .
    "FROM customers c " .
    "LEFT JOIN (SELECT customer_id, COUNT(*) AS visit_count, MAX(visit_date) AS last_visit FROM customer_visits GROUP BY customer_id) v ON v.customer_id = c.id " .
    "LEFT JOIN (SELECT customer_id, SUM(points) AS balance FROM points_transactions GROUP BY customer_id) p ON p.customer_id = c.id " .
    "ORDER BY c.join_date DESC"
);
if ($customerQuery) {
    $customers = $customerQuery->fetch_all(MYSQLI_ASSOC);
    $customerQuery->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rewards Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            body { background: #fff; color: #000; }
            .print\:hidden { display: none !important; }
            .print\:block { display: block !important; }
            .print\:grid { display: grid !important; }
        }
    </style>
</head>
<body class="bg-slate-900 text-white min-h-screen">
    <header class="bg-slate-800/80 backdrop-blur border-b border-slate-700 print:hidden">
        <div class="max-w-7xl mx-auto px-6 py-4 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold">Rewards Dashboard</h1>
                <p class="text-sm text-slate-300">Logged in as <span class="font-semibold text-blue-300"><?= htmlspecialchars($adminUsername) ?></span> (<?= htmlspecialchars($adminRole) ?>)</p>
            </div>
            <form method="POST">
                <button type="submit" name="logout" class="px-4 py-2 bg-red-600 hover:bg-red-500 rounded-xl text-sm font-semibold">Logout</button>
            </form>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-6 py-8 space-y-8">
        <section class="grid grid-cols-1 md:grid-cols-4 gap-4 print:grid">
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
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <h2 class="text-xl font-semibold">Customer Lookup</h2>
                <div class="flex items-center gap-3 print:hidden">
                    <button onclick="window.print()" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-xl">Print Profile</button>
                </div>
            </div>
            <form method="POST" class="flex flex-col md:flex-row gap-4 print:hidden">
                <input type="text" name="search_term" value="<?= htmlspecialchars($searchTerm) ?>" placeholder="Search by phone or name" class="flex-1 px-4 py-3 rounded-xl bg-slate-900/60 border border-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                <button type="submit" class="px-6 py-3 bg-blue-600 hover:bg-blue-500 rounded-xl font-semibold">Search</button>
            </form>

            <?php foreach ($errorMessages as $message): ?>
                <div class="p-4 bg-red-500/20 border border-red-500 text-red-200 rounded-xl">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endforeach; ?>
            <?php foreach ($successMessages as $message): ?>
                <div class="p-4 bg-green-500/20 border border-green-500 text-green-200 rounded-xl">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endforeach; ?>

            <?php if ($selectedCustomer): ?>
                <div class="grid gap-6 lg:grid-cols-2 print:grid print:grid-cols-2">
                    <div class="space-y-4">
                        <div class="bg-slate-900/60 rounded-2xl p-5 space-y-2">
                            <p class="text-lg font-semibold"><?= htmlspecialchars($selectedCustomer['name']) ?></p>
                            <p class="text-sm text-slate-300">Phone: <?= htmlspecialchars($selectedCustomer['phone']) ?></p>
                            <p class="text-sm text-slate-300">Email: <?= $selectedCustomer['email'] ? htmlspecialchars($selectedCustomer['email']) : 'Not provided' ?></p>
                            <p class="text-sm text-slate-300">Joined: <?= date('M d, Y', strtotime($selectedCustomer['join_date'])) ?></p>
                            <p class="text-sm text-slate-300">Visits: <?= $visitCount ?></p>
                            <p class="mt-2 text-3xl font-bold text-green-400"><?= $selectedCustomer['balance'] ?> pts</p>
                        </div>

                        <div class="bg-slate-900/60 rounded-2xl p-5 space-y-4 print:hidden">
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

                                <?php if ($adminRole === 'superadmin'): ?>
                                    <form method="POST" class="flex flex-col md:flex-row md:items-center gap-3">
                                        <input type="hidden" name="customer_id" value="<?= $selectedCustomer['id'] ?>">
                                        <?php if ($searchTerm !== ''): ?>
                                            <input type="hidden" name="search_term" value="<?= htmlspecialchars($searchTerm) ?>">
                                        <?php endif; ?>
                                        <button name="action" value="log_visit" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg">Log Visit</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-4">
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

                        <div class="bg-slate-900/60 rounded-2xl p-5">
                            <h3 class="font-semibold mb-4">Visit History</h3>
                            <div class="space-y-3">
                                <?php if (empty($visitHistory)): ?>
                                    <p class="text-sm text-slate-400">No visits have been logged yet.</p>
                                <?php else: ?>
                                    <?php foreach ($visitHistory as $visit): ?>
                                        <div class="p-3 rounded-xl bg-slate-800 border border-slate-700">
                                            <div class="flex justify-between text-sm text-slate-300">
                                                <span><?= date('M d, Y g:i A', strtotime($visit['visit_date'])) ?></span>
                                                <span><?= htmlspecialchars($visit['username'] ?? 'Unknown') ?></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <p class="text-sm text-slate-400">Search for a customer by phone number or name to manage their rewards and visit history.</p>
            <?php endif; ?>
        </section>

        <section class="grid gap-6 lg:grid-cols-2">
            <div class="bg-slate-800/60 rounded-3xl p-6 space-y-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-semibold">Customer Management</h2>
                    <?php if ($selectedCustomer): ?>
                        <a href="?customer_id=<?= $selectedCustomer['id'] ?>" class="text-sm text-blue-300 hover:text-blue-200">Refresh Profile</a>
                    <?php endif; ?>
                </div>
                <?php if ($adminRole === 'superadmin'): ?>
                    <form method="POST" class="grid gap-3 print:hidden">
                        <h3 class="font-semibold">Add New Customer</h3>
                        <div class="grid gap-2">
                            <label class="text-sm text-slate-300" for="new_customer_name">Name</label>
                            <input id="new_customer_name" type="text" name="customer_name" class="px-3 py-2 rounded-lg bg-slate-900/60 border border-slate-700" required>
                        </div>
                        <div class="grid gap-2">
                            <label class="text-sm text-slate-300" for="new_customer_phone">Phone</label>
                            <input id="new_customer_phone" type="tel" name="customer_phone" class="px-3 py-2 rounded-lg bg-slate-900/60 border border-slate-700" required>
                        </div>
                        <div class="grid gap-2">
                            <label class="text-sm text-slate-300" for="new_customer_email">Email (optional)</label>
                            <input id="new_customer_email" type="email" name="customer_email" class="px-3 py-2 rounded-lg bg-slate-900/60 border border-slate-700">
                        </div>
                        <button name="action" value="create_customer" class="px-4 py-2 bg-green-600 hover:bg-green-500 rounded-xl font-semibold">Add Customer</button>
                    </form>
                <?php else: ?>
                    <p class="text-sm text-slate-300 print:hidden">Customer records can only be created or edited by a superadmin.</p>
                <?php endif; ?>

                <div class="overflow-x-auto bg-slate-900/40 rounded-2xl">
                    <table class="min-w-full text-left text-sm">
                        <thead class="text-xs uppercase text-slate-400 bg-slate-900/70">
                            <tr>
                                <th class="px-4 py-3">Name</th>
                                <th class="px-4 py-3">Phone</th>
                                <th class="px-4 py-3">Visits</th>
                                <th class="px-4 py-3">Points</th>
                                <th class="px-4 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            <?php if (empty($customers)): ?>
                                <tr>
                                    <td colspan="5" class="px-4 py-4 text-center text-slate-400">No customers found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($customers as $customer): ?>
                                    <tr class="hover:bg-slate-800/40">
                                        <td class="px-4 py-3 font-medium text-slate-200"><?= htmlspecialchars($customer['name']) ?></td>
                                        <td class="px-4 py-3 text-slate-300"><?= htmlspecialchars($customer['phone']) ?></td>
                                        <td class="px-4 py-3 text-slate-300"><?= (int)$customer['visit_count'] ?></td>
                                        <td class="px-4 py-3 text-slate-300"><?= (int)$customer['balance'] ?></td>
                                        <td class="px-4 py-3 text-sm text-slate-200">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <a href="?customer_id=<?= $customer['id'] ?>" class="px-3 py-1 bg-blue-600 hover:bg-blue-500 rounded-lg">View</a>
                                                <?php if ($adminRole === 'superadmin'): ?>
                                                    <button type="button" data-modal="edit-customer-<?= $customer['id'] ?>" class="px-3 py-1 bg-slate-700 hover:bg-slate-600 rounded-lg modal-trigger">Edit</button>
                                                    <form method="POST" class="inline" onsubmit="return confirm('Delete this customer?');">
                                                        <input type="hidden" name="customer_id" value="<?= $customer['id'] ?>">
                                                        <button name="action" value="delete_customer" class="px-3 py-1 bg-red-600 hover:bg-red-500 rounded-lg">Delete</button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="text-xs uppercase tracking-wide text-slate-400">View only</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-slate-800/60 rounded-3xl p-6 space-y-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-semibold">Admin Management</h2>
                    <span class="text-xs uppercase tracking-wide text-slate-400">Superadmin Only</span>
                </div>

                <?php if ($adminRole === 'superadmin'): ?>
                    <form method="POST" class="grid gap-3 print:hidden">
                        <h3 class="font-semibold">Add New Admin</h3>
                        <div class="grid gap-2">
                            <label class="text-sm text-slate-300" for="new_admin_username">Username</label>
                            <input id="new_admin_username" type="text" name="admin_username" class="px-3 py-2 rounded-lg bg-slate-900/60 border border-slate-700" required>
                        </div>
                        <div class="grid gap-2">
                            <label class="text-sm text-slate-300" for="new_admin_password">Password</label>
                            <input id="new_admin_password" type="password" name="admin_password" class="px-3 py-2 rounded-lg bg-slate-900/60 border border-slate-700" required>
                        </div>
                        <div class="grid gap-2">
                            <label class="text-sm text-slate-300" for="new_admin_role">Role</label>
                            <select id="new_admin_role" name="admin_role" class="px-3 py-2 rounded-lg bg-slate-900/60 border border-slate-700">
                                <option value="cashier">Cashier</option>
                                <option value="superadmin">Superadmin</option>
                            </select>
                        </div>
                        <button name="action" value="create_admin" class="px-4 py-2 bg-blue-600 hover:bg-blue-500 rounded-xl font-semibold">Add Admin</button>
                    </form>
                <?php else: ?>
                    <p class="text-sm text-slate-300">Contact a superadmin to manage user accounts.</p>
                <?php endif; ?>

                <div class="overflow-x-auto bg-slate-900/40 rounded-2xl">
                    <table class="min-w-full text-left text-sm">
                        <thead class="text-xs uppercase text-slate-400 bg-slate-900/70">
                            <tr>
                                <th class="px-4 py-3">Username</th>
                                <th class="px-4 py-3">Role</th>
                                <th class="px-4 py-3">Created</th>
                                <th class="px-4 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            <?php if (empty($admins)): ?>
                                <tr>
                                    <td colspan="4" class="px-4 py-4 text-center text-slate-400">No admin accounts found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($admins as $admin): ?>
                                    <tr class="hover:bg-slate-800/40">
                                        <td class="px-4 py-3 font-medium text-slate-200"><?= htmlspecialchars($admin['username']) ?></td>
                                        <td class="px-4 py-3 text-slate-300 capitalize"><?= htmlspecialchars($admin['role']) ?></td>
                                        <td class="px-4 py-3 text-slate-300"><?= date('M d, Y', strtotime($admin['created_at'])) ?></td>
                                        <td class="px-4 py-3 text-sm text-slate-200">
                                            <?php if ($adminRole === 'superadmin'): ?>
                                                <div class="flex flex-wrap gap-2">
                                                    <button type="button" data-modal="edit-admin-<?= $admin['id'] ?>" class="px-3 py-1 bg-slate-700 hover:bg-slate-600 rounded-lg modal-trigger">Edit</button>
                                                    <?php if ((int)$admin['id'] !== $adminId): ?>
                                                        <form method="POST" onsubmit="return confirm('Delete this admin account?');">
                                                            <input type="hidden" name="admin_id" value="<?= $admin['id'] ?>">
                                                            <button name="action" value="delete_admin" class="px-3 py-1 bg-red-600 hover:bg-red-500 rounded-lg">Delete</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-xs text-slate-400">No access</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>

    <template id="modal-template">
        <div class="fixed inset-0 bg-black/70 flex items-center justify-center z-50">
            <div class="bg-slate-900 rounded-3xl shadow-2xl w-full max-w-lg p-6 space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-semibold" data-modal-title></h3>
                    <button type="button" class="text-slate-400 hover:text-white" data-modal-close>&times;</button>
                </div>
                <div data-modal-body></div>
            </div>
        </div>
    </template>

    <div id="modal-root"></div>

    <script>
        const modalRoot = document.getElementById('modal-root');
        const template = document.getElementById('modal-template');

        function openModal(content) {
            const clone = template.content.cloneNode(true);
            const wrapper = clone.querySelector('div.fixed');
            const body = clone.querySelector('[data-modal-body]');
            body.appendChild(content);
            clone.querySelector('[data-modal-close]').addEventListener('click', () => closeModal(wrapper));
            wrapper.addEventListener('click', (event) => {
                if (event.target === wrapper) {
                    closeModal(wrapper);
                }
            });
            modalRoot.appendChild(clone);
        }

        function closeModal(modal) {
            modal?.remove();
        }

        document.querySelectorAll('.modal-trigger').forEach((button) => {
            button.addEventListener('click', () => {
                const targetId = button.getAttribute('data-modal');
                const formTemplate = document.getElementById(targetId);
                if (!formTemplate) return;
                const formContent = formTemplate.content.cloneNode(true);
                openModal(formContent);
            });
        });
    </script>

    <?php if ($adminRole === 'superadmin'): ?>
        <?php foreach ($customers as $customer): ?>
            <template id="edit-customer-<?= $customer['id'] ?>">
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="customer_id" value="<?= $customer['id'] ?>">
                    <div class="space-y-2">
                        <label class="text-sm text-slate-300" for="customer-name-<?= $customer['id'] ?>">Name</label>
                        <input id="customer-name-<?= $customer['id'] ?>" type="text" name="customer_name" value="<?= htmlspecialchars($customer['name']) ?>" class="w-full px-3 py-2 rounded-lg bg-slate-800 border border-slate-700" required>
                    </div>
                    <div class="space-y-2">
                        <label class="text-sm text-slate-300" for="customer-phone-<?= $customer['id'] ?>">Phone</label>
                        <input id="customer-phone-<?= $customer['id'] ?>" type="tel" name="customer_phone" value="<?= htmlspecialchars($customer['phone']) ?>" class="w-full px-3 py-2 rounded-lg bg-slate-800 border border-slate-700" required>
                    </div>
                    <div class="space-y-2">
                        <label class="text-sm text-slate-300" for="customer-email-<?= $customer['id'] ?>">Email</label>
                        <input id="customer-email-<?= $customer['id'] ?>" type="email" name="customer_email" value="<?= htmlspecialchars($customer['email'] ?? '') ?>" class="w-full px-3 py-2 rounded-lg bg-slate-800 border border-slate-700">
                    </div>
                    <div class="flex justify-end gap-3">
                        <button type="button" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg" onclick="document.querySelector('[data-modal-close]')?.click();">Cancel</button>
                        <button name="action" value="update_customer" class="px-4 py-2 bg-blue-600 hover:bg-blue-500 rounded-lg">Save Changes</button>
                    </div>
                </form>
            </template>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php foreach ($admins as $admin): ?>
        <template id="edit-admin-<?= $admin['id'] ?>">
            <form method="POST" class="space-y-4">
                <input type="hidden" name="admin_id" value="<?= $admin['id'] ?>">
                <div class="space-y-2">
                    <label class="text-sm text-slate-300" for="admin-username-<?= $admin['id'] ?>">Username</label>
                    <input id="admin-username-<?= $admin['id'] ?>" type="text" name="admin_username" value="<?= htmlspecialchars($admin['username']) ?>" class="w-full px-3 py-2 rounded-lg bg-slate-800 border border-slate-700" required>
                </div>
                <div class="space-y-2">
                    <label class="text-sm text-slate-300" for="admin-role-<?= $admin['id'] ?>">Role</label>
                    <select id="admin-role-<?= $admin['id'] ?>" name="admin_role" class="w-full px-3 py-2 rounded-lg bg-slate-800 border border-slate-700">
                        <option value="cashier" <?= $admin['role'] === 'cashier' ? 'selected' : '' ?>>Cashier</option>
                        <option value="superadmin" <?= $admin['role'] === 'superadmin' ? 'selected' : '' ?>>Superadmin</option>
                    </select>
                </div>
                <div class="space-y-2">
                    <label class="text-sm text-slate-300" for="admin-password-<?= $admin['id'] ?>">New Password (optional)</label>
                    <input id="admin-password-<?= $admin['id'] ?>" type="password" name="admin_password" class="w-full px-3 py-2 rounded-lg bg-slate-800 border border-slate-700" placeholder="Leave blank to keep current password">
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg" onclick="document.querySelector('[data-modal-close]')?.click();">Cancel</button>
                    <button name="action" value="update_admin" class="px-4 py-2 bg-blue-600 hover:bg-blue-500 rounded-lg">Save Changes</button>
                </div>
            </form>
        </template>
    <?php endforeach; ?>
</body>
</html>
