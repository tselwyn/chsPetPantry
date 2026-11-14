<?php
/*
 * Sign users out after 30 minutes of inactivity (SRS UC-01, NFR-SEC-005).
 *
 * Every page includes this right after session_start(). If the signed-in
 * account's last request was more than 30 minutes ago, the session is cleared
 * and the user is sent to the login page with a message. Otherwise the clock
 * restarts. header.php shows a warning 2 minutes before the cut-off with a
 * button that keeps the session alive (sessionKeepAlive.php).
 */

if (!defined('PFPMS_SESSION_TIMEOUT_SECONDS')) {
    define('PFPMS_SESSION_TIMEOUT_SECONDS', 30 * 60);
}

/*
 * True when a session last active at $lastActivity has timed out at $now.
 * A session with no recorded activity yet has not timed out.
 */
function pfpms_session_expired($lastActivity, $now, $limit = PFPMS_SESSION_TIMEOUT_SECONDS) {
    return is_int($lastActivity) && ($now - $lastActivity) > $limit;
}

if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['_id'])) {
    $pfpmsNow = time();
    $pfpmsLast = isset($_SESSION['last_activity']) ? $_SESSION['last_activity'] : null;

    if (pfpms_session_expired($pfpmsLast, $pfpmsNow)) {
        $_SESSION = array();
        if (ini_get('session.use_cookies')) {
            $cookie = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly']);
        }
        session_destroy();

        $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($isAjax) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(array('timedOut' => true));
        } else {
            header('Location: login.php?timeout=1');
        }
        exit;
    }

    $_SESSION['last_activity'] = $pfpmsNow;
    unset($pfpmsNow, $pfpmsLast);
}
