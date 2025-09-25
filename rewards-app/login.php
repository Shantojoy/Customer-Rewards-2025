<?php
session_start();
require_once __DIR__ . '/config.php';

$error = '';

if (isset($_SESSION['admin_id'])) {
    header('Location: admin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $stmt = $conn->prepare('SELECT id, username, role FROM admins WHERE username = ? AND password = SHA2(?, 256)');
        $stmt->bind_param('ss', $username, $password);
        $stmt->execute();
        $result = $stmt->get_result();
        $admin = $result->fetch_assoc();
        $stmt->close();

        if ($admin) {
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            $_SESSION['admin_role'] = $admin['role'];
            header('Location: admin.php');
            exit;
        } else {
            $error = 'Invalid credentials. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Rewards System</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen flex items-center justify-center bg-slate-900 text-white">
    <div class="w-full max-w-md bg-slate-800/70 rounded-3xl p-8 shadow-2xl">
        <h1 class="text-2xl font-bold text-center mb-6">Rewards Dashboard Login</h1>
        <?php if ($error): ?>
            <div class="mb-4 p-3 text-sm bg-red-500/20 border border-red-500 text-red-200 rounded-xl text-center">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        <form method="POST" class="space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1" for="username">Username</label>
                <input type="text" name="username" id="username" required class="w-full px-4 py-3 rounded-xl bg-slate-900/60 border border-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Enter username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1" for="password">Password</label>
                <input type="password" name="password" id="password" required class="w-full px-4 py-3 rounded-xl bg-slate-900/60 border border-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Enter password">
            </div>
            <button type="submit" class="w-full py-3 bg-blue-600 hover:bg-blue-500 rounded-xl text-lg font-semibold">Log In</button>
        </form>
    </div>
</body>
</html>
