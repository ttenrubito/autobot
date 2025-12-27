<?php
/**
 * Input Validator
 * Provides comprehensive input validation and sanitization
 * SECURITY: Prevents XSS, SQL Injection, and other attacks
 */

class Validator {
    private $errors = [];

    /**
     * Validate required field
     */
    public function required($field, $value, $fieldName = null) {
        $fieldName = $fieldName ?? ucfirst($field);
        if (empty($value) && $value !== '0') {
            $this->errors[$field] = "$fieldName is required";
            return false;
        }
        return true;
    }

    /**
     * Validate email format
     */
    public function email($field, $value, $fieldName = null) {
        $fieldName = $fieldName ?? ucfirst($field);
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = "$fieldName must be a valid email address";
            return false;
        }
        return true;
    }

    /**
     * Validate minimum length
     */
    public function minLength($field, $value, $min, $fieldName = null) {
        $fieldName = $fieldName ?? ucfirst($field);
        if (strlen($value) < $min) {
            $this->errors[$field] = "$fieldName must be at least $min characters";
            return false;
        }
        return true;
    }

    /**
     * Validate maximum length
     */
    public function maxLength($field, $value, $max, $fieldName = null) {
        $fieldName = $fieldName ?? ucfirst($field);
        if (strlen($value) > $max) {
            $this->errors[$field] = "$fieldName must not exceed $max characters";
            return false;
        }
        return true;
    }

    /**
     * Validate numeric value
     */
    public function numeric($field, $value, $fieldName = null) {
        $fieldName = $fieldName ?? ucfirst($field);
        if (!is_numeric($value)) {
            $this->errors[$field] = "$fieldName must be a number";
            return false;
        }
        return true;
    }

    /**
     * Validate phone number (Thai format)
     */
    public function phone($field, $value, $fieldName = null) {
        $fieldName = $fieldName ?? ucfirst($field);
        $pattern = '/^0[0-9]{8,9}$/';
        if (!preg_match($pattern, $value)) {
            $this->errors[$field] = "$fieldName must be a valid phone number";
            return false;
        }
        return true;
    }

    /**
     * Validate value is in allowed list
     */
    public function in($field, $value, $allowed, $fieldName = null) {
        $fieldName = $fieldName ?? ucfirst($field);
        if (!in_array($value, $allowed, true)) {
            $this->errors[$field] = "$fieldName has invalid value";
            return false;
        }
        return true;
    }

    /**
     * Get all validation errors
     */
    public function getErrors() {
        return $this->errors;
    }

    /**
     * Check if validation passed
     */
    public function passes() {
        return empty($this->errors);
    }

    /**
     * Check if validation failed
     */
    public function fails() {
        return !$this->passes();
    }

    /**
     * Sanitize string (prevent XSS)
     */
    public static function sanitize($value) {
        return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Sanitize email
     */
    public static function sanitizeEmail($email) {
        return filter_var(trim($email), FILTER_SANITIZE_EMAIL);
    }

    /**
     * Clean input array (recursive sanitization)
     */
    public static function cleanInput($data) {
        if (is_array($data)) {
            return array_map([self::class, 'cleanInput'], $data);
        }
        return self::sanitize($data);
    }
}
