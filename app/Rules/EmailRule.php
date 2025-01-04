<?php

namespace App\Rules;

use Closure;
use App\Models\User;
use App\Helper\Helper;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Encryption\DecryptException;
class EmailRule  implements Rule
{

    public function passes($attribute, $value)
    {
        $useridHash = Helper::HashedValue($value);
        $users = User::query()->where('email_hashed' , $useridHash)->first();
        if ($users) {
            return false; // Email is not unique
        }

        return true; // Email is unique
    }

    public function message()
    {
        return 'The :attribute has already been taken.';
    }
}
