<?php
require_once dirname(__DIR__) . '/config/auth.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>404 — Not Found</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<style>body{background:#f4f6fb}</style>
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh">
<div class="text-center">
  <div class="display-1 text-secondary fw-bold"><i class="fa-solid fa-compass"></i> 404</div>
  <h2 class="mb-2">Page Not Found</h2>
  <p class="text-muted mb-4">The page you are looking for does not exist or has been moved.</p>
  <a href="/dashboard" class="btn btn-primary"><i class="fa-solid fa-house me-1"></i> Go Home</a>
</div>
</body>
</html>
