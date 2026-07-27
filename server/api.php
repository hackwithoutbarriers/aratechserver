if ($_GET['route'] == 'expiry') {
    $user = $_GET['user'];
    // Connexion à RouterOS
    $API = new RouterosAPI();
    $API->connect($iphost, $userhost, decrypt($passwdhost));
    
    $getUser = $API->comm("/ip/hotspot/user/print", array(
        "?name" => $user,
    ));
    
    $expiry = '';
    if (isset($getUser[0])) {
        $comment = $getUser[0]['comment'];
        // Extraire la date (supposée être après le préfixe "vc-" ou "up-")
        if (preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $comment, $matches)) {
            $expiry = $matches[1];
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['expiry' => $expiry]);
    exit;
}