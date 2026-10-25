<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** AUTH-1: 5 failed attempts per minute per email + IP. */
    public function throttleKey(): string
    {
        return 'login:'.mb_strtolower((string) $this->input('email')).'|'.$this->ip();
    }

    /**
     * Failed attempts per account from one network (/24 IPv4, /48 IPv6). Slows IP rotation
     * against an account without letting an attacker lock the owner out from their own network.
     */
    public function accountThrottleKey(): string
    {
        return 'login-account:'.mb_strtolower((string) $this->input('email')).'|'.self::network((string) $this->ip());
    }

    /** Failed attempts per account from anywhere; past the threshold logins are slowed, not blocked. */
    public function globalAccountKey(): string
    {
        return 'login-account-global:'.mb_strtolower((string) $this->input('email'));
    }

    public static function network(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return implode(':', array_slice(explode(':', inet_ntop(inet_pton($ip))), 0, 3)).'::/48';
        }
        $parts = explode('.', $ip);

        return count($parts) === 4 ? "{$parts[0]}.{$parts[1]}.{$parts[2]}.0/24" : $ip;
    }
}
