<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExportReadingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'format'     => ['sometimes', 'string', 'in:json,csv'],
            'anemometer' => ['sometimes', 'uuid'],
        ];
    }
}
