<?php
require_once '../includes/security.php';
require_once '../includes/mailer.php';

if (isset($_SESSION['user_id'])) {
    header('Location: ' . ($_SESSION['role'] === 'admin'
        ? '/Margaux_Collections/admin/dashboard.php'
        : '/Margaux_Collections/customer/products.php'));
    exit;
}

require_once '../config/database.php';
$db    = getDB();
$error = '';

// Accounts that skip the OTP step (e.g. for teacher/panel checking during defense).
// Keep this list short and only ever include trusted admin accounts you control.
$OTP_BYPASS_EMAILS = [
    'vinc.atis.ui@phinmaed.com',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step']) && $_POST['step'] === '1') {
    csrf_verify();

    if (rate_limit_check('login')) {
        $error = 'Too many login attempts. Please wait 5 minutes and try again.';
    } else {
        $email    = clean($_POST['email'] ?? '', 150);
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = 'Please enter your email and password.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email format.';
        } else {
            $stmt = $db->prepare("SELECT user_id, name, role, password FROM users WHERE email=? LIMIT 1");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($user && password_verify($password, $user['password'])) {
                rate_limit_clear('login');

                if (in_array($email, $OTP_BYPASS_EMAILS, true)) {
                    // Skip OTP entirely for whitelisted accounts.
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['name']    = $user['name'];
                    $_SESSION['role']    = $user['role'];

                    header('Location: ' . ($user['role'] === 'admin'
                        ? '/Margaux_Collections/admin/dashboard.php'
                        : '/Margaux_Collections/customer/products.php'));
                    exit;
                }

                $otp = generate_otp();
                store_otp($db, $email, 'login', $otp, 300);

                $sent = send_mail(
                    $email,
                    $user['name'],
                    'Your Margaux Collections sign-in code',
                    otp_email_html($otp, 'login', 5)
                );

                if ($sent) {
                    $_SESSION['otp_pending'] = [
                        'user_id'       => $user['user_id'],
                        'name'          => $user['name'],
                        'role'          => $user['role'],
                        'email'         => $email,
                        'issued_at'     => time(),
                    ];
                    header('Location: verify_otp.php');
                    exit;
                } else {
                    $error = 'Failed to send OTP email. Please try again or contact support.';
                }
            } else {
                rate_limit_increment('login');
                $error = 'Invalid email or password.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login — Margaux Collections</title>
<link rel="icon" type="image/x-icon" href="/Margaux_Collections/favicon.ico"> 
<link rel="icon" type="image/png" sizes="32x32" href="/Margaux_Collections/favicon-32x32.png">
<link rel="apple-touch-icon" href="/Margaux_Collections/apple-touch-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Jost', sans-serif;
    min-height: 100vh;
    display: flex;
    background: linear-gradient(to right, #0e0507 0%, #1a0a0e 25%, #2a0d14 55%, #3d1020 78%, #4a1020 100%);
    color: #f0e6da;
    overflow-x: hidden;
}
a { text-decoration: none; }

.auth-wrapper {
    display: flex;
    width: 100%;
    min-height: 100vh;
    position: relative;
}

/* Radial glow on right */
.auth-wrapper::after {
    content: '';
    position: fixed;
    top: 0; right: 0;
    width: 60%; height: 100%;
    background: radial-gradient(ellipse at 75% 45%, rgba(196,80,100,0.16) 0%, transparent 68%);
    pointer-events: none;
    z-index: 0;
}

/* ── LEFT PANEL ── */
.left {
    width: 48%;
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 80px 64px;
    position: relative;
    z-index: 1;
    flex-shrink: 0;
}

/* Decorative circles */
.left::before {
    content: '';
    position: absolute;
    top: -120px; left: -120px;
    width: 460px; height: 460px;
    border-radius: 50%;
    background: rgba(196, 80, 100, 0.07);
    pointer-events: none;
}
.left::after {
    content: '';
    position: absolute;
    bottom: -90px; left: 80px;
    width: 280px; height: 280px;
    border-radius: 50%;
    background: rgba(196, 80, 100, 0.04);
    pointer-events: none;
}

/* Brand logo */
.brand-logo {
    display: flex;
    align-items: center;
    gap: 18px;
    margin-bottom: 80px;
}
.logo-img-circle {
    width: 56px; height: 56px;
    border-radius: 50%;
    border: 1.5px solid #c45064;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; overflow: hidden;
    background: rgba(196, 80, 100, 0.1);
}
.logo-img-circle img { width: 100%; height: 100%; object-fit: cover; }
.logo-fallback {
    font-family: 'Playfair Display', serif;
    font-size: 22px; color: #c45064;
}
.brand-name {
    font-family: 'Playfair Display', serif;
    font-size: 17px; color: #e8d5c4;
    letter-spacing: .5px; line-height: 1.3;
}
.brand-name span {
    display: block;
    font-family: 'Jost', sans-serif;
    font-size: 9px; letter-spacing: 4px;
    color: #c45064; font-weight: 500;
    margin-top: 5px; text-transform: uppercase;
}

/* Hero text */
.hero-text { margin-bottom: 0; }

.eyebrow {
    font-family: 'Jost', sans-serif;
    font-size: 10px; letter-spacing: 4.5px;
    color: #c45064; text-transform: uppercase;
    font-weight: 500; margin-bottom: 24px;
    display: flex; align-items: center; gap: 12px;
}
.eyebrow::before {
    content: '';
    display: inline-block;
    width: 32px; height: 1px;
    background: #c45064; opacity: .6;
}

.hero-text h2 {
    font-family: 'Playfair Display', serif;
    font-size: 68px;
    color: #f0e6da;
    line-height: 1.0;
    font-weight: 700;
    margin-bottom: 30px;
    letter-spacing: -1px;
}
.hero-text h2 em { color: #c45064; font-style: italic; }

.hero-text p {
    color: #7a6058;
    font-size: 14.5px;
    font-weight: 300;
    line-height: 2.0;
    max-width: 340px;
    border-left: 2px solid rgba(196, 80, 100, 0.3);
    padding-left: 18px;
}

/* ── RIGHT PANEL ── */
.right {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 80px 70px;
    position: relative;
    z-index: 1;
}

.form-card { width: 100%; max-width: 400px; }

/* Form header */
.form-header { margin-bottom: 36px; }
.form-header h1 {
    font-family: 'Playfair Display', serif;
    font-size: 32px; color: #f0e6da;
    font-weight: 700; line-height: 1.15;
    margin-bottom: 10px;
}
.form-header p {
    color: #5a4a42; font-size: 13.5px;
    font-weight: 300; line-height: 1.6;
}

/* Alerts */
.alert {
    border-radius: 9px; padding: 12px 16px;
    font-size: .84rem; margin-bottom: 20px;
    display: flex; align-items: flex-start;
    gap: 10px; line-height: 1.6;
}
.alert-error   { background: rgba(196,80,100,0.10); border: .5px solid rgba(196,80,100,0.35); color: #e8a0a8; }
.alert-info    { background: rgba(196,80,100,0.07); border: .5px solid rgba(196,80,100,0.25); color: #c89090; }
.alert-success { background: rgba(80,160,100,0.08); border: .5px solid rgba(80,160,100,0.25); color: #86c49a; }

/* Form fields */
.form-group { margin-bottom: 20px; }

label {
    display: block; font-size: 10px;
    letter-spacing: 2px; color: #7a6058;
    font-weight: 500; text-transform: uppercase;
    margin-bottom: 9px;
}

input[type=email],
input[type=password],
input[type=text] {
    width: 100%; padding: 14px 16px;
    background: rgba(255,255,255,0.04);
    border: .5px solid rgba(196,80,100,0.22);
    border-radius: 9px; font-size: 14px;
    font-family: 'Jost', sans-serif; font-weight: 300;
    color: #f0e6da; outline: none;
    transition: border-color .2s, background .2s, box-shadow .2s;
}
input::placeholder { color: #3a2a28; }
input:focus {
    border-color: #c45064;
    background: rgba(196,80,100,0.06);
    box-shadow: 0 0 0 3px rgba(196,80,100,0.1);
}

/* Password toggle */
.pw-wrap { position: relative; display: block; }
.pw-wrap input { padding-right: 48px !important; }
.show-hide {
    position: absolute; right: 14px; top: 50%;
    transform: translateY(-50%);
    background: none; border: none; color: #5a4a42;
    cursor: pointer; padding: 4px;
    display: flex; align-items: center; justify-content: center;
    transition: color .2s; line-height: 0;
}
.show-hide:hover { color: #c45064; }
.show-hide svg { display: block; flex-shrink: 0; }

/* Submit button */
.btn {
    display: flex; align-items: center;
    justify-content: center; gap: 10px;
    width: 100%; padding: 15px;
    background: #c45064; color: #fff;
    border: none; border-radius: 9px;
    font-size: 12px; font-weight: 500;
    font-family: 'Jost', sans-serif;
    letter-spacing: 3px; text-transform: uppercase;
    cursor: pointer; margin-top: 8px;
    transition: background .2s, transform .1s;
}
.btn:hover  { background: #a83d53; }
.btn:active { transform: scale(.98); }
.btn svg {
    width: 15px; height: 15px;
    stroke: white; fill: none;
    stroke-width: 2; stroke-linecap: round;
    stroke-linejoin: round; flex-shrink: 0;
}

.forgot {
    display: block; text-align: center;
    font-size: 12px; color: #5a4a42;
    margin-top: 16px; font-weight: 300;
    transition: color .2s;
}
.forgot:hover { color: #c45064; }

.card-divider {
    height: .5px;
    background: rgba(196,80,100,0.12);
    margin: 28px 0;
}
.bottom {
    text-align: center; font-size: 13px;
    color: #5a4a42; font-weight: 300;
}
.bottom a { color: #c45064; font-weight: 500; }
.bottom a:hover { color: #e07080; }

/* ── RESPONSIVE ── */
@media (max-width: 960px) {
    body { background: linear-gradient(to bottom, #0e0507 0%, #2a0d14 100%); }
    .auth-wrapper { flex-direction: column; }
    .auth-wrapper::after { width: 100%; height: 50%; top: 50%; }
    .left { width: 100%; padding: 48px 36px 36px; }
    .hero-text h2 { font-size: 46px; }
    .brand-logo { margin-bottom: 52px; }
    .right { padding: 36px 32px 64px; }
}
@media (max-width: 480px) {
    .left { padding: 32px 22px 28px; }
    .right { padding: 24px 20px 48px; }
    .hero-text h2 { font-size: 36px; }
    .form-header h1 { font-size: 26px; }
    .brand-logo { margin-bottom: 40px; }
}
</style>
</head>
<body>
<div class="auth-wrapper">

  <!-- LEFT PANEL -->
  <div class="left">
    <div class="brand-logo">
      <div class="logo-img-circle">
        <img src="/Margaux_Collections/images/logo.jpg" alt="Logo"
             onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
        <span class="logo-fallback" style="display:none">M</span>
      </div>
      <div class="brand-name">
        Margaux Collections
        <span>+ Fashion Boutique</span>
      </div>
    </div>

    <div class="hero-text">
      <div class="eyebrow">Fashion Boutique</div>
      <h2>Welcome<br><em>Back</em></h2>
      <p>Sign in to explore curated brand-new outfits
         and pre-loved designer pieces made just for you.</p>
    </div>
  </div>

  <!-- RIGHT PANEL -->
  <div class="right">
    <div class="form-card">

      <div class="form-header">
        <h1>Sign In</h1>
        <p>Enter your credentials to continue.</p>
      </div>

      <?php if (isset($_GET['reason']) && $_GET['reason'] === 'timeout'): ?>
        <div class="alert alert-info">&#9201; Your session expired. Please log in again.</div>
      <?php endif; ?>

      <?php if (isset($_GET['registered']) && $_GET['registered'] === '1'): ?>
        <div class="alert alert-success">&#10003; Account created! Please sign in below.</div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="alert alert-error">&#9888; <?= e($error) ?></div>
      <?php endif; ?>

      <form method="POST" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="step" value="1">

        <div class="form-group">
          <label>Email Address</label>
          <input type="email" name="email"
                 value="<?= e($_POST['email'] ?? '') ?>"
                 placeholder="your@email.com"
                 required maxlength="150" autofocus>
        </div>

        <div class="form-group">
          <label>Password</label>
          <div class="pw-wrap">
            <input type="password" name="password" id="passField"
                   placeholder="Enter your password"
                   required maxlength="255">
            <button type="button" class="show-hide" onclick="togglePass()" aria-label="Toggle password">
              <svg id="eyeIcon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                <circle cx="12" cy="12" r="3"/>
              </svg>
              <svg id="eyeOffIcon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none">
                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                <line x1="1" y1="1" x2="23" y2="23"/>
              </svg>
            </button>
          </div>
        </div>

        <button type="submit" class="btn">
          Continue
          <svg viewBox="0 0 24 24">
            <line x1="5" y1="12" x2="19" y2="12"/>
            <polyline points="12 5 19 12 12 19"/>
          </svg>
        </button>

        <a href="forgot_password.php" class="forgot">Forgot password?</a>
      </form>

      <div class="card-divider"></div>
      <p class="bottom">Don&#39;t have an account? <a href="register.php">Create one</a></p>

    </div>
  </div>

</div>

<script>
function togglePass() {
    var f = document.getElementById('passField');
    var show = f.type === 'password';
    f.type = show ? 'text' : 'password';
    document.getElementById('eyeIcon').style.display    = show ? 'none' : '';
    document.getElementById('eyeOffIcon').style.display = show ? '' : 'none';
}
</script>
</body>
</html>
