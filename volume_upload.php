<?php
// TEMPORARY ADMIN TOOL — upload a file directly into the persistent volume
// mounted at images/. Needed because that folder is a Railway Volume and
// does NOT get updated by git pushes/deploys.
//
// SECURITY: delete this file (or at least restrict/remove it) once you're
// done using it — anyone who finds this URL could otherwise overwrite files
// in your images folder.

session_start();
require_once __DIR__ . '/config/database.php';

// Require an authenticated admin session (same check style as your other admin pages)
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    die('Forbidden — admin login required.');
}

$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['upload_file'])) {
    $targetName = trim($_POST['target_filename'] ?? '');
    $targetName = basename($targetName); // strip any path traversal

    if ($targetName === '') {
        $error = 'Please specify a target filename (e.g. gcash_qr.png).';
    } elseif ($_FILES['upload_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Upload failed (error code ' . $_FILES['upload_file']['error'] . ').';
    } else {
        $destPath = __DIR__ . '/images/' . $targetName;
        if (move_uploaded_file($_FILES['upload_file']['tmp_name'], $destPath)) {
            $message = "Uploaded successfully to images/$targetName";
        } else {
            $error = 'Could not move uploaded file to images/' . htmlspecialchars($targetName);
        }
    }
}

// List current files in images/ (top-level only) so you can confirm the result
$currentFiles = [];
foreach (glob(__DIR__ . '/images/*') as $f) {
    if (is_file($f)) {
        $currentFiles[] = basename($f) . ' (' . filesize($f) . ' bytes, modified ' . date('Y-m-d H:i:s', filemtime($f)) . ')';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Volume Upload Tool</title>
<style>
body { font-family: sans-serif; max-width: 640px; margin: 40px auto; padding: 0 20px; }
h1 { font-size: 1.3rem; }
.msg-ok { background: #d4edda; color: #155724; padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; }
.msg-err { background: #f8d7da; color: #721c24; padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; }
form { border: 1px solid #ccc; padding: 16px; border-radius: 8px; margin-bottom: 24px; }
label { display: block; margin-bottom: 6px; font-weight: 600; }
input[type=text] { width: 100%; padding: 8px; margin-bottom: 12px; box-sizing: border-box; }
button { padding: 10px 18px; cursor: pointer; }
ul { font-size: .85rem; color: #444; }
</style>
</head>
<body>
<h1>Volume Upload Tool (temporary)</h1>
<p>This uploads a file directly into <code>images/</code> on the running server — bypassing git/deploy, since that folder is a persistent volume.</p>

<?php if ($message): ?><div class="msg-ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="msg-err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<form method="POST" enctype="multipart/form-data">
  <label for="target_filename">Target filename (e.g. gcash_qr.png)</label>
  <input type="text" id="target_filename" name="target_filename" value="gcash_qr.png" required>

  <label for="upload_file">File to upload</label>
  <input type="file" id="upload_file" name="upload_file" required>

  <button type="submit">Upload</button>
</form>

<h2>Current files in images/</h2>
<ul>
<?php foreach ($currentFiles as $f): ?>
  <li><?= htmlspecialchars($f) ?></li>
<?php endforeach; ?>
</ul>

<p style="color:#a00; font-weight:600;">⚠️ Delete this file from your repo once you're done uploading.</p>
</body>
</html>
