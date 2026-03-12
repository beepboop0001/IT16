<?php
// auth/PasswordValidator.php

class PasswordValidator {
    
    // Password requirements constants
    private const MIN_LENGTH = 8;
    private const MAX_LENGTH = 16;
    
    /**
     * Validate password against security requirements
     * 
     * Requirements:
     * - 8-16 characters
     * - At least one uppercase letter (A-Z)
     * - At least one lowercase letter (a-z)
     * - At least one number (0-9)
     * - At least one special character (!@#$%^&*)
     * 
     * @param string $password
     * @return array ['valid' => bool, 'errors' => array]
     */
    public static function validate(string $password): array {
        $errors = [];
        
        // Length checks
        if (strlen($password) < self::MIN_LENGTH) {
            $errors[] = 'Password must be at least ' . self::MIN_LENGTH . ' characters long.';
        }
        if (strlen($password) > self::MAX_LENGTH) {
            $errors[] = 'Password must be no more than ' . self::MAX_LENGTH . ' characters long.';
        }
        
        // Uppercase letter
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter (A-Z).';
        }
        
        // Lowercase letter
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter (a-z).';
        }
        
        // Number
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number (0-9).';
        }
        
        // Special character
        if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};:\'",.<>?\/\\|`~]/', $password)) {
            $errors[] = 'Password must contain at least one special character (!@#$%^&* etc).';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Get all password requirements as a formatted string
     */
    public static function getRequirements(): string {
        return '
            • Minimum 8 characters, Maximum 16 characters
            • At least one uppercase letter (A-Z)
            • At least one lowercase letter (a-z)
            • At least one number (0-9)
            • At least one special character (!@#$%^&* etc)
        ';
    }
    
    /**
     * Check if password meets minimum requirements
     */
    public static function isValid(string $password): bool {
        return self::validate($password)['valid'];
    }
}
