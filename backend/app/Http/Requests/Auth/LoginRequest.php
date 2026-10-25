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

    /** Failed attempts per account from any IP (stops IP rotation against one account). */
    public function accountThrottleKey(): string
    {
        return 'login-account:'.mb_strtolower((string) $this->input('email'));
    }
}
