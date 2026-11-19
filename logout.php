<?php
require __DIR__ . '/includes/init.php';

if (kullanici()) {
    $token = $_POST['_csrf'] ?? $_GET['_csrf'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        flash('danger', 'Güvenli çıkış doğrulaması başarısız oldu.');
        redirect('index.php');
    }
    logla('Çıkış', kullanici()['kullanici_adi'] . ' çıkış yaptı');
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
redirect('login.php');
