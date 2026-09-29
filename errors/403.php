<?php
require_once dirname(__DIR__) . '/config/auth.php';
if (!is_logged_in()) {
    redirect('/login');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>403 — Access Denied</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<style>body{background:#f4f6fb}</style>
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh">
<div class="text-center">
  <div class="display-1 text-danger fw-bold"><i class="fa-solid fa-lock"></i> 403</div>
  <h2 class="mb-2">Access Denied</h2>
  <p class="text-muted mb-4">You do not have permission to access this page or action.</p>
  <a href="/dashboard" class="btn btn-primary"><i class="fa-solid fa-gauge-high me-1"></i> Back to Dashboard</a>
</div>
</body>
</html>
