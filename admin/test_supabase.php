<?php
require_once __DIR__ . '/../db.php';
try {
    $pdo = ara_db_supabase();
    $stmt = $pdo->query('SELECT 1 AS connecte');
    $row = $stmt->fetch();
    echo 'Connexion OK : ' . $row['connecte'];
} catch (Exception $e) {
    echo 'Erreur : ' . $e->getMessage();
}
