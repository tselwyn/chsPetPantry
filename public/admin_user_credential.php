<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Pfpms\Account\AccountRepository;
use Pfpms\Account\AccountService;
use Pfpms\Clock;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Security\Csrf;
use Pfpms\Validation\ValidationException;
use Pfpms\View\View;

// UC-11 §3.3.5: a printable activation sheet when the invitation email does not arrive.
// The link is created only on POST and shown once; it is never stored in readable form.
$ctx = Page::start(['capability' => 'user.manage']);
$userId = Request::int('id', fromQuery: true) ?? Request::int('user_id') ?? Response::notFound();
$account = AccountRepository::find($userId) ?? Response::notFound();
$sheet = null;
$error = null;

if (Request::isPost()) {
    Csrf::verify();
    try {
        $token = AccountService::issueActivationSheet($userId, $ctx->userId());
        $link = absolute_url('activate.php', ['token' => $token]);
        $options = new QROptions(['outputType' => QROutputInterface::MARKUP_SVG, 'outputBase64' => true, 'addQuietzone' => true]);
        $sheet = [
            'link' => $link,
            'qr' => (new QRCode($options))->render($link),
            'expires' => Clock::now()->modify('+' . AccountService::tokenHours() . ' hours'),
        ];
    } catch (ValidationException $e) {
        $error = $e->getMessage();
    }
}

View::render('pages/admin/user_credential', ['title' => 'Activation sheet', 'ctx' => $ctx, 'account' => $account, 'sheet' => $sheet, 'error' => $error]);
