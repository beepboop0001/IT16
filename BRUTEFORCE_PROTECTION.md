# Brute-Force Login Protection Feature

## Overview
This document describes the brute-force attack prevention system that protects user accounts from unauthorized access attempts through automated login attacks.

## Features Implemented

### 1. Failed Login Attempts Tracking
- System tracks consecutive failed login attempts per user
- Counter resets to 0 on successful login
- Stored in database: `users.failed_login_attempts`

### 2. Tiered Lockout System

#### First Lockout (Level 1)
- **Trigger:** 5 consecutive failed login attempts
- **Duration:** 1 minute
- **Message:** "Account temporarily locked. Please try again in X minute(s)."
- **Database field:** `lockout_level = 1`, `lockout_until` set to current time + 1 minute

#### Second Lockout (Level 2)
- **Trigger:** 2 more failed attempts after first lockout expires
- **Duration:** 5 minutes
- **Message:** "Account temporarily locked. Please try again in X minute(s)."
- **Database field:** `lockout_level = 2`, `lockout_until` set to current time + 5 minutes

#### Subsequent Lockouts
- After Level 2 lockout expires, the user can attempt login again
- Cycle can repeat if user continues to fail

### 3. Automatic Lockout Expiration
- System automatically checks if lockout has expired
- Expired lockouts are cleared when user attempts login
- Original lockout state is recorded in audit logs

### 4. Successful Login Reset
- All lockout flags reset to 0
- Failed attempts counter reset to 0
- Lock status cleared from database
- Login recorded in audit log

---

## Database Changes

### New Columns in `users` Table

```sql
ALTER TABLE users ADD COLUMN failed_login_attempts INT DEFAULT 0;
ALTER TABLE users ADD COLUMN lockout_until DATETIME NULL;
ALTER TABLE users ADD COLUMN lockout_level INT DEFAULT 0;
```

### Column Descriptions

| Column | Type | Purpose |
|--------|------|---------|
| `failed_login_attempts` | INT | Counter of consecutive failed login attempts |
| `lockout_until` | DATETIME | Timestamp when current lockout expires (NULL if not locked) |
| `lockout_level` | INT | 0=not locked, 1=first lockout, 2=second lockout |

---

## System Logic

### Login Flow Diagram

```
User submits login form
    ↓
Check if account exists
    ↓
Is account locked? (lockout_until > NOW())
    ├─ YES → Check if lock expired
    │         ├─ NOT expired → Deny login, show lockout message, log attempt
    │         └─ EXPIRED → Clear lock, proceed
    │
    └─ NO → Proceed
    ↓
Verify password
    ├─ CORRECT → 
    │   ├─ Reset failed_login_attempts = 0
    │   ├─ Clear lockout_until = NULL
    │   ├─ Set lockout_level = 0
    │   ├─ Log successful login
    │   └─ Create session
    │
    └─ INCORRECT → 
        ├─ Increment failed_login_attempts
        ├─ Log failed attempt
        ├─ did_attempts >= 5? (Level 1)
        │  ├─ YES → Set lockout_until = NOW + 1 minute
        │  │        Set lockout_level = 1
        │  │        Log account_locked
        │  │        Show "locked for 1 minute" message
        │  └─ NO → Show "N attempts remaining" message
        │
        └─ did_attempts >= 7? (Level 2)
           ├─ YES → Set lockout_until = NOW + 5 minutes
           │        Set lockout_level = 2
           │        Log account_locked
           │        Show "locked for 5 minutes" message
           └─ NO → Show "N attempts remaining" message
```

---

## API Reference

### LoginSecurity Class

#### `checkAccountLock($username): array`
Checks if an account is currently locked.

```php
$result = LoginSecurity::checkAccountLock('john_doe');
// Returns:
// [
//     'locked' => true/false,
//     'message' => 'Account temporarily locked...',
//     'minutes_remaining' => 3,
//     'level' => 1 or 2
// ]
```

#### `recordFailedAttempt($username): array`
Records a failed login attempt and determines if lockout should occur.

```php
$result = LoginSecurity::recordFailedAttempt('john_doe');
// Returns:
// [
//     'locked' => true/false,
//     'attempts' => 4,
//     'message' => 'Invalid credentials. 1 attempt remaining...'
// ]
```

#### `resetFailedAttempts($userId): void`
Resets all lockout flags on successful login.

```php
LoginSecurity::resetFailedAttempts(5);
```

#### `clearLockout($userId): void`
Manually clear lockout for a user (admin function).

```php
LoginSecurity::clearLockout(5);
```

#### `getAttemptStats($username): array`
Get login attempt statistics for a user.

```php
$stats = LoginSecurity::getAttemptStats('john_doe');
// Returns:
// [
//     'attempts' => 4,
//     'locked' => true/false,
//     'level' => 0/1/2,
//     'locked_until' => '2026-03-11 14:35:00'
// ]
```

#### `getLockedOutUsers(): array`
Get all currently locked out users (useful for admin monitoring).

```php
$locked_users = LoginSecurity::getLockedOutUsers();
// Returns array of all users with active lockouts
```

---

## Audit Logging

All login security events are logged to the `audit_logs` table:

### Logged Actions

| Action | Trigger |
|--------|---------|
| `login_failed` | Any failed login attempt |
| `login_blocked_lockout` | User tries to login while account is locked |
| `account_locked` | Account is locked due to failed attempts |
| `login_success` | Successful login (existing) |
| `logout` | User logs out (existing) |

### Example Audit Log Entry
```
{
  "user_id": null,
  "username": "john_doe",
  "action": "account_locked",
  "details": "Account locked due to 5 failed login attempts. Lock level: 1",
  "created_at": "2026-03-11 13:30:00"
}
```

---

## Configuration

Edit `auth/LoginSecurity.php` to change lockout parameters:

```php
private const FIRST_LOCKOUT_ATTEMPTS = 5;      // Attempts before 1st lock
private const FIRST_LOCKOUT_MINUTES = 1;       // Duration of 1st lock
private const SECOND_LOCKOUT_ATTEMPTS = 2;     // Additional attempts before 2nd lock
private const SECOND_LOCKOUT_MINUTES = 5;      // Duration of 2nd lock
```

---

## Testing the Feature

### Test 1: First Lockout (1 minute)
1. Log in 5 times with wrong password
2. On 5th attempt, account should be locked
3. Try to log in with correct password
4. Should see: "Account temporarily locked" message
5. Wait 1 minute, try again with correct password
6. Should log in successfully

### Test 2: Second Lockout (5 minutes)
1. Complete Test 1 (first lockout expires)
2. Fail 2 more times after lock expires
3. On 7th total attempt, account locked for 5 minutes
4. Wait 5 minutes, try with correct password
5. Should log in successfully

### Test 3: Reset on Success
1. Fail 2 login attempts
2. Log in successfully with correct password
3. Check database: `failed_login_attempts` should be 0
4. `lockout_until` should be NULL
5. `lockout_level` should be 0

### Test 4: Audit Logging
1. Perform failed login attempts
2. Check `audit_logs` table
3. Should see `login_failed` and `account_locked` entries

---

## Security Considerations

✅ **Prevents Brute-Force Attacks** - Limits login attempts with escalating penalties  
✅ **Tiered Approach** - First lockout is short (1 min), second is longer (5 min)  
✅ **Automatic Expiration** - Lockouts automatically expire without admin intervention  
✅ **Audit Trail** - All attempts logged for security monitoring  
✅ **User Feedback** - Clear messages show remaining attempts and lockout duration  
✅ **Database Efficiency** - Uses DATETIME for automatic MySQL comparisons  

---

## For Your Existing Database

Run these queries to add the required columns:

```sql
ALTER TABLE users ADD COLUMN failed_login_attempts INT DEFAULT 0;
ALTER TABLE users ADD COLUMN lockout_until DATETIME NULL;
ALTER TABLE users ADD COLUMN lockout_level INT DEFAULT 0;
```

Or if column already exists:

```sql
ALTER TABLE users ADD COLUMN IF NOT EXISTS failed_login_attempts INT DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS lockout_until DATETIME NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS lockout_level INT DEFAULT 0;
```

---

## User Experience

### Scenario 1: Normal Login
```
User enters: john_doe / password123
✓ Password correct
→ Reset attempts to 0
→ Redirect to dashboard
```

### Scenario 2: First Failed Attempt
```
User enters: john_doe / wrongpassword
✗ Password incorrect
→ Attempts: 1/5
→ Message: "Invalid credentials. 4 attempts remaining before account lockout"
```

### Scenario 3: Fifth Failed Attempt (Lockout Triggered)
```
User enters: john_doe / wrongpassword (5th time)
✗ Password incorrect, attempts = 5
→ Account locked for 1 minute
→ Message: "Account temporarily locked. Please try again in 1 minute"
```

### Scenario 4: Attempting Login While Locked
```
User enters: john_doe / correctpassword (within 1 minute)
⛔ Account is locked (lock valid until 14:35)
→ Message: "Account temporarily locked. Please try again in 1 minute(s)"
→ No audit log for password history
```

### Scenario 5: After Lock Expires
```
User waits 1 minute
Tries again: john_doe / correctpassword
✓ Password correct
→ Lock expired and cleared
→ Reset attempts to 0
→ Redirect to dashboard
```

---

## Troubleshooting

**Q: User locked out but lockout time has passed**
- The system checks and clears expired locks automatically on next login attempt
- If manual intervention needed, use: `LoginSecurity::clearLockout($userId)`

**Q: How to manually unlock a user?**
- Run in AuditLog or admin panel: `LoginSecurity::clearLockout($user_id)`
- Or SQL: `UPDATE users SET lockout_until = NULL, lockout_level = 0 WHERE id = ?`

**Q: How to see all locked users?**
- Use: `$locked = LoginSecurity::getLockedOutUsers();`
- Or SQL: `SELECT * FROM users WHERE lockout_until IS NOT NULL AND lockout_until > NOW();`

**Q: How to change lockout duration?**
- Edit `auth/LoginSecurity.php` constants:
  - `FIRST_LOCKOUT_MINUTES = 1`
  - `SECOND_LOCKOUT_MINUTES = 5`

---

## Version Information
- Created: 2026-03-11
- Feature: Brute-Force Login Protection
- Database: MySQL 5.7+
- PHP: 7.4+ (uses DateTime class)
