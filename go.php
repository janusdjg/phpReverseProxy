<?php
if(!empty($_GET['p']) && !empty($_GET['d'])){
  $next_page = rawurldecode($_GET['p']);
  setcookie("d", $_GET['d'],[
    'expires' => time() + 86400,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => true,
    'samesite' => 'None'
  ]);
  header("Location: $next_page");
}
