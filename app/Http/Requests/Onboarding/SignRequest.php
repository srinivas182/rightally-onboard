<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SignRequest extends FormRequest
{
    /** Drawn signatures are small PNGs; anything larger is not a signature pad image. */
    private const MAX_BYTES = 400_000;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'consent' => ['accepted'],
            'typed_name' => ['required', 'string', 'min:2', 'max:160'],
            'signature' => ['required', 'string', 'starts_with:data:image/png;base64,'],
        ];
    }

    public function messages(): array
    {
        return [
            'consent.accepted' => 'Tick the box to agree to sign electronically.',
            'typed_name.required' => 'Type your full legal name.',
            'signature.required' => 'Draw your signature in the box.',
            'signature.starts_with' => 'Draw your signature in the box.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->has('signature')) {
                return;
            }
            $png = $this->signaturePng();
            if ($png === null) {
                $v->errors()->add('signature', 'Draw your signature in the box.');
            }
        }];
    }

    /** Decoded PNG bytes, or null if the data isn't a real, non-empty PNG. */
    public function signaturePng(): ?string
    {
        $data = base64_decode(substr((string) $this->input('signature'), strlen('data:image/png;base64,')), true);
        if ($data === false || strlen($data) < 200 || strlen($data) > self::MAX_BYTES || ! str_starts_with($data, "\x89PNG\r\n\x1a\n")) {
            return null;
        }
        $size = @getimagesizefromstring($data);

        return ($size && $size[0] >= 50 && $size[1] >= 20 && $size[0] <= 4000 && $size[1] <= 2000) ? $data : null;
    }
}
