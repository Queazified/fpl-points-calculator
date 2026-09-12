<?php
http_response_code(404);

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
$scheme = $isHttps ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$baseUrl = $scheme . '://' . $host . ($scriptDir === '' ? '' : $scriptDir);
$canonicalUrl = $baseUrl . '/404.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page Not Found | FPL Points Calculator</title>
    <meta name="description" content="The page you requested could not be found. Return to the FPL Points Calculator homepage to view live mini-league standings.">
    <link rel="canonical" href="<?php echo htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #37003c;
        }
        main {
            max-width: 640px;
            width: 100%;
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            padding: 36px;
            text-align: center;
        }
        h1 { font-size: 2rem; margin-bottom: 12px; }
        p { color: #555; margin-bottom: 20px; line-height: 1.6; }
        a {
            display: inline-block;
            text-decoration: none;
            color: #fff;
            background: #37003c;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
        }
        a:hover { background: #28002c; }
    </style>
</head>
<body>
    <main>
        <h1>404: Page not found</h1>
        <p>The page you opened does not exist or has moved. Use the button below to return to the FPL Points Calculator homepage.</p>
        <a href="fpl.php">Go to homepage</a>
    </main>
</body>
</html>
