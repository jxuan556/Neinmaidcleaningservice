<?php
session_start();

// Handle Flash Messages (success/error messages)
function flash($ok, $msg) {
    $_SESSION['flash_message'] = ['type' => $ok ? 'success' : 'error', 'message' => $msg];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Forgot Password – NeinMaid</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-grad: linear-gradient(180deg, #ffffff 0%, #fafafa 40%, #fff4f9 100%);
            --ink: #0f172a;
            --muted: #6b7280;
            --line: #e5e7eb;
            --card: #ffffff;
            --pink: #ec4899;
            --pink-2: #db2777;
            --focus: #f472b6;
            --ok: #065f46; --ok-bg: #ecfdf5; --ok-br: #6ee7b7;
            --er: #991b1b; --er-bg: #fff1f2; --er-br: #fda4af;
            --radius: 18px;
            --shadow: 0 16px 40px rgba(17, 24, 39, .08);
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg-grad);
            font-family: Inter, system-ui, Arial;
            color: var(--ink);
        }
        .page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .card {
            width: 100%;
            max-width: 520px;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 22px;
        }
        .title { margin: 0 0 6px; font-weight: 900; font-size: 26px; }
        .muted { color: var(--muted); margin: 0 0 14px; }
        .alert {
            border-radius: 12px;
            padding: 10px 12px;
            margin-bottom: 12px;
            font-size: 14px;
        }
        .ok { background: var(--ok-bg); border: 1px solid var(--ok-br); color: var(--ok); }
        .err { background: var(--er-bg); border: 1px solid var(--er-br); color: var(--er); }
        input {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: #fafafa;
            font-size: 15px;
            margin: 8px 0;
        }
        .btn {
            width: 100%;
            padding: 12px 14px;
            border: 0;
            border-radius: 12px;
            background: linear-gradient(90deg, var(--pink), var(--pink-2));
            color: #fff;
            font-weight: 800;
            cursor: pointer;
        }
        a { color: var(--pink); text-decoration: none; }

        /* Popup styling */
        .popup {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: #fff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            max-width: 400px;
            width: 100%;
        }
        .popup.show {
            display: block;
        }
        .popup .popup-message {
            margin: 0;
            font-size: 16px;
        }
        .popup .close-btn {
            background: #ec4899;
            color: white;
            padding: 8px 12px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            margin-top: 10px;
        }
        .popup .close-btn:hover {
            background: #db2777;
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="card">
            <div class="brand">
                <span class="dot" aria-hidden="true"></span>
                <span>NEINMAID</span>
            </div>
            <h1 class="title">Forgot Password</h1>
            <p class="muted">We’ll send a reset link to your email.</p>

            <!-- Flash message (success or error) -->
            <?php if (!empty($_SESSION['flash_message'])): $f = $_SESSION['flash_message']; unset($_SESSION['flash_message']); ?>
                <div class="alert <?php echo $f['type'] === 'error' ? 'err' : 'ok'; ?>">
                    <?php echo $f['message']; ?>
                </div>
            <?php endif; ?>

            <form action="reset_password.php" method="POST" novalidate>
                <div class="field">
                    <input class="input" type="email" name="email" placeholder="Email address" required autocomplete="email" inputmode="email">
                </div>
                <button type="submit" class="btn">Send reset link</button>
            </form>

            <p class="subtle">Remembered your password? <a class="link" href="login.php">Sign in</a></p>
        </div>
    </div>

    <!-- Popup message -->
    <div id="popup" class="popup">
        <p class="popup-message">If that email is registered, a reset link has been sent.</p>
        <button class="close-btn" onclick="closePopup()">Close</button>
    </div>

    <script>
        // Function to show the popup
        function showPopup() {
            document.getElementById('popup').classList.add('show');
        }

        // Function to close the popup
        function closePopup() {
            document.getElementById('popup').classList.remove('show');
        }

        // Show popup if flash message exists
        <?php if (!empty($_SESSION['flash_message'])): ?>
            showPopup();
        <?php endif; ?>
    </script>
</body>
</html>
