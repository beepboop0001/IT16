# First-Time Login Password Change Security Feature

## Overview
This document describes the new security feature that enforces password changes on first login for both Owner and Cashier accounts.

## Features Implemented

### 1. Default Admin Account
- **When:** System first used (if no Owner account exists)
- **Automatic Creation:** Yes - happens on first page load via `InitializeAdmin::ensureDefaultAdmin()`
- **Default Credentials:**
  - Username: `admin`
  - Password: `123`
- **First Login Flag:** `first_login = 1` (forces password change)

### 2. Forced Password Change on First Login
- **Owner:** Must change password after first login with default credentials
- **Cashier:** Must change password after first login (set by Owner)
- **Location:** `/pages/change_password.php`
- **Behavior:** User cannot access dashboard until password is changed

### 3. Password Requirements (Enforced)
All passwords must meet these requirements:
- **Length:** 8-16 characters
- **Uppercase:** At least one (A-Z)
- **Lowercase:** At least one (a-z)
- **Number:** At least one (0-9)
- **Special Character:** At least one (!@#$%^&* etc)

### 4. Database Changes

#### Modified `users` table:
```sql
ALTER TABLE users ADD COLUMN first_login TINYINT(1) DEFAULT 1;
```

New column tracks whether user must change password on first login:
- `1` = Must change password (first login or forced change)
- `0` = Password already changed (normal user)

### 5. New Files Created

#### `/auth/PasswordValidator.php`
- Validates password against all security requirements
- Methods:
  - `validate(string $password): array` - Returns validation result with errors
  - `isValid(string $password): bool` - Quick validation check
  - `getRequirements(): string` - Returns formatted requirements

#### `/auth/InitializeAdmin.php`
- Automatically creates default admin account if none exists
- Methods:
  - `ensureDefaultAdmin(): array` - Creates default admin if needed
  - `ownerExists(): bool` - Checks if Owner account exists
  - `getOwnerCount(): int` - Returns count of Owner accounts

#### `/pages/change_password.php`
- Password change form for first-time users
- Validates current password
- Ensures new passwords match
- Enforces PasswordValidator requirements
- Supports both first login and forced password changes
- User-friendly error messages

### 6. Modified Files

#### `/config/Database.php`
- No changes (already handles DB connection)

#### `/auth/Auth.php`
New methods added:
- `isFirstLogin(): bool` - Check if user is on first login
- `updatePassword(int $userId, string $newPassword): bool` - Update password and clear first_login flag

#### `/index.php` (Login Page)
- Calls `InitializeAdmin::ensureDefaultAdmin()` on load
- Redirects to `change_password.php` if user is on first login
- Updated login hints with default admin credentials

#### `/pages/cashiers.php`
- New cashier accounts created with `first_login = 1`
- Users informed that cashiers must change password on first login

### 7. User Flow

#### First-Time System Use (New Owner):
1. System creates default `admin` account automatically
2. Owner logs in with: `admin` / `123`
3. Redirected to `/pages/change_password.php`
4. Owner enters current password (`123`) and new secure password
5. Password validated against requirements
6. On success: `first_login` set to `0`, redirected to dashboard

#### Adding New Cashier:
1. Owner creates new cashier account via `/pages/cashiers.php`
2. Owner provides temporary password (minimum 6 characters)
3. Cashier logs in with provided credentials
4. Redirected to `/pages/change_password.php` (because `first_login = 1`)
5. Cashier changes to a secure password
6. On success: `first_login` set to `0`, redirected to dashboard

#### Forced Password Change (Future):
- Admin can force password change by setting `first_login = 1` on any user
- Next login will redirect to change password page

### 8. Audit Logging Integration
All password changes are logged:
- `password_changed_first_login` - First-time password change
- `password_changed_forced` - Forced password change

Logs include:
- User ID, username, role
- Timestamp
- Action type
- Detailed description

## Security Considerations

✓ Default password only valid for first login  
✓ Strong password enforcement (8-16 chars, mixed case, numbers, special chars)  
✓ Session regeneration on login (existing feature)  
✓ Password hashing with PASSWORD_BCRYPT  
✓ Audit trail for all password changes  
✓ Users cannot bypass change password on first login  

## Testing the Feature

### Test 1: Default Admin Account Creation
1. Set up fresh database using `schema.sql`
2. Visit `/index.php`
3. Check database: Should have `admin` user with `first_login = 1`

### Test 2: First Login Password Change (Owner)
1. Log in with: `admin` / `123`
2. Should redirect to `/pages/change_password.php`
3. Try weak password (should fail validation)
4. Enter strong password: `MySecure@Pass123`
5. Should redirect to dashboard
6. Database should show `first_login = 0`

### Test 3: First Login Password Change (Cashier)
1. Owner creates new cashier: `test_cashier` / `temp123`
2. Log out (if needed)
3. Log in as `test_cashier` with `temp123`
4. Should redirect to change password page
5. Change to strong password
6. Should access dashboard normally

### Test 4: Demo Accounts
- Existing demo accounts (`owner1`, `cashier1`) have `first_login = 0`
- They bypass change password and go straight to dashboard

## File Structure
```
IT16/
├── auth/
│   ├── Auth.php (MODIFIED - added isFirstLogin, updatePassword)
│   ├── PasswordValidator.php (NEW)
│   ├── InitializeAdmin.php (NEW)
│   └── AuditLog.php
├── pages/
│   ├── change_password.php (NEW)
│   ├── cashiers.php (MODIFIED - set first_login on new accounts)
│   ├── dashboard.php
│   └── ...
├── config/
│   └── Database.php
├── index.php (MODIFIED - added initialization and redirect logic)
└── schema.sql (MODIFIED - added first_login column)
```

## API Reference

### PasswordValidator

```php
// Validate password
$result = PasswordValidator::validate($password);
// Returns: ['valid' => bool, 'errors' => array]

// Quick check
if (PasswordValidator::isValid($password)) { ... }

// Get requirements text
echo PasswordValidator::getRequirements();
```

### Auth (New Methods)

```php
// Check if user is on first login
if (Auth::isFirstLogin()) { ... }

// Update password (clears first_login flag)
Auth::updatePassword($userId, $newPassword);
```

### InitializeAdmin

```php
// Ensure default admin exists
$result = InitializeAdmin::ensureDefaultAdmin();
// Returns: ['success' => bool, 'message' => string]

// Check if owner exists
if (InitializeAdmin::ownerExists()) { ... }

// Get owner count
$count = InitializeAdmin::getOwnerCount();
```

## Troubleshooting

**Q: Default admin account not being created**
- Ensure `roles` table has 'owner' role
- Check database permissions
- Verify `schema.sql` was fully imported

**Q: Password change page shows infinite loop**
- Ensure user has `first_login = 1` in database
- Check session is properly maintained
- Look at browser console for errors

**Q: User can't log in after password change**
- Verify password was properly hashed (bcrypt)
- Check `first_login` flag was set to 0
- Try clearing browser cache and cookies

## Future Enhancements

1. **Password History** - Prevent reusing old passwords
2. **Password Expiration** - Force password change periodically
3. **Security Questions** - Additional verification on password reset
4. **Two-Factor Authentication** - Add 2FA for additional security
5. **Admin Password Reset Tool** - Allow admin to reset user passwords

## Version Information
- Created: 2026-03-11
- Feature: First-Time Login Password Change
- Database: MySQL 5.7+
- PHP: 7.4+
