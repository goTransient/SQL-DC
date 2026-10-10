"use strict";

/* ============================================================
 * App state, navigation, loading
 * ============================================================ */

let DB = {
  households: [],
  residents: [],
  associations: [],
  residentAssociations: [],
  activities: [],
  householdActivities: []
};

let currentTab = "dashboard";
let isAuthenticated = false;
let authenticatedUser = null;
let appInitialized = false;

/*
 * Pages in the sidebar. afterRender runs once the page is on screen,
 * for pages that fill part of themselves afterwards.
 */
const TABS = [
  { id: "dashboard",    label: "Tổng quan",       ico: "■", render: renderDashboard, afterRender: renderDashboardStats },
  { id: "members",      label: "Cư dân",          ico: "☻", render: renderMembers, afterRender: updateMemberResults },
  { id: "associations", label: "Hội, đoàn thể",   ico: "⚑", render: renderAssociations },
  { id: "activities",   label: "Hoạt động chung", ico: "●", render: renderActivities },
  { id: "import",       label: "Import dữ liệu",  ico: "⇩", render: renderImport }
];

function setTab(id) {
  closeFormModal();
  currentTab = id;
  render();
  window.scrollTo(0, 0);
}

function renderSidebar() {
  const living = DB.residents.filter(isLiving).length;
  const sub = document.getElementById("brand-sub");
  if (sub) sub.textContent = `${DB.households.length} hộ · ${living} nhân khẩu`;

  const communityName = document.getElementById("community-name");
  if (communityName) communityName.textContent = COMMUNITY_NAME;
  const sidebarCommunityName = document.getElementById("community-sidebar-name");
  if (sidebarCommunityName) sidebarCommunityName.textContent = COMMUNITY_NAME;
  const dataDate = document.getElementById("data-date");
  if (dataDate) dataDate.textContent = new Date().toLocaleDateString("vi-VN");

  renderAccount();
  const nav = document.getElementById("nav");
  nav.innerHTML = `
    <div class="nav-group">
      <div class="nav-group-label">TỔNG QUAN</div>
      ${renderNavItem(TABS[0])}
    </div>
    <div class="nav-group">
      <div class="nav-group-label">QUẢN LÝ</div>
      ${TABS.slice(1).map(renderNavItem).join("")}
    </div>
  `;
}

function renderNavItem(tab) {
  return `
    <button class="nav-item ${currentTab === tab.id ? "active" : ""}" onclick="setTab('${tab.id}')">
      <span class="nav-ico" aria-hidden="true">${tab.ico}</span>
      <span>${esc(tab.label)}</span>
    </button>
  `;
}

function render() {
  if (!isAuthenticated) return;

  const main = document.getElementById("main");
  if (!main) return;

  const tab = TABS.find(t => t.id === currentTab) || TABS[0];
  currentTab = tab.id;

  renderSidebar();
  main.innerHTML = tab.render();
  tab.afterRender?.();
}

async function refreshData() {
  const data = await call("data");

  for (const key of Object.keys(DB)) {
    DB[key] = Array.isArray(data[key]) ? data[key] : [];
  }
}

async function loadData() {
  try {
    await refreshData();
    setSaved();
    render();
  } catch (error) {
    if (error.status === 401) return;

    console.error(error);
    render();
    document.getElementById("main")?.insertAdjacentHTML(
      "afterbegin",
      `<div class="form-error">⚠ Không tải được dữ liệu: ${esc(error.message)}</div>`
    );
  }
}

function renderAccount() {
  const account = document.getElementById("account-area");
  if (!account) return;

  account.hidden = !authenticatedUser;
  account.innerHTML = authenticatedUser ? `
    <div class="account-details">
      <span class="account-name">${esc(authenticatedUser.name || authenticatedUser.email)}</span>
      <span class="account-email">${esc(authenticatedUser.email)}</span>
    </div>
    <button type="button" class="account-logout" onclick="logout()">Đăng xuất</button>
  ` : "";
}

function showLogin(message = "") {
  closeFormModal();
  isAuthenticated = false;
  authenticatedUser = null;
  CSRF_TOKEN = "";
  Object.keys(DB).forEach(key => { DB[key] = []; });

  const app = document.querySelector(".app");
  app?.classList.add("auth-only");
  const account = document.getElementById("account-area");
  if (account) {
    account.hidden = true;
    account.replaceChildren();
  }
  document.getElementById("nav")?.replaceChildren();

  const main = document.getElementById("main");
  if (main) {
    main.innerHTML = `
      <section class="auth-page">
        <div class="auth-card">
          <h2>Sổ Quản Lý Dân Cư</h2>
          <p>Đăng nhập bằng tài khoản Google để tiếp tục.</p>
          ${message ? `<div class="form-error" role="alert">${esc(message)}</div>` : ""}
          <a class="btn-primary auth-login" href="api/google-login.php">Đăng nhập bằng Google</a>
        </div>
      </section>
    `;
    main.focus();
  }
}

async function logout() {
  try {
    await logoutRequest();
    showLogin("Bạn đã đăng xuất.");
  } catch (error) {
    if (error.status !== 401) alert(error.message);
  }
}

async function initializeApp() {
  if (appInitialized) return;
  appInitialized = true;

  try {
    const status = await requestJson("api/current-user.php");
    CSRF_TOKEN = status.csrfToken;
    authenticatedUser = status.user;
    isAuthenticated = true;
    renderAccount();
    await loadData();
    if (isAuthenticated) {
      document.querySelector(".app")?.classList.remove("auth-only");
    }
  } catch (error) {
    if (error.status === 401) {
      showLogin();
      return;
    }

    console.error(error);
    showLogin("Không thể kiểm tra phiên đăng nhập. Vui lòng thử tải lại trang.");
  }
}

document.addEventListener("sql-dc:unauthorized", () => showLogin("Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại."));
document.addEventListener("keydown", handleModalEscape);

initializeApp();