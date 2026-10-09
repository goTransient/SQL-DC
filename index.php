<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sổ Quản Lý Dân Cư</title>
  <link rel="stylesheet" href="css/style.css">
  <script src="js/common.js" defer></script>
  <script src="js/modules.js" defer></script>
  <script src="js/import.js" defer></script>
  <script src="js/script.js" defer></script>
</head>
<body>
  <div class="app auth-only">
    <aside class="sidebar">
      <div class="brand">
        <h1>Sổ Quản Lý<br>Dân Cư</h1>
        <div class="sub" id="brand-sub"></div>
      </div>

      <nav id="nav"></nav>

      <div class="sidebar-account" id="account-area" hidden></div>
      <div class="sidebar-foot save-indicator idle" id="save-indicator"></div>
    </aside>

    <main id="main" tabindex="-1">
      <section class="auth-page">
        <div class="auth-card">
          <h2>Sổ Quản Lý Dân Cư</h2>
          <p>Đăng nhập bằng tài khoản Google đã được cấp quyền để tiếp tục.</p>
          <a class="btn-primary auth-login" href="api/google-login.php">Đăng nhập bằng Google</a>
        </div>
      </section>
    </main>
  </div>
</body>
</html>