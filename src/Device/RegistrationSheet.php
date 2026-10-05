<?php
declare(strict_types=1);

namespace Pfpms\Device;

use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/** The printed sheet that registers a tablet (plan P2A admin_devices). */
final class RegistrationSheet
{
    /** Home-screen name of the installed Station. P2B's manifest.json "name" must be the same. */
    public const APP_NAME = 'Pet Pantry Station';

    /** The code as an SVG QR code in a data: URI (the page's CSP allows img-src data:). */
    public static function qr(#[\SensitiveParameter] string $canonical): string
    {
        $options = new QROptions(['outputType' => QROutputInterface::MARKUP_SVG, 'outputBase64' => true, 'addQuietzone' => true]);
        return (new QRCode($options))->render(RegistrationCode::qrPayload($canonical));
    }
}
