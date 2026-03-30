<?php
// =================== CONFIG ===================
$token  = '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';
$owner  = 'mrkotp';
$repo   = 'cdn';
$branch = 'main';
$folder = 'public'; 
// =============================================

$success = false;
$error   = '';

// ===================== UPLOAD HANDLING =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image'])) {
    
    $file = $_FILES['image'];
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        
        $file_name = time() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '', basename($file['name']));
        $path      = $folder . $file_name;
        
        $content   = base64_encode(file_get_contents($file['tmp_name']));

        $url = "https://api.github.com/repos/{$owner}/{$repo}/contents/{$path}";

        $data = [
            'message' => 'Upload from website: ' . $file_name,
            'content' => $content,
            'branch'  => $branch
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_USERAGENT, 'PHP-Debug-Uploader');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: token ' . $token,
            'Accept: application/vnd.github.v3+json',
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        $response = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 201) {
            $success = true;
        } else {
            $error = "HTTP Code: $code<br><strong>Response:</strong><br>" . htmlspecialchars($response);
        }
    } else {
        $error = "File upload error!";
    }
}

// ===================== GALLERY =====================
$images = [];
$url = "https://api.github.com/repos/{$owner}/{$repo}/contents/{$folder}";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_USERAGENT, 'PHP-Debug-Gallery');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: token ' . $token,
    'Accept: application/vnd.github.v3+json'
]);
$response = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code === 200) {
    $files = json_decode($response, true);
    foreach ($files as $file) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
            $images[] = $file['download_url'];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="hi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug Gallery</title>
    <style>
        body { font-family: Arial; margin: 20px; background: #f4f4f4; }
        .box { background: white; padding: 20px; border-radius: 10px; margin: 15px auto; max-width: 700px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .error { color: red; background:#ffe6e6; padding:15px; border-radius:8px; }
    </style>
</head>
<body>

<div class="box">
    <h1>📸 GitHub Gallery Debug Mode</h1>
    
    <?php if ($error): ?>
        <div class="error">
            <strong>Upload Error:</strong><br>
            <?= $error ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <p style="color:green; font-size:18px;">✅ Upload Successful!</p>
    <?php endif; ?>

    <form action="" method="POST" enctype="multipart/form-data">
        <input type="file" name="image" accept="image/*" required><br><br>
        <button type="submit" style="padding:12px 30px; font-size:16px;">Upload Image</button>
    </form>
</div>

<div class="box">
    <h2>Gallery (<?= count($images) ?> photos)</h2>
    <?php if (empty($images)): ?>
        <p>No images yet or cannot access folder.</p>
    <?php else: ?>
        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(150px,1fr)); gap:10px;">
            <?php foreach ($images as $img): ?>
                <img src="<?= htmlspecialchars($img) ?>" style="width:100%; border-radius:6px;">
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

</body>
</html>