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

/*
 * Pages in the sidebar. afterRender runs once the page is on screen,
 * for pages that fill part of themselves afterwards.
 */
const TABS = [
  { id: "dashboard",    label: "Tổng quan",       ico: "■", render: renderDashboard },
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

  document.getElementById("nav").innerHTML = TABS.map(t => `
    <button class="nav-item ${currentTab === t.id ? "active" : ""}" onclick="setTab('${t.id}')">
      <span class="nav-ico">${t.ico}</span>
      <span>${esc(t.label)}</span>
    </button>
  `).join("");
}

function render() {
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
    console.error(error);
    render();
    document.getElementById("main")?.insertAdjacentHTML(
      "afterbegin",
      `<div class="form-error">⚠ Không tải được dữ liệu: ${esc(error.message)}</div>`
    );
  }
}

document.addEventListener("keydown", handleModalEscape);

loadData();