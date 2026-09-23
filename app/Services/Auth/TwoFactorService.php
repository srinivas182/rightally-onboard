<?php

namespace App\Services\Auth;

use App\Models\Admin;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Authenticator-app (TOTP) two-factor authentication for admins.
 *
 * Recovery codes are shown once, then stored hashed (inside an encrypted
 * column), and each can be used only once.
 */
class TwoFactorService
{
    public function __construct(private readonly Google2FA $engine) {}

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey(32);
    }

    public function qrCodeSvg(Admin $admin, string $secret): string
    {
        $uri = $this->engine->getQRCodeUrl(config('app.name').' Admin', $admin->email, $secret);
        $writer = new Writer(new ImageRenderer(new RendererStyle(180, 1), new SvgImageBackEnd));

        return $writer->writeString($uri);
    }

    public function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        return strlen($code) === 6 && $this->engine->verifyKey($secret, $code, 1);
    }

    /**
     * @return array{plain: array<int, string>, hashed: array<int, string>}
     */
    public function makeRecoveryCodes(int $count = 8): array
    {
        $plain = [];
        for ($i = 0; $i < $count; $i++) {
            $plain[] = Str::lower(Str::random(5).'-'.Str::random(5));
        }

        return ['plain' => $plain, 'hashed' => array_map(fn ($c) => Hash::make($c), $plain)];
    }

    /** Consume a recovery code if it matches. */
    public function useRecoveryCode(Admin $admin, string $code): bool
    {
        $code = Str::lower(trim($code));
        $codes = $admin->two_factor_recovery_codes ?? [];

        foreach ($codes as $i => $hash) {
            if (Hash::check($code, $hash)) {
                unset($codes[$i]);
                $admin->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }
}
