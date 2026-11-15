<?php
namespace App\Models;
final class User {
    public static function byEmail(string $email): ?array {
        return \row('SELECT * FROM users WHERE email=?', [strtolower(trim($email))]);
    }
}
