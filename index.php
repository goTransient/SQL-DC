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
  <link rel="stylesheet" href="vendor/my-ui-kit/css/uikit.css">
  <script src="vendor/my-ui-kit/js/uikit.js" defer></script>
  <script src="js/common.js" defer></script>
  <script src="js/modules.js" defer></script>
  <script src="js/import.js" defer></script>
  <script src="js/script.js" defer></script>
</head>
<body>
  <div class="app auth-only">
    <div class="app-body">
      <aside class="sidebar">
        <div class="brand">
          <span class="brand-mark" aria-hidden="true">D</span>
          <div class="brand-copy">
            <strong>SỔ DÂN CƯ</strong>
            <span>QUẢN LÝ ĐỊA BÀN</span>
          </div>
        </div>

        <div class="sidebar-context">
          <span class="context-mark" aria-hidden="true">23</span>
          <div>
            <strong id="community-sidebar-name">Tổ dân phố 23</strong>
            <span class="sub" id="brand-sub"></span>
          </div>
        </div>

        <nav id="nav"></nav>

        <div class="sidebar-foot save-indicator idle" id="save-indicator"></div>
      </aside>

      <div class="app-content">
        <header class="app-masthead">
          <div class="masthead-copy">
            <h1 id="community-name"></h1>
            <p>Số liệu ngày <span id="data-date"></span>, chỉ tính người đang ở</p>
          </div>
          <div class="topbar-account" id="account-area" hidden></div>
        </header>

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
    </div>
  </div>
</body>
</html>