<?php
require_once __DIR__ . '/auth/Auth.php';
require_once __DIR__ . '/auth/InitializeAdmin.php';
require_once __DIR__ . '/auth/LoginSecurity.php';

// ── reCAPTCHA v2 Configuration (loaded from .env) ────────────────────────
require_once __DIR__ . '/config/Database.php';
define('RECAPTCHA_SITE_KEY',   Database::env('RECAPTCHA_SITE_KEY'));
define('RECAPTCHA_SECRET_KEY', Database::env('RECAPTCHA_SECRET_KEY'));

/**
 * Verify reCAPTCHA token with Google's API
 */
function verifyCaptcha(string $token): bool {
    if (empty($token)) return false;

    $response = file_get_contents('https://www.google.com/recaptcha/api/siteverify?' . http_build_query([
        'secret'   => RECAPTCHA_SECRET_KEY,
        'response' => $token,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]));

    if ($response === false) return false;

    $data = json_decode($response, true);
    return isset($data['success']) && $data['success'] === true;
}

// Initialize default admin account if needed
InitializeAdmin::ensureDefaultAdmin();

Auth::init();
if (Auth::check()) {
    if (Auth::isFirstLogin()) {
        header('Location: /pages/change_password.php');
    } else {
        header('Location: /pages/dashboard.php');
    }
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username     = trim($_POST['username'] ?? '');
    $password     = $_POST['password'] ?? '';
    $captchaToken = $_POST['g-recaptcha-response'] ?? '';

    // ── Verify CAPTCHA first ──────────────────────────────────────────────
    if (!verifyCaptcha($captchaToken)) {
        $error = 'Please complete the CAPTCHA verification.';
    } else {
        // ── Check if account is locked ────────────────────────────────────
        $lockStatus = LoginSecurity::checkAccountLock($username);
        if ($lockStatus['locked']) {
            $error = $lockStatus['message'];
        } elseif (Auth::login($username, $password)) {
            $user = Auth::user();
            if ($user && $user['first_login'] === 1) {
                header('Location: /pages/change_password.php');
            } else {
                header('Location: /pages/dashboard.php');
            }
            exit;
        } else {
            $attemptStats = LoginSecurity::getAttemptStats($username);
            if ($attemptStats['locked']) {
                $error = "Account temporarily locked due to multiple failed attempts. Please try again later.";
            } else {
                $remaining = 5 - $attemptStats['attempts'];
                if ($remaining > 0) {
                    $error = "Invalid username or password. ({$remaining} attempt(s) remaining before account lockout)";
                } else {
                    $error = "Too many failed login attempts. Your account has been locked.";
                }
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
<title>Kusinero — Login</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<!-- Google reCAPTCHA v2 -->
<script src="https://www.google.com/recaptcha/api.js" async defer></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0e0a06;--surface:#1a1208;--card:#211608;
  --gold:#c9933a;--gold-light:#e8b86d;--cream:#f5ead8;
  --muted:#7a6448;--border:#3a2a14;--red:#e05c3a;
}
body{background:var(--bg);color:var(--cream);font-family:'DM Sans',sans-serif;
     min-height:100vh;display:grid;place-items:center;
     background-image: radial-gradient(ellipse at 20% 50%, #2a1a06 0%, transparent 60%),
                       radial-gradient(ellipse at 80% 20%, #1e1204 0%, transparent 50%);}

.login-wrap{width:100%;max-width:420px;padding:16px;}

.brand{text-align:center;margin-bottom:36px;}
.brand-icon{font-size:3rem;margin-bottom:8px;display:block;}
.brand h1{font-family:'Playfair Display',serif;font-size:2.4rem;font-weight:900;
           color:var(--gold-light);letter-spacing:1px;line-height:1;}
.brand p{color:var(--muted);font-size:.85rem;margin-top:6px;letter-spacing:2px;
          text-transform:uppercase;}

.card{background:var(--card);border:1px solid var(--border);border-radius:14px;
      padding:32px;box-shadow:0 20px 60px rgba(0,0,0,.5);}

.card h2{font-family:'Playfair Display',serif;font-size:1.3rem;color:var(--cream);
          margin-bottom:22px;border-bottom:1px solid var(--border);padding-bottom:14px;}

label{display:block;font-size:.75rem;letter-spacing:1px;text-transform:uppercase;
      color:var(--muted);margin-bottom:6px;}
input{width:100%;padding:11px 14px;background:#110d06;color:var(--cream);
      border:1px solid var(--border);border-radius:8px;font-size:.95rem;
      font-family:'DM Sans',sans-serif;margin-bottom:16px;transition:border .2s;}
input:focus{outline:none;border-color:var(--gold);}

/* ── Password wrapper with eye toggle ── */
.password-wrap{position:relative;margin-bottom:16px;}
.password-wrap input{margin-bottom:0;padding-right:44px;}
.toggle-pw{position:absolute;right:12px;top:50%;transform:translateY(-50%);
           background:none;border:none;cursor:pointer;padding:4px;
           color:var(--muted);transition:color .2s;line-height:1;}
.toggle-pw:hover{color:var(--gold);}
.toggle-pw svg{display:block;width:18px;height:18px;}

/* reCAPTCHA wrapper — centers the widget and applies dark theme filter */
.captcha-wrap{
    display:flex;
    justify-content:center;
    margin-bottom:18px;
    filter: invert(0.85) hue-rotate(170deg);
}

.btn{width:100%;padding:12px;background:var(--gold);color:#0e0a06;
     border:none;border-radius:8px;font-size:.95rem;font-weight:700;
     font-family:'DM Sans',sans-serif;cursor:pointer;letter-spacing:.5px;
     transition:background .2s,transform .1s;}
.btn:hover{background:var(--gold-light);}
.btn:active{transform:scale(.98);}

.error{background:rgba(224,92,58,.12);border:1px solid rgba(224,92,58,.4);
       color:#e8937a;padding:10px 14px;border-radius:7px;font-size:.85rem;margin-bottom:16px;}

.hint{background:rgba(201,147,58,.06);border:1px solid rgba(201,147,58,.2);
      border-radius:8px;padding:14px;margin-top:20px;font-size:.8rem;color:var(--muted);line-height:1.8;}
.hint strong{color:var(--gold-light);display:block;margin-bottom:4px;}
code{background:#0e0a06;color:var(--gold);padding:1px 6px;border-radius:4px;font-size:.8rem;}

@keyframes fadeIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
.login-wrap{animation:fadeIn .4s ease}
</style>
</head>
<body>
<div class="login-wrap">
  <div class="brand">
    <span class="brand-icon">🍽️</span>
    <h1>Kusinero</h1>
    <p>Restaurant Management</p>
  </div>
  <div class="card">
    <h2>Login</h2>

    <?php if ($error): ?>
      <div class="error">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
      <label>Username</label>
      <input type="text" name="username" autofocus required autocomplete="username">

      <label>Password</label>
      <div class="password-wrap">
        <input type="password" id="passwordInput" name="password" required autocomplete="current-password">
        <button type="button" class="toggle-pw" onclick="togglePassword()" title="Show/hide password" aria-label="Toggle password visibility">
          <!-- Eye icon (visible when password is hidden) -->
          <svg id="eyeIcon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
            <circle cx="12" cy="12" r="3"/>
          </svg>
          <!-- Eye-off icon (visible when password is shown) -->
          <svg id="eyeOffIcon" style="display:none" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
            <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
            <line x1="1" y1="1" x2="23" y2="23"/>
          </svg>
        </button>
      </div>

      <!-- Google reCAPTCHA v2 widget -->
      <div class="captcha-wrap">
        <div class="g-recaptcha" data-sitekey="<?= RECAPTCHA_SITE_KEY ?>"></div>
      </div>

      <button class="btn" type="submit">Sign In →</button>
    </form>

    <?php
    // Show default credentials hint whenever the auto-created admin hasn't logged in yet
    $db2  = Database::get();
    $chk2 = $db2->query("SELECT username FROM users WHERE username='admin' AND first_login=1 LIMIT 1")->fetch();
    if ($chk2):
    ?>
    <div class="hint">
      <strong>⚠️ Default Admin Account Active</strong>
      Username: <code>admin</code> &nbsp;&nbsp;Password: <code>Admin@123</code><br>
      You will be asked to set a new password after logging in.
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
function togglePassword() {
    const input  = document.getElementById('passwordInput');
    const eyeOn  = document.getElementById('eyeIcon');
    const eyeOff = document.getElementById('eyeOffIcon');
    const showing = input.type === 'text';

    input.type           = showing ? 'password' : 'text';
    eyeOn.style.display  = showing ? 'block'    : 'none';
    eyeOff.style.display = showing ? 'none'     : 'block';
}
</script>
</body>
</html>