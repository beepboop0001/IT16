<?php
require_once __DIR__ . '/../auth/Auth.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../auth/PasswordValidator.php';

Auth::init();
Auth::requireLogin();

$u = Auth::user();
$error = '';
$success = '';

// If not on first login and no force flag, redirect to dashboard
if (!Auth::isFirstLogin() && empty($_GET['force'])) {
    header('Location: /pages/dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    // For first-time login, we validate against the default password
    // For forced changes, we validate against current password
    if (Auth::isFirstLogin()) {
        // First login: just verify current password matches what's in DB
        if (!password_verify($currentPassword, $u['password'])) {
            $error = 'Current password is incorrect.';
        } elseif (empty($newPassword)) {
            $error = 'New password is required.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'New passwords do not match.';
        } else {
            // Validate new password
            $validation = PasswordValidator::validate($newPassword);
            if (!$validation['valid']) {
                $error = implode('<br>', $validation['errors']);
            } else {
                // Update password
                if (Auth::updatePassword($u['id'], $newPassword)) {
                    // Log the password change
                    require_once __DIR__ . '/../auth/AuditLog.php';
                    AuditLog::record(
                        action:     'password_changed_first_login',
                        details:    "User \"{$u['name']}\" changed password on first login.",
                        userId:     $u['id'],
                        username:   $u['username'],
                        role:       $u['role_name']
                    );
                    
                    // Redirect to dashboard
                    header('Location: /pages/dashboard.php');
                    exit;
                } else {
                    $error = 'Failed to update password. Please try again.';
                }
            }
        }
    } else {
        // Forced password change
        if (!password_verify($currentPassword, $u['password'])) {
            $error = 'Current password is incorrect.';
        } elseif (empty($newPassword)) {
            $error = 'New password is required.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'New passwords do not match.';
        } elseif ($newPassword === $currentPassword) {
            $error = 'New password must be different from current password.';
        } else {
            // Validate new password
            $validation = PasswordValidator::validate($newPassword);
            if (!$validation['valid']) {
                $error = implode('<br>', $validation['errors']);
            } else {
                // Update password
                if (Auth::updatePassword($u['id'], $newPassword)) {
                    require_once __DIR__ . '/../auth/AuditLog.php';
                    AuditLog::record(
                        action:     'password_changed_forced',
                        details:    "User \"{$u['name']}\" completed forced password change.",
                        userId:     $u['id'],
                        username:   $u['username'],
                        role:       $u['role_name']
                    );
                    
                    header('Location: /pages/dashboard.php');
                    exit;
                } else {
                    $error = 'Failed to update password. Please try again.';
                }
            }
        }
    }
}

$pageTitle = Auth::isFirstLogin() ? 'Set Your Initial Password' : 'Change Password';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($pageTitle) ?> — Kusinero</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0e0a06;--surface:#1a1208;--card:#211608;
  --gold:#c9933a;--gold-light:#e8b86d;--cream:#f5ead8;
  --muted:#7a6448;--border:#3a2a14;--red:#e05c3a;--green:#4caf50;
}
body{background:var(--bg);color:var(--cream);font-family:'DM Sans',sans-serif;
     min-height:100vh;display:grid;place-items:center;padding:20px;
     background-image: radial-gradient(ellipse at 20% 50%, #2a1a06 0%, transparent 60%),
                       radial-gradient(ellipse at 80% 20%, #1e1204 0%, transparent 50%);}

.password-wrap{width:100%;max-width:550px;}

.brand{text-align:center;margin-bottom:32px;}
.brand-icon{font-size:3rem;margin-bottom:8px;display:block;}
.brand h1{font-family:'Playfair Display',serif;font-size:2.4rem;font-weight:900;
           color:var(--gold-light);letter-spacing:1px;line-height:1;}
.brand p{color:var(--muted);font-size:.85rem;margin-top:6px;letter-spacing:2px;
          text-transform:uppercase;}

.card{background:var(--card);border:1px solid var(--border);border-radius:14px;
      padding:32px;box-shadow:0 20px 60px rgba(0,0,0,.5);}

.card h2{font-family:'Playfair Display',serif;font-size:1.3rem;color:var(--cream);
          margin-bottom:12px;border-bottom:1px solid var(--border);padding-bottom:14px;}

.subtitle{color:var(--muted);font-size:.9rem;margin-bottom:24px;line-height:1.6;}

.form-group{margin-bottom:20px;}
.form-group label{display:block;font-size:.75rem;letter-spacing:1px;text-transform:uppercase;
                  color:var(--muted);margin-bottom:6px;}
input[type="password"]{width:100%;padding:11px 14px;background:#110d06;color:var(--cream);
      border:1px solid var(--border);border-radius:8px;font-size:.95rem;
      font-family:'DM Sans',sans-serif;transition:border .2s;}
input[type="password"]:focus{outline:none;border-color:var(--gold);}

.password-requirements{background:rgba(201,147,58,.06);border:1px solid rgba(201,147,58,.2);
                        border-radius:8px;padding:16px;margin-bottom:24px;font-size:.8rem;
                        color:var(--muted);line-height:1.8;}
.password-requirements strong{color:var(--gold-light);display:block;margin-bottom:8px;}
.requirement{display:flex;align-items:flex-start;margin-bottom:6px;}
.requirement-icon{margin-right:8px;min-width:16px;}
.requirement.valid{color:#81c784;}
.requirement.valid .requirement-icon::before{content:'✓';}
.requirement .requirement-icon{display:inline-block;width:16px;height:16px;}

.btn{width:100%;padding:12px;background:var(--gold);color:#0e0a06;
     border:none;border-radius:8px;font-size:.95rem;font-weight:700;
     font-family:'DM Sans',sans-serif;cursor:pointer;letter-spacing:.5px;
     transition:background .2s,transform .1s;}
.btn:hover{background:var(--gold-light);}
.btn:active{transform:scale(.98);}

.error{background:rgba(224,92,58,.12);border:1px solid rgba(224,92,58,.4);
       color:#e8937a;padding:12px 14px;border-radius:7px;font-size:.85rem;margin-bottom:16px;
       line-height:1.6;}

.success{background:rgba(76,175,80,.12);border:1px solid rgba(76,175,80,.4);
         color:#81c784;padding:12px 14px;border-radius:7px;font-size:.85rem;margin-bottom:16px;}

.current-user{background:rgba(201,147,58,.06);border-left:3px solid var(--gold);
              padding:12px 14px;border-radius:6px;margin-bottom:20px;font-size:.85rem;}
.current-user strong{color:var(--gold-light);}

.hint{color:var(--muted);font-size:.8rem;margin-top:10px;}

@keyframes fadeIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
.password-wrap{animation:fadeIn .4s ease}
</style>
</head>
<body>
<div class="password-wrap">
  <div class="brand">
    <span class="brand-icon">🍽️</span>
    <h1>Kusinero</h1>
    <p>Restaurant Management</p>
  </div>
  
  <div class="card">
    <h2><?= htmlspecialchars($pageTitle) ?></h2>
    
    <div class="current-user">
      👤 <strong>User:</strong> <?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['username']) ?>)
    </div>
    
    <?php if (Auth::isFirstLogin()): ?>
      <p class="subtitle">
        🔒 <strong>Security Requirement:</strong> This is your first login. For security purposes, you must set a new password before accessing the system. Please choose a strong password that meets all requirements below.
      </p>
    <?php else: ?>
      <p class="subtitle">
        🔑 <strong>Password Change Required:</strong> For your account security, please update your password now.
      </p>
    <?php endif; ?>
    
    <?php if ($error): ?>
      <div class="error">⚠️ <?= $error ?></div>
    <?php endif; ?>
    
    <form method="POST">
      <div class="form-group">
        <label>Current Password</label>
        <input type="password" name="current_password" required autocomplete="current-password" 
               placeholder="Enter your current password">
      </div>
      
      <div class="form-group">
        <label>New Password</label>
        <input type="password" name="new_password" required autocomplete="new-password"
               placeholder="Enter new password">
      </div>
      
      <div class="form-group">
        <label>Confirm New Password</label>
        <input type="password" name="confirm_password" required autocomplete="new-password"
               placeholder="Confirm new password">
      </div>
      
      <div class="password-requirements">
        <strong>✓ Password Requirements:</strong>
        <div class="requirement" id="req-length" data-check="length">
          <span class="requirement-icon">•</span>
          <span>8–16 characters</span>
        </div>
        <div class="requirement" id="req-uppercase" data-check="uppercase">
          <span class="requirement-icon">•</span>
          <span>At least one uppercase letter (A–Z)</span>
        </div>
        <div class="requirement" id="req-lowercase" data-check="lowercase">
          <span class="requirement-icon">•</span>
          <span>At least one lowercase letter (a–z)</span>
        </div>
        <div class="requirement" id="req-number" data-check="number">
          <span class="requirement-icon">•</span>
          <span>At least one number (0–9)</span>
        </div>
        <div class="requirement" id="req-special" data-check="special">
          <span class="requirement-icon">•</span>
          <span>At least one special character (!@#$%^&* etc)</span>
        </div>
      </div>
      
      <button class="btn" type="submit">Update Password →</button>
      <p class="hint">🔐 Your password will be securely updated and encrypted.</p>
    </form>
  </div>
</div>

<script>
const newPasswordInput = document.querySelector('input[name="new_password"]');
const requirements = {
  length: { check: (pwd) => pwd.length >= 8 && pwd.length <= 16, id: 'req-length' },
  uppercase: { check: (pwd) => /[A-Z]/.test(pwd), id: 'req-uppercase' },
  lowercase: { check: (pwd) => /[a-z]/.test(pwd), id: 'req-lowercase' },
  number: { check: (pwd) => /[0-9]/.test(pwd), id: 'req-number' },
  special: { check: (pwd) => /[!@#$%^&*()_+\-=\[\]{};:\'",.<>?\/\\|`~]/.test(pwd), id: 'req-special' }
};

function updateRequirements() {
  const password = newPasswordInput.value;
  
  Object.values(requirements).forEach(req => {
    const element = document.getElementById(req.id);
    if (req.check(password)) {
      element.classList.add('valid');
    } else {
      element.classList.remove('valid');
    }
  });
}

if (newPasswordInput) {
  newPasswordInput.addEventListener('input', updateRequirements);
  // Run once on page load in case autofill
  updateRequirements();
}
</script>
</body>
</html>
