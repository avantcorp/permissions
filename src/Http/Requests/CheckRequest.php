<?php

declare(strict_types=1);

namespace Avant\Permissions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            '*.ability' => ['required', 'string'],
            '*.model'   => ['nullable', 'string'],
            '*.id'      => ['nullable'],
        ];
    }
}