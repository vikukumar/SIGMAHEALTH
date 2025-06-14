<?php
$status = isset($_GET['status']) ? intval($_GET['status']) : 404;

$messages = [
  400 => "Bad Request",
  401 => "Unauthorized",
  403 => "Forbidden",
  404 => "Page Not Found",
  408 => "Request Timeout",
  429 => "Too Many Requests",
  500 => "Internal Server Error",
  502 => "Bad Gateway",
  503 => "Service Unavailable",
  504 => "Gateway Timeout",
];

$message = $messages[$status] ?? "Unexpected Error";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Error <?= $status ?> - <?= $message ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    html, body {
      margin: 0;
      padding: 0;
      font-family: 'Segoe UI', sans-serif;
      height: 100%;
      overflow: hidden;
      background: linear-gradient(to top right, tomato, white);
    }

    .parallax {
      background-image: url('https://www.transparenttextures.com/patterns/inspiration-geometry.png');
      height: 100%;
      background-attachment: fixed;
      background-position: center;
      background-repeat: repeat;
      background-size: cover;
      display: flex;
      align-items: center;
      justify-content: center;
      animation: fadeIn 2s ease-in;
    }

    .error-box {
      background: rgba(255, 255, 255, 0.9);
      padding: 40px;
      border-radius: 20px;
      box-shadow: 0 8px 20px rgba(0,0,0,0.2);
      text-align: center;
      animation: slideUp 1s ease-out;
      max-width: 90%;
    }

    h1 {
      font-size: 5em;
      color: tomato;
      margin: 0;
    }

    p {
      font-size: 1.5em;
      color: #444;
    }

    a {
      display: inline-block;
      margin-top: 20px;
      padding: 10px 25px;
      background: tomato;
      color: white;
      border-radius: 5px;
      text-decoration: none;
      transition: background 0.3s ease;
    }

    a:hover {
      background: #d63d1f;
    }

    @keyframes slideUp {
      from { transform: translateY(50px); opacity: 0; }
      to { transform: translateY(0); opacity: 1; }
    }

    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }

    @media (max-width: 600px) {
      h1 { font-size: 3em; }
      p { font-size: 1.2em; }
    }
  </style>
</head>
<body>
  <div class="parallax">
    <div class="error-box">
      <h1><?= $status ?></h1>
      <p><?= $message ?></p>
      <a href="/">🏠 Go Home</a>
      <a href="/admin">🏠 Go Admin</a>
    </div>
  </div>
</body>
</html>
