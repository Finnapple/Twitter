<?php
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Block direct access sa JSON, TXT, at register.php
if (preg_match('/\.(json|txt)$|\/register\.php$/', $uri)) {
    http_response_code(403);
    // Custom forbidden page
    echo '<!doctype html>
<html lang="en-us">
  <head>
    <title>SUP, HACKER</title>
    <meta name="description" content="flag{h3y_st4wp_r1ght_h3r3}">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="messenger.png">
    <style>
      html, body {
        margin: 0;
        padding: 0;
        height: 100%;
        overflow: hidden; 
      }

      body {
        background: #111;
        color: #ff4d4d;
        display: flex;
        justify-content: center;
        align-items: center;
        font-family: "Courier New", monospace;
        text-align: center;
      }

      h1 {
        font-size: clamp(30px, 8vw, 50px); 
        animation: flicker 1.5s infinite;
        margin: 0; 
      }

      @keyframes flicker {
        0%, 19%, 21%, 23%, 25%, 54%, 56%, 100% { opacity: 1; }
        20%, 22%, 24%, 55% { opacity: 0.3; }
      }
    </style>
  </head>
  <body>
    <h1>FORBIDDEN</h1>
  </body>
</html>';
    exit();
}

// Normal file handling
$file = __DIR__ . $uri;
if ($uri !== '/' && file_exists($file)) {
    return false; // serve normal static files
}

// Fallback
require __DIR__ . '/index.php';