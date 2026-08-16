<?php

namespace App\Http\Requests\Auth;

use App\Rules\SenhaForte;
use Illuminate\Foundation\Http\FormRequest;

class AceitarConviteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', new SenhaForte],
        ];
    }
}
