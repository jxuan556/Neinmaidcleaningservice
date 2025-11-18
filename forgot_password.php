<?php
// forgot_password.php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Forgot Password – NeinMaid</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
  <style>
    :root{
      /* Base */
      --bg-grad: linear-gradient(180deg,#ffffff 0%, #fafafa 40%, #fff4f9 100%);
      --ink:#0f172a;
      --muted:#6b7280;
      --line:#e5e7eb;
      --card:#ffffff;

      /* Accent (soft pink) */
      --pink:#ec4899;
      --pink-2:#db2777;
      --focus:#f472b6;

      /* States */
      --ok:#065f46; --ok-bg:#ecfdf5; --ok-br:#6ee7b7;
      --er:#991b1b; --er-bg:#fff1f2; --er-br:#fda4af;

      --radius:18px; --radius-sm:12px;
      --shadow:0 16px 40px rgba(17, 24, 39, .08);
    }

    *{box-sizing:border-box}
    html,body{height:100%}
    body{
      margin:0; background:var(--bg-grad);
      font-family:Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      color:var(--ink);
      -webkit-font-smoothing:antialiased;
      -moz-osx-font-smoothing:grayscale;
    }

    .page{
      min-height:100svh;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:20px;
    }

    .card{
      width:100%;
      max-width:520px;
      background:var(--card);
      border:1px solid var(--line);
      border-radius:var(--radius);
      box-shadow:var(--shadow);
      padding:22px;
    }

    .brand{
      display:flex; align-items:center; gap:10px; margin-bottom:4px;
      color:var(--pink); text-transform:uppercase; font-weight:900;
      letter-spacing:.08em; font-size:13px;
    }
    .brand .dot{width:8px;height:8px;border-radius:999px;background:var(--pink)}
    .title{margin:.25rem 0 0;font-size:26px;font-weight:900}
    .slogan{margin:4px 0 18px;color:var(--muted);font-size:14px}

    .field{margin-bottom:12px}
    .input{
      width:100%; padding:12px 12px; border:1px solid var(--line); border-radius:12px;
      background:#fafafa; color:var(--ink); font-size:15px;
      transition:border-color .2s, background .2s, box-shadow .2s;
    }
    .input:focus{
      outline:none; border-color:var(--pink); background:#fff;
      box-shadow:0 0 0 3px rgba(244,114,182,.25);
    }

    .btn{
      width:100%; padding:12px 14px; border:0; border-radius:12px;
      cursor:pointer; font-weight:800; font-size:15px;
      background:linear-gradient(90deg, var(--pink), var(--pink-2));
      color:#fff;
      transition:transform .06s ease-in-out, filter .2s;
    }
    .btn:hover{ filter:brightness(1.03) }
    .btn:active{ transform:translateY(1px) }

    .subtle{
      text-align:center; color:var(--muted); font-size:14px; margin-top:14px;
    }
    .link{color:var(--pink); text-decoration:none}
    .link:hover{text-decoration:underline}

    .alert{
      border-radius:12px;
      padding:10px 12px;
      margin-bottom:12px;
      font-size:14px;
    }
    .alert.error{background:var(--er-bg);border:1px solid var(--er-br);color:var(--er)}
    .alert.success{background:var(--ok-bg);border:1px solid var(--ok-br);color:var(--ok)}

    @media (max-width:520px){
      .card{padding:18px}
      .title{font-size:24px}
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
      <p class="slogan">We’ll send a reset link to your email.</p>

      <?php if (!empty($_SESSION['reset_error'])): ?>
        <div class="alert error">
          <?= htmlspecialchars($_SESSION['reset_error'], ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <?php $_SESSION['reset_error'] = null; ?>
      <?php endif; ?>

      <?php if (!empty($_SESSION['reset_success'])): ?>
        <div class="alert success">
          <?= htmlspecialchars($_SESSION['reset_success'], ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <?php $_SESSION['reset_success'] = null; ?>
      <?php endif; ?>

      <form action="reset_password.php" method="POST" novalidate>
        <div class="field">
          <input class="input" type="email" name="email"
                 placeholder="Email address" required
                 autocomplete="email" inputmode="email">
        </div>
        <button type="submit" class="btn">Send reset link</button>
      </form>

      <p class="subtle">
        Remembered your password?
        <a class="link" href="login.php">Sign in</a>
      </p>
    </div>
  </div>
</body>
</html>
