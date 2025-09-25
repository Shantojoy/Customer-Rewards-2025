<?php
require_once __DIR__ . '/config.php';

$errors = [];
$successMessage = '';
$customer = null;
$phoneInput = '';
$showNewCustomerForm = false;

function sanitize_phone(string $phone): string {
    return preg_replace('/[^0-9]/', '', $phone);
}

function is_valid_phone(string $phone): bool {
    return strlen($phone) >= 7 && strlen($phone) <= 15;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'check_phone') {
        $phoneInput = sanitize_phone($_POST['phone'] ?? '');

        if (!is_valid_phone($phoneInput)) {
            $errors[] = 'Please enter a valid phone number.';
        } else {
            $stmt = $conn->prepare('SELECT id, phone, name, email FROM customers WHERE phone = ?');
            $stmt->bind_param('s', $phoneInput);
            $stmt->execute();
            $result = $stmt->get_result();
            $customer = $result->fetch_assoc();
            $stmt->close();

            if (!$customer) {
                $showNewCustomerForm = true;
            }
        }
    } elseif ($action === 'create_customer') {
        $phoneInput = sanitize_phone($_POST['phone'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if (!is_valid_phone($phoneInput)) {
            $errors[] = 'Invalid phone number. Please use digits only.';
        }

        if ($name === '') {
            $errors[] = 'Name is required.';
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please provide a valid email address.';
        }

        if (empty($errors)) {
            // Ensure the phone number is still unique.
            $stmt = $conn->prepare('SELECT id FROM customers WHERE phone = ?');
            $stmt->bind_param('s', $phoneInput);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows > 0) {
                $errors[] = 'This phone number is already registered. Please try again.';
            }
            $stmt->close();
        }

        if (empty($errors)) {
            $stmt = $conn->prepare('INSERT INTO customers (phone, name, email) VALUES (?, ?, NULLIF(?, \'\'))');
            $stmt->bind_param('sss', $phoneInput, $name, $email);
            if ($stmt->execute()) {
                $successMessage = 'Welcome! You have been enrolled in the rewards program.';
                $customer = [
                    'id' => $stmt->insert_id,
                    'phone' => $phoneInput,
                    'name' => $name,
                    'email' => $email,
                ];
            } else {
                $errors[] = 'Unable to create customer. Please try again later.';
            }
            $stmt->close();
        }

        if (!empty($errors)) {
            $showNewCustomerForm = true;
        }
    }

    if ($customer) {
        $stmt = $conn->prepare('SELECT COALESCE(SUM(points), 0) AS balance FROM points_transactions WHERE customer_id = ?');
        $stmt->bind_param('i', $customer['id']);
        $stmt->execute();
        $balanceResult = $stmt->get_result()->fetch_assoc();
        $customer['points_balance'] = (int)($balanceResult['balance'] ?? 0);
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hashtag Smoke Shop Rewards</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-900 text-white flex flex-col">
    <div class="flex items-center justify-between p-6">
        <div class="flex items-center space-x-4">
            <div class="bg-white rounded-full p-3">
                <span class="text-2xl font-bold text-slate-900">#</span>
            </div>
            <h1 class="text-3xl font-semibold">Hashtag Smoke Shop</h1>
        </div>
        <button id="fullscreenBtn" class="px-4 py-2 bg-blue-600 hover:bg-blue-500 rounded-lg text-white text-sm font-medium">
            Toggle Fullscreen
        </button>
    </div>

    <main class="flex-1 flex flex-col items-center justify-center px-4">
        <div class="w-full max-w-3xl bg-slate-800/60 rounded-3xl p-8 shadow-xl">
            <h2 class="text-center text-2xl md:text-3xl font-bold mb-6">Enter Your Phone Number to Earn Rewards</h2>

            <?php if (!empty($errors)): ?>
                <div class="mb-4 p-4 bg-red-500/20 border border-red-500 text-red-200 rounded-xl">
                    <ul class="list-disc list-inside space-y-1">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($successMessage): ?>
                <div class="mb-4 p-4 bg-green-500/20 border border-green-500 text-green-200 rounded-xl">
                    <?= htmlspecialchars($successMessage) ?>
                </div>
            <?php endif; ?>

            <?php if ($customer && !$showNewCustomerForm): ?>
                <div class="space-y-6 text-center">
                    <div>
                        <p class="text-xl font-semibold">Welcome back, <?= htmlspecialchars($customer['name']) ?>!</p>
                        <p class="text-lg text-blue-200">Phone: <?= htmlspecialchars($customer['phone']) ?></p>
                    </div>
                    <div class="bg-slate-900/60 rounded-2xl p-6">
                        <?php $progress = $customer['points_balance'] % 200; ?>
                        <p class="text-4xl font-bold text-green-400"><?= $customer['points_balance'] ?> points</p>
                        <p class="mt-2 text-sm uppercase tracking-widest text-slate-300">Every 200 points = $5 reward</p>
                        <div class="mt-4 bg-slate-700 rounded-full h-4 overflow-hidden">
                            <div class="h-4 bg-blue-500" style="width: <?= min(100, ($progress / 200) * 100) ?>%"></div>
                        </div>
                        <p class="mt-2 text-sm text-slate-300">Progress: <?= $progress ?>/200</p>
                    </div>
                    <button onclick="window.location.href='index.php'" class="px-6 py-3 bg-blue-600 hover:bg-blue-500 rounded-xl text-lg font-semibold">New Entry</button>
                </div>
            <?php elseif ($showNewCustomerForm): ?>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="create_customer">
                    <input type="hidden" name="phone" value="<?= htmlspecialchars($phoneInput) ?>">
                    <div>
                        <label class="block text-left text-sm font-medium mb-1">Phone Number</label>
                        <div class="px-4 py-3 bg-slate-900/60 rounded-xl border border-slate-700 text-lg tracking-widest">
                            <?= htmlspecialchars($phoneInput) ?>
                        </div>
                    </div>
                    <div>
                        <label class="block text-left text-sm font-medium mb-1">Name</label>
                        <input type="text" name="name" required class="w-full px-4 py-3 rounded-xl bg-slate-900/60 border border-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Your Name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block text-left text-sm font-medium mb-1">Email (optional)</label>
                        <input type="email" name="email" class="w-full px-4 py-3 rounded-xl bg-slate-900/60 border border-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="you@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>
                    <button type="submit" class="w-full py-3 bg-green-600 hover:bg-green-500 rounded-xl text-lg font-semibold">Join Rewards</button>
                    <button type="button" onclick="window.location.href='index.php'" class="w-full py-3 bg-slate-700 hover:bg-slate-600 rounded-xl text-lg font-semibold">Start Over</button>
                </form>
            <?php else: ?>
                <form method="POST" id="phoneForm" class="space-y-6">
                    <input type="hidden" name="action" value="check_phone">
                    <input type="hidden" id="phoneInput" name="phone" value="<?= htmlspecialchars($phoneInput) ?>">
                    <div class="bg-slate-900/60 rounded-2xl px-6 py-6 text-center text-4xl tracking-widest font-mono" id="displayPhone"><?= $phoneInput !== '' ? htmlspecialchars($phoneInput) : '•••••••••' ?></div>
                    <div class="grid grid-cols-3 gap-4 text-center">
                        <?php
                        $keys = ['1','2','3','4','5','6','7','8','9','clear','0','submit'];
                        foreach ($keys as $key):
                        ?>
                            <?php if ($key === 'clear'): ?>
                                <button type="button" data-key="clear" class="py-5 bg-red-600 hover:bg-red-500 rounded-2xl text-2xl font-semibold">Clear</button>
                            <?php elseif ($key === 'submit'): ?>
                                <button type="submit" class="py-5 bg-green-600 hover:bg-green-500 rounded-2xl text-2xl font-semibold">Submit</button>
                            <?php else: ?>
                                <button type="button" data-key="<?= $key ?>" class="py-5 bg-blue-600 hover:bg-blue-500 rounded-2xl text-3xl font-bold"><?= $key ?></button>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <p class="text-center text-sm text-slate-300">Every 200 points = $5 reward</p>
                </form>
            <?php endif; ?>
        </div>
    </main>

    <script>
        const fullscreenBtn = document.getElementById('fullscreenBtn');
        fullscreenBtn?.addEventListener('click', () => {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen().catch(() => {});
            } else {
                document.exitFullscreen().catch(() => {});
            }
        });

        const phoneInput = document.getElementById('phoneInput');
        const displayPhone = document.getElementById('displayPhone');

        document.querySelectorAll('[data-key]').forEach(btn => {
            btn.addEventListener('click', () => {
                const key = btn.dataset.key;
                if (key === 'clear') {
                    phoneInput.value = '';
                } else {
                    if (phoneInput.value.length < 15) {
                        phoneInput.value += key;
                    }
                }
                displayPhone.textContent = phoneInput.value ? phoneInput.value : '•••••••••';
            });
        });
    </script>
</body>
</html>
