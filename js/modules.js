"use strict";

/* ============================================================
 * TỔNG QUAN
 * A portrait of the tổ: who lives here, where, what is coming up
 * this year, and how complete the records are.
 * Everything counts only people whose Tình trạng is "Đang ở".
 * ============================================================ */

const COMMUNITY_NAME = "Tổ dân phố 23";

const AGE_BANDS = [
  [90, 999, "90+"], [80, 89, "80–89"], [70, 79, "70–79"], [60, 69, "60–69"], [50, 59, "50–59"],
  [40, 49, "40–49"], [30, 39, "30–39"], [20, 29, "20–29"], [10, 19, "10–19"], [0, 9, "0–9"]
];

const LIFE_STAGES = [
  { group: "Trẻ em",               label: "Trẻ em (dưới 6)",       cls: "seg-child" },
  { group: "Học sinh / Sinh viên", label: "Học sinh, sinh viên",   cls: "seg-student" },
  { group: "Đang đi làm",          label: "Đang đi làm",           cls: "seg-working" },
  { group: "Nghỉ hưu",             label: "Nghỉ hưu (từ 60)",      cls: "seg-retired" },
  { group: "Thiếu thông tin",      label: "Chưa có năm sinh",      cls: "seg-unknown" }
];

function pct(n, total) {
  return total ? Math.round((n * 100) / total) : 0;
}

function decimal(n) {
  return n.toFixed(1).replace(".", ",");
}

/* "5 ngách 17/2 Tạ Quang Bửu" -> "Ngách 17/2 Tạ Quang Bửu"; "212 E4 Bách Khoa" -> "E4 Bách Khoa". */
function areaOf(address) {
  const a = String(address || "").trim();
  if (!a) return "Chưa rõ địa chỉ";
  const rest = a.replace(/^(số\s*)?[+\w\/.\-]*\d[\w\/.\-]*,?\s+/i, "").trim() || a;
  return rest.charAt(0).toUpperCase() + rest.slice(1);
}

/* Opens the Cư dân page on one of its quick lists. */
function openMemberList(presetId) {
  const p = PRESETS.find(x => x.id === presetId);
  if (!p) return;
  memPreset = p.id;
  memFilters = { ...emptyMemberFilters(), ...p.f };
  memSortByAge = !!p.sortByAge;
  setTab("members");
}

function renderDashboard() {
  const living = DB.residents.filter(isLiving);

  if (!living.length) {
    return `
      <section class="page">
        <div class="page-header"><div><h2>Tổng quan</h2></div></div>
        <div class="empty-state">Chưa có dữ liệu. Vào “Import dữ liệu” để nạp file dân cư.</div>
      </section>`;
  }

  return `
    <section class="page dash">
      ${dashHero(living)}

      <div class="dash-row dash-row-wide">
        ${dashPyramid(living)}
        <div class="dash-stack">
          ${dashLifeStages(living)}
          ${dashThisYear(living)}
        </div>
      </div>

      <div class="dash-row">
        ${dashAreas(living)}
        ${dashBirthdays(living)}
      </div>

      <div class="dash-row">
        ${dashActivities()}
        ${dashRecords(living)}
      </div>
    </section>
  `;
}

/* ---------- Hero: the tổ in four numbers ---------- */

function dashHero(living) {
  const households = DB.households.filter(h => membersOf(h.household_id).some(isLiving)).length;
  const voters = living.filter(r => Number(r.is_voter)).length;
  const ages = living.map(ageOf).filter(a => a !== null);
  const avgAge = ages.length ? Math.round(ages.reduce((s, a) => s + a, 0) / ages.length) : "—";
  const today = new Date().toLocaleDateString("vi-VN");

  const big = (value, label, note = "") => `
    <div class="hero-stat">
      <strong>${value}</strong>
      <span>${esc(label)}</span>
      ${note ? `<small>${esc(note)}</small>` : ""}
    </div>`;

  return `
    <div class="dash-hero">
      <div class="hero-title">
        <h2>${esc(COMMUNITY_NAME)}</h2>
        <p>Số liệu ngày ${today}, chỉ tính người đang ở</p>
      </div>
      <div class="hero-stats">
        ${big(living.length, "nhân khẩu")}
        ${big(households, "hộ gia đình", households ? `${decimal(living.length / households)} người mỗi hộ` : "")}
        ${big(voters, "cử tri", `${pct(voters, living.length)}% dân số`)}
        ${big(avgAge, "tuổi trung bình")}
      </div>
    </div>`;
}

/* ---------- Population pyramid ---------- */

function dashPyramid(living) {
  const rows = AGE_BANDS.map(([min, max, label]) => {
    const band = living.filter(r => { const a = ageOf(r); return a !== null && a >= min && a <= max; });
    return {
      label,
      male: band.filter(r => r.gender === "Nam").length,
      female: band.filter(r => r.gender === "Nữ").length,
      unknown: band.filter(r => !r.gender).length
    };
  });

  const widest = Math.max(1, ...rows.map(r => Math.max(r.male, r.female)));
  const bar = (n, side) => `
    <div class="pyr-side pyr-${side}">
      <span class="pyr-bar" style="width:${(n / widest) * 100}%"></span>
      <em>${n || ""}</em>
    </div>`;

  const male = living.filter(r => r.gender === "Nam").length;
  const female = living.filter(r => r.gender === "Nữ").length;
  const unknown = living.length - male - female;

  return `
    <div class="dash-card">
      <div class="dash-card-head">
        <h3>Tháp dân số</h3>
        <div class="legend">
          <span><i class="dot dot-male"></i>Nam ${male}</span>
          <span><i class="dot dot-female"></i>Nữ ${female}</span>
        </div>
      </div>

      <div class="pyramid">
        <div class="pyr-row pyr-head"><span></span><span></span><span></span><span class="pyr-unk">Chưa ghi<br>giới tính</span></div>
        ${rows.map(r => `
          <div class="pyr-row">
            ${bar(r.male, "male")}
            <span class="pyr-label">${r.label}</span>
            ${bar(r.female, "female")}
            <span class="pyr-unk">${r.unknown || ""}</span>
          </div>`).join("")}
      </div>

      ${unknown ? `
        <p class="dash-foot">${unknown} người (${pct(unknown, living.length)}%) chưa ghi giới tính nên chỉ có ở cột bên phải.
          <button class="link-button" onclick="showUnknownGenderList()">Xem danh sách</button></p>` : ""}
    </div>`;
}

function showUnknownGenderList() {
  memPreset = "";
  memFilters = { ...emptyMemberFilters(), gender: "?" };
  memSortByAge = false;
  setTab("members");
}

/* ---------- Life stages ---------- */

function dashLifeStages(living) {
  const counts = LIFE_STAGES.map(s => ({ ...s, n: living.filter(r => ageGroupOf(r) === s.group).length }))
    .filter(s => s.n);

  return `
    <div class="dash-card">
      <h3>Các lứa tuổi</h3>
      <div class="stage-bar">
        ${counts.map(s => `<span class="${s.cls}" style="flex:${s.n}" title="${esc(s.label)}: ${s.n}"></span>`).join("")}
      </div>
      <div class="stage-list">
        ${counts.map(s => `
          <div>
            <i class="dot ${s.cls}"></i>
            <span>${esc(s.label)}</span>
            <strong>${s.n}</strong>
            <small>${pct(s.n, living.length)}%</small>
          </div>`).join("")}
      </div>
    </div>`;
}

/* ---------- Coming up this year ---------- */

function dashThisYear(living) {
  const count = id => {
    const p = PRESETS.find(x => x.id === id);
    return p ? living.filter(r => residentMatches(r, { ...emptyMemberFilters(), ...p.f })).length : 0;
  };
  const firstGrade = living.filter(r => ageOf(r) === 6).length;

  const oldest = living
    .filter(r => ageOf(r) !== null)
    .sort((a, b) => ageOf(b) - ageOf(a))[0];

  const tile = (n, label, presetId) => presetId
    ? `<button class="year-tile" onclick="openMemberList('${presetId}')"><strong>${n}</strong><span>${esc(label)}</span></button>`
    : `<div class="year-tile"><strong>${n}</strong><span>${esc(label)}</span></div>`;

  return `
    <div class="dash-card">
      <h3>Trong năm ${AGE_YEAR}</h3>
      <div class="year-tiles">
        ${tile(count("mungtho"), "được mừng thọ", "mungtho")}
        ${tile(firstGrade, "cháu vào lớp 1")}
        ${tile(count("nvqs17"), "nam 17–19 đăng ký NVQS", "nvqs17")}
        ${tile(count("caotuoi"), "người cao tuổi", "caotuoi")}
      </div>
      ${oldest ? `
        <p class="dash-foot">Người cao tuổi nhất tổ: <strong>${esc(oldest.full_name)}</strong>,
          ${ageOf(oldest)} tuổi, ở ${esc(householdAddress(oldest.household_id))}.</p>` : ""}
    </div>`;
}

/* ---------- Where people live ---------- */

function dashAreas(living) {
  const areas = new Map();

  living.forEach(r => {
    const key = areaOf(householdAddress(r.household_id));
    if (!areas.has(key)) areas.set(key, { people: 0, households: new Set() });
    const a = areas.get(key);
    a.people++;
    a.households.add(String(r.household_id));
  });

  const list = [...areas.entries()]
    .map(([name, a]) => ({ name, people: a.people, households: a.households.size }))
    .sort((a, b) => b.people - a.people);

  const top = list.slice(0, 8);
  const rest = list.slice(8);
  const most = Math.max(1, ...top.map(a => a.people));

  return `
    <div class="dash-card">
      <h3>Nơi ở</h3>
      <div class="area-list">
        ${top.map(a => `
          <div class="area-row">
            <div class="area-name">
              <span>${esc(a.name)}</span>
              <small>${a.households} hộ · ${a.people} người</small>
            </div>
            <div class="area-track"><span style="width:${(a.people / most) * 100}%"></span></div>
          </div>`).join("")}
      </div>
      ${rest.length ? `<p class="dash-foot">Và ${rest.length} khu vực khác với ${rest.reduce((s, a) => s + a.people, 0)} người.</p>` : ""}
    </div>`;
}

/* ---------- Birthdays this month ---------- */

function dashBirthdays(living) {
  const now = new Date();
  const month = now.getMonth() + 1;

  const people = living
    .filter(r => r.dob && Number(String(r.dob).slice(5, 7)) === month)
    .map(r => ({ r, day: Number(String(r.dob).slice(8, 10)) }))
    // Days still to come first, then the ones already past this month.
    .sort((a, b) => (a.day < now.getDate()) - (b.day < now.getDate()) || a.day - b.day);

  const shown = people.slice(0, 10);

  return `
    <div class="dash-card">
      <div class="dash-card-head">
        <h3>Sinh nhật tháng ${month}</h3>
        <span class="dash-count">${people.length} người</span>
      </div>
      ${shown.length ? `
        <ul class="birthday-list">
          ${shown.map(({ r, day }) => `
            <li class="${day === now.getDate() ? "today" : day < now.getDate() ? "past" : ""}">
              <span class="bday-date">${String(day).padStart(2, "0")}/${String(month).padStart(2, "0")}</span>
              <span class="bday-name">${esc(r.full_name)}</span>
              <span class="bday-age">${ageOf(r) ?? ""} tuổi</span>
            </li>`).join("")}
        </ul>
        ${people.length > shown.length ? `<p class="dash-foot">và ${people.length - shown.length} người nữa.</p>` : ""}
      ` : `<p class="empty-hint">Chưa có ai có ngày sinh đầy đủ trong tháng này.</p>`}
    </div>`;
}

/* ---------- Common activities ---------- */

function dashActivities() {
  const list = DB.activities
    .slice()
    .sort((a, b) => String(b.activity_date || "").localeCompare(String(a.activity_date || "")))
    .slice(0, 4);

  const total = DB.households.length;

  return `
    <div class="dash-card">
      <h3>Hoạt động chung</h3>
      ${list.length ? list.map(a => {
        const rows = DB.householdActivities.filter(x => Number(x.activity_id) === Number(a.activity_id));
        const paid = rows.filter(x => Number(x.paid));
        const amount = paid.reduce((s, x) => s + (Number(x.amount) || 0), 0);
        return `
          <div class="activity-row">
            <div class="activity-row-head">
              <strong>${esc(a.name)}</strong>
              <span>${paid.length}/${total} hộ</span>
            </div>
            <div class="area-track"><span class="paid" style="width:${pct(paid.length, total)}%"></span></div>
            <small>${activityPeriod(a) ? esc(activityPeriod(a)) + " · " : ""}Đã thu ${money(amount)} đ</small>
          </div>`;
      }).join("") : `<p class="empty-hint">Chưa có hoạt động chung.</p>`}

      ${DB.associations.length ? `
        <h3 class="dash-subhead">Hội, đoàn thể</h3>
        <div class="chip-list">
          ${DB.associations.map(a => `
            <button class="chip" onclick="showAssociationMembers(${a.association_id})">
              ${esc(a.name)} <span class="chip-count">${DB.residentAssociations.filter(x => Number(x.association_id) === Number(a.association_id)).length}</span>
            </button>`).join("")}
        </div>` : ""}
    </div>`;
}

/* ---------- How complete the records are ---------- */

function dashRecords(living) {
  const fields = [
    ["Giới tính", r => r.gender],
    ["Năm sinh", r => r.birth_year],
    ["Ngày sinh đầy đủ", r => r.dob],
    ["Quan hệ với chủ hộ", r => r.relation],
    ["CCCD", r => r.cccd],
    ["Điện thoại", r => r.phone]
  ];
  const toCheck = DB.residents.filter(r => r.check_note).length;

  return `
    <div class="dash-card">
      <h3>Thông tin đã thu thập</h3>
      <div class="record-list">
        ${fields.map(([label, has]) => {
          const p = pct(living.filter(has).length, living.length);
          return `
            <div class="record-row">
              <span>${esc(label)}</span>
              <div class="area-track"><span class="${p >= 80 ? "good" : p >= 50 ? "mid" : "low"}" style="width:${p}%"></span></div>
              <strong>${p}%</strong>
            </div>`;
        }).join("")}
      </div>
      ${toCheck ? `
        <button class="check-callout" onclick="openMemberList('kiemtra')">
          <strong>${toCheck}</strong> người có điểm cần kiểm tra lại <span>Xem danh sách</span>
        </button>` : ""}
    </div>`;
}

/* ============================================================
 * CƯ DÂN
 * Quick lists + one search box. Filtering happens in the browser.
 * ============================================================ */

const LONGEVITY_AGES = [70, 80, 90, 100];

/*
 * Quick lists. Each one is just a set of filter values, so adding a new
 * list means adding one line here.
 */
const PRESETS = [
  { id: "all",      label: "Tất cả",                      f: {} },
  { id: "mamnon",   label: "Mầm non (dưới 6)",            f: { ageMax: 5 } },
  { id: "treem",    label: "Trẻ em (dưới 16)",            f: { ageMax: 15 } },
  { id: "hocsinh",  label: "Học sinh (6–17)",             f: { ageMin: 6, ageMax: 17 } },
  { id: "sinhvien", label: "Sinh viên (18–22)",           f: { ageMin: 18, ageMax: 22 } },
  { id: "phunu",    label: "Phụ nữ 22–35",                f: { gender: "Nữ", ageMin: 22, ageMax: 35 } },
  { id: "nvqs17",   label: "Nam 17-19 (đăng ký NVQS)",    f: { gender: "Nam", ageMin: 17, ageMax: 19 } },
  { id: "nvqs",     label: "Nam 18–27 (NVQS)",            f: { gender: "Nam", ageMin: 18, ageMax: 27 } },
  { id: "caotuoi",  label: "Người cao tuổi (từ 60)",      f: { ageMin: 60 } },
  { id: "mungtho",  label: "Mừng thọ (70, 80, 90, 100)",  f: { longevity: true }, sortByAge: true },
  { id: "cutri",    label: "Cử tri",                      f: { voter: "1" } },
  { id: "kiemtra",  label: "Cần kiểm tra",                f: { check: true } }
];

/* The search box only sets q; the other keys are set by the quick lists. */
function emptyMemberFilters() {
  return {
    q: "", gender: "", ageMin: "", ageMax: "", status: "Đang ở",
    voter: "", association: "", check: false, longevity: false
  };
}

let memPreset = "all";
let memFilters = emptyMemberFilters();
let memSortByAge = false;

function residentMatches(r, f, ignoreGender = false) {
  if (f.status && r.status !== f.status) return false;

  if (!ignoreGender && f.gender) {
    if (f.gender === "?" ? r.gender !== "" : r.gender !== f.gender) return false;
  }

  const age = ageOf(r);
  if (f.ageMin !== "" && f.ageMin !== undefined && (age === null || age < Number(f.ageMin))) return false;
  if (f.ageMax !== "" && f.ageMax !== undefined && (age === null || age > Number(f.ageMax))) return false;
  if (f.longevity && (age === null || !(LONGEVITY_AGES.includes(age) || age >= 100))) return false;

  if (f.voter && Number(r.is_voter) !== Number(f.voter)) return false;
  if (f.association && !residentHasAssociation(r.resident_id, f.association)) return false;
  if (f.check && !r.check_note) return false;

  // Every typed word must appear somewhere in name, code, CCCD, phone, job or address.
  if (f.q) {
    const text = plain(`${r.full_name} ${r.code} ${r.cccd} ${r.phone} ${r.job} ${householdAddress(r.household_id)}`);
    const words = plain(f.q).trim().split(/\s+/).filter(Boolean);
    if (!words.every(w => text.includes(w))) return false;
  }

  return true;
}

function filteredResidents() {
  const list = DB.residents.filter(r => residentMatches(r, memFilters));

  if (memSortByAge) {
    list.sort((a, b) => (ageOf(b) ?? -1) - (ageOf(a) ?? -1));
  }

  return list;
}

function presetCount(p) {
  return DB.residents.filter(r => residentMatches(r, { ...emptyMemberFilters(), ...p.f })).length;
}

function memberListTitle() {
  const p = PRESETS.find(x => x.id === memPreset);
  if (p) return p.label;

  if (memFilters.association) {
    const a = DB.associations.find(x => String(x.association_id) === String(memFilters.association));
    if (a) return a.name;
  }
  if (memFilters.gender === "?") return "Chưa ghi giới tính";

  return "Danh sách";
}

function renderMembers() {
  return `
    <section class="page">
      <div class="page-header">
        <div>
          <h2>Cư dân</h2>
          <p>Danh sách nhân khẩu trong địa bàn</p>
        </div>
        <div class="actions no-print">
          <button class="btn-secondary" onclick="printMembers()">In danh sách</button>
          <button class="btn-primary" onclick="openMemberForm()">+ Thêm cư dân</button>
        </div>
      </div>

      <div class="card quick-lists no-print">
        <h3>Danh sách nhanh</h3>
        <div class="chip-list" id="member-presets"></div>
      </div>

      <div class="toolbar no-print">
        <input class="search-input" type="search"
          placeholder="Tìm tên, CCCD, địa chỉ ..."
          value="${esc(memFilters.q)}" oninput="setMemberSearch(this.value)">
      </div>

      <div id="member-notice"></div>
      <div class="result-bar">
        <strong id="member-title"></strong>
        <span id="member-count"></span>
      </div>
      <div class="card" id="member-rows"></div>
    </section>
  `;
}

/* Redraws only the results, so typing in the search box keeps the cursor in place. */
function updateMemberResults() {
  const rows = filteredResidents();

  document.getElementById("member-presets").innerHTML = PRESETS.map(p => `
    <button class="chip ${memPreset === p.id ? "selected" : ""}" onclick="applyMemberPreset('${p.id}')">
      ${esc(p.label)} <span class="chip-count">${presetCount(p)}</span>
    </button>
  `).join("");

  document.getElementById("member-title").textContent = memberListTitle();
  document.getElementById("member-count").textContent = `${rows.length} người · tính tuổi theo năm ${AGE_YEAR}`;

  // People left out only because their gender is not recorded.
  let notice = "";
  if (memFilters.gender === "Nam" || memFilters.gender === "Nữ") {
    const unknown = DB.residents.filter(r => r.gender === "" && residentMatches(r, memFilters, true)).length;
    if (unknown) {
      notice = `
        <div class="import-notice no-print">
          <strong>${unknown} người khác cũng hợp điều kiện nhưng chưa ghi giới tính</strong>
          <p>Họ không có trong danh sách này. <button class="btn-small" onclick="showUnknownGender()">Xem ${unknown} người này</button></p>
        </div>`;
    }
  }
  document.getElementById("member-notice").innerHTML = notice;

  document.getElementById("member-rows").innerHTML = `
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>#</th><th>Họ và tên</th><th>Giới tính</th><th>Ngày sinh</th><th>Tuổi</th>
            <th>Hộ gia đình</th><th>Quan hệ</th><th>Điện thoại</th><th class="no-print"></th>
          </tr>
        </thead>
        <tbody>
          ${rows.length ? rows.map((r, i) => {
            const age = ageOf(r);
            return `
              <tr>
                <td>${i + 1}</td>
                <td>
                  <strong>${esc(r.full_name)}</strong>
                  ${Number(r.is_voter) ? `<span class="badge">Cử tri</span>` : ""}
                  ${isLiving(r) ? "" : `<span class="badge">${esc(r.status)}</span>`}
                  ${r.check_note ? `<button type="button" class="badge badge-check no-print" onclick="openCheckModal(${r.resident_id})">Cần kiểm tra (${checkItems(r).length})</button>` : ""}
                  ${r.check_note && memFilters.check ? checkSummary(r) : ""}
                </td>
                <td>${esc(r.gender)}</td>
                <td>${esc(dobText(r))}</td>
                <td><span class="age-badge ${ageGroupColors[ageGroupOf(r)] || ""}">${age ?? "—"}</span></td>
                <td>${esc(householdAddress(r.household_id))}</td>
                <td>${esc(r.relation)}</td>
                <td>${esc(r.phone)}</td>
                <td class="actions no-print">
                  <button class="btn-icon" title="Sửa" aria-label="Sửa ${esc(r.full_name)}"
                    onclick="openMemberForm(${r.resident_id})">✎</button>
                </td>
              </tr>`;
          }).join("") : `
            <tr><td colspan="9" class="empty-state">${DB.residents.length
              ? "Không có cư dân phù hợp. Thử từ khóa khác hoặc chọn “Tất cả”."
              : "Chưa có dữ liệu. Vào “Import dữ liệu” để nạp file dân cư."}</td></tr>`}
        </tbody>
      </table>
    </div>
  `;
}

/* The search works on top of the selected quick list. */
function setMemberSearch(value) {
  memFilters.q = value;
  updateMemberResults();
}

function applyMemberPreset(id) {
  const p = PRESETS.find(x => x.id === id);
  if (!p) return;
  memPreset = id;
  memFilters = { ...emptyMemberFilters(), ...p.f, q: memFilters.q };
  memSortByAge = !!p.sortByAge;
  render();
}

function showUnknownGender() {
  memFilters = { ...memFilters, gender: "?" };
  memPreset = "";
  render();
}

/*
 * "In danh sách": opens the current list as a clean page in a new tab.
 * From there the user can print it or save it as PDF (browser print dialog).
 */
function printMembers() {
  const rows = filteredResidents();
  const title = memberListTitle();
  const today = new Date().toLocaleDateString("vi-VN");

  const win = window.open("", "_blank");
  if (!win) {
    alert("Trình duyệt đã chặn cửa sổ mới. Hãy cho phép cửa sổ bật lên (pop-up) cho trang này rồi bấm lại.");
    return;
  }

  const body = rows.map((r, i) => `
    <tr>
      <td class="num">${i + 1}</td>
      <td class="name">${esc(r.full_name)}</td>
      <td>${esc(r.gender)}</td>
      <td>${esc(dobText(r))}</td>
      <td class="num">${ageOf(r) ?? ""}</td>
      <td>${esc(householdAddress(r.household_id))}</td>
      <td>${esc(r.phone)}</td>
    </tr>`).join("");

  win.document.write(`<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<title>${esc(title)} – ${esc(COMMUNITY_NAME)}</title>
<style>
  /* A4 portrait: 210 x 297 mm, 12 mm side margins -> 186 mm for the table (7 columns). */
  @page { size: A4 portrait; margin: 14mm 12mm; }
  * { box-sizing: border-box; }
  html { background: #E6EAEE; }
  body { margin: 0; font-family: "Times New Roman", Times, serif; font-size: 11pt; line-height: 1.3; color: #000; }

  .toolbar { position: sticky; top: 0; z-index: 1; display: flex; gap: 8px; justify-content: center;
             padding: 12px; background: #E6EAEE; font-family: "Segoe UI", Arial, sans-serif; }
  .toolbar button { padding: 8px 16px; border: 1px solid #2E86C1; border-radius: 5px; background: #2E86C1;
                    color: #fff; font: inherit; font-size: 14px; font-weight: 600; cursor: pointer; }
  .toolbar button.plain { border-color: #C9D3DA; background: #fff; color: #14212E; }

  /* On screen: one A4 sheet, so what you see is what prints. */
  .sheet { width: 210mm; min-height: 297mm; margin: 0 auto 24px; padding: 14mm 12mm;
           background: #fff; box-shadow: 0 2px 12px rgba(0, 0, 0, .18); }

  header { text-align: center; margin-bottom: 5mm; }
  header .unit { font-size: 11pt; }
  header h1 { margin: 3mm 0 1mm; font-size: 15pt; }
  header p { margin: 0; font-size: 10.5pt; font-style: italic; }

  table { width: 100%; border-collapse: collapse; table-layout: fixed; }
  th, td { border: 0.6pt solid #000; padding: 1.2mm 1.5mm; vertical-align: top; overflow-wrap: anywhere; }
  th { background: #EDEDED; font-size: 10pt; font-weight: bold; text-align: center; vertical-align: middle; }
  td.num { text-align: center; }
  td.name { font-weight: bold; }
  thead { display: table-header-group; }
  tr { break-inside: avoid; page-break-inside: avoid; }

  .sign { display: flex; justify-content: flex-end; margin-top: 8mm; break-inside: avoid; }
  .sign div { width: 45%; text-align: center; }
  .sign p { margin: 0 0 22mm; font-style: italic; }

  @media print {
    html { background: #fff; }
    .toolbar { display: none; }
    .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
    th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  }
</style>
</head>
<body>
  <div class="toolbar">
    <button class="plain" onclick="window.close()">Đóng</button>
    <button onclick="window.print()">In hoặc lưu PDF</button>
  </div>

  <main class="sheet">
  <header>
    <div class="unit">${esc(COMMUNITY_NAME)}</div>
    <h1>${esc(title)}</h1>
    <p>${rows.length} người · tuổi tính theo năm ${AGE_YEAR} · lập ngày ${today}</p>
  </header>

  <table>
    <colgroup>
      <col style="width:10mm"><col style="width:48mm"><col style="width:13mm"><col style="width:22mm">
      <col style="width:11mm"><col style="width:56mm"><col style="width:26mm">
    </colgroup>
    <thead>
      <tr>
        <th>STT</th><th>Họ và tên</th><th>Giới<br>tính</th><th>Ngày sinh</th><th>Tuổi</th>
        <th>Địa chỉ</th><th>Điện thoại</th>
      </tr>
    </thead>
    <tbody>${body || `<tr><td colspan="7" class="num">Không có người nào trong danh sách.</td></tr>`}</tbody>
  </table>

  <div class="sign">
    <div>
      <p>Ngày ...... tháng ...... năm ${AGE_YEAR}</p>
      <strong>Người lập danh sách</strong>
    </div>
  </div>
  </main>
</body>
</html>`);
  win.document.close();
}

/* ---------- Resident form ---------- */

function toggleChipAssoc(el) {
  el.classList.toggle("selected");
}

/* What the Ngày sinh box shows: the full date if known, otherwise the year. */
function birthText(r) {
  if (!r) return "";
  return r.dob ? formatDob(r.dob) : (r.birth_year ?? "");
}

/*
 * Reads "12/08/1992", "12-8-1992", "12.08.1992" or "1992".
 * Returns { dob, birth_year }, or null if it can't be read.
 */
function parseBirth(text) {
  const s = String(text || "").trim();
  if (!s) return { dob: "", birth_year: "" };

  const thisYear = new Date().getFullYear();
  const okYear = y => y >= 1900 && y <= thisYear;
  let m;

  if ((m = s.match(/^(\d{4})$/))) {
    const y = Number(m[1]);
    return okYear(y) ? { dob: "", birth_year: y } : null;
  }

  if ((m = s.match(/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/))) {
    const [d, mo, y] = [Number(m[1]), Number(m[2]), Number(m[3])];
    const date = new Date(y, mo - 1, d);
    const real = date.getFullYear() === y && date.getMonth() === mo - 1 && date.getDate() === d;
    if (real && okYear(y)) {
      const pad = n => String(n).padStart(2, "0");
      return { dob: `${y}-${pad(mo)}-${pad(d)}`, birth_year: y };
    }
  }

  return null;
}

function openMemberForm(id = null, householdId = null) {
  const r = id ? DB.residents.find(x => Number(x.resident_id) === Number(id)) : null;
  const selected = new Set(r ? associationIdsOf(r.resident_id) : []);
  const households = DB.households.map(h => [String(h.household_id), `${h.code} – ${h.address}`]);
  const checks = checkItems(r || {});

  showFormModal(`
    ${formHeader(r ? "Sửa cư dân" : "Thêm cư dân")}
    <form onsubmit="submitMemberForm(event, ${r?.resident_id || 0})">
      <div class="form-meta">
        ${r ? `<span>Mã ${esc(r.code)}</span>` : ""}
        <label class="check-field">
          <input type="checkbox" name="is_voter" ${Number(r?.is_voter) ? "checked" : ""}>
          <span>Là cử tri</span>
        </label>
      </div>

      <div class="form-grid form-grid-3">
        ${formInput("Họ và tên", "full_name", r?.full_name || "", "text", "required")}
        ${formSelect("Hộ gia đình", "household_id", r?.household_id ?? householdId ?? "", [["", "Chọn hộ..."], ...households], "required")}
        ${formSelect("Giới tính", "gender", r?.gender || "", [["", "Chưa ghi"], "Nam", "Nữ"])}
        ${formSelect("Quan hệ với chủ hộ", "relation", r?.relation || "", [["", "Chưa ghi"], ...RELATION_OPTIONS])}
        ${formInput("Ngày sinh (hoặc chỉ năm)", "birth", birthText(r), "text", 'placeholder="VD: 12/08/1992 hoặc 1992"')}
        ${formInput("CCCD", "cccd", r?.cccd || "")}
        ${formInput("Dân tộc", "ethnicity", r?.ethnicity || "")}
        ${formInput("Nghề nghiệp", "job", r?.job || "")}
        ${formInput("Điện thoại", "phone", r?.phone || "")}
        ${formInput("Email", "email", r?.email || "")}
        ${formSelect("Tình trạng", "status", r?.status || "Đang ở", STATUS_OPTIONS)}
      </div>

      <label class="form-field">
        <span>Hội, đoàn thể</span>
        <div class="chip-list">
          ${DB.associations.length ? DB.associations.map(a => `
            <button type="button" class="chip ${selected.has(Number(a.association_id)) ? "selected" : ""}"
              data-association-id="${a.association_id}" onclick="toggleChipAssoc(this)">${esc(a.name)}</button>
          `).join("") : `<span class="empty-hint">Chưa có hội, đoàn thể.</span>`}
        </div>
      </label>

      ${formTextarea("Ghi chú", "notes", r?.notes || "")}

      <input type="hidden" name="check_note" value="${esc(checks.join("\n"))}">
      ${checks.length ? `
        <div class="form-field">
          <span>Cần kiểm tra</span>
          <div class="import-notice check-box">${renderCheckItems(r, true)}</div>
        </div>` : ""}

      ${r?.source ? `<p class="empty-hint">Nguồn thông tin: ${esc(r.source)}</p>` : ""}

      ${r ? `
        <div class="form-delete">
          <button type="button" class="btn-small danger" onclick="removeMember(${r.resident_id})">Xóa cư dân này</button>
        </div>` : ""}

      ${formActions(r ? "Lưu thay đổi" : "Thêm cư dân")}
    </form>
  `);
}

async function submitMemberForm(e, id) {
  e.preventDefault();
  const r = DB.residents.find(x => Number(x.resident_id) === Number(id));
  const { birth, ...values } = formValues(e.target);

  const parsed = parseBirth(birth);
  if (!parsed) {
    alert("Ngày sinh chưa đúng. Nhập dạng 12/08/1992, hoặc chỉ năm sinh như 1992.");
    e.target.elements.birth.focus();
    return;
  }

  const data = {
    ...values,
    ...parsed,
    resident_id: id,
    source: r?.source || "",
    associationIds: [...e.target.querySelectorAll(".chip.selected")].map(x => Number(x.dataset.associationId))
  };

  if (await saveAndRefresh("resident.save", data)) closeFormModal();
}

async function removeMember(id) {
  const r = DB.residents.find(x => Number(x.resident_id) === Number(id));
  if (!r) return;

  if (!confirm(`Xóa hẳn cư dân "${r.full_name}"?\n\nNếu người này chuyển đi hoặc đã mất, nên đổi "Tình trạng" thay vì xóa để giữ lịch sử.`)) return;

  if (await saveAndRefresh("resident.delete", { resident_id: id })) closeFormModal();
}

/* ============================================================
 * CẦN KIỂM TRA
 * check_note holds one or more issues found when the Excel lists
 * were merged. Each issue is shown with what it means, what is
 * stored now, and what to do; "Đã xử lý" removes that one issue.
 * ============================================================ */

/* Issues are separated by new lines or "; " (as written by the Excel merge). */
function checkItems(r) {
  const parts = String(r?.check_note || "").split(/\n|; /).map(x => x.trim()).filter(Boolean);
  const items = [];

  parts.forEach(part => {
    // "Đã lấy tên theo DS cử tri; sổ cũ ghi: X" was split in two: glue it back.
    if (/^sổ cũ ghi/i.test(part) && items.length) items[items.length - 1] += "; " + part;
    else items.push(part);
  });

  return items;
}

function residentByCode(code) {
  return DB.residents.find(x => x.code === code) || null;
}

function currentDob(r) {
  return dobText(r) || "chưa có";
}

/* Turns one issue into { title, detail, todo } in plain words. */
function explainCheck(item, r) {
  let m;

  if ((m = item.match(/^Không có trong sổ cư dân, thêm từ (.+?) –/))) {
    return {
      title: "Người mới, chưa có trong sổ cư dân cũ",
      detail: `Chỉ có trong danh sách ${m[1]}, nên được thêm vào khi gộp dữ liệu.`,
      todo: "Xác nhận người này còn ở tổ. Nếu không, đổi Tình trạng thành “Đã chuyển đi”. Nên bổ sung Quan hệ với chủ hộ."
    };
  }

  if ((m = item.match(/^Đã lấy tên theo DS cử tri; sổ cũ ghi: (.+)$/))) {
    return {
      title: "Tên khác nhau giữa hai danh sách",
      detail: `Đang lưu: “${r.full_name}” (theo DS cử tri). Sổ cư dân cũ ghi: “${m[1]}”.`,
      todo: "Đối chiếu với CCCD. Nếu tên trong sổ cũ mới đúng thì sửa lại Họ và tên."
    };
  }

  if ((m = item.match(/^Đã lấy ngày sinh theo DS cử tri; sổ cũ ghi: (.+)$/))) {
    return {
      title: "Ngày sinh khác nhau giữa hai danh sách",
      detail: `Đang lưu: ${currentDob(r)} (theo DS cử tri). Sổ cư dân cũ ghi: ${m[1]}.`,
      todo: "Đối chiếu với CCCD. Nếu sổ cũ đúng thì sửa lại Ngày sinh."
    };
  }

  if ((m = item.match(/^(.+?) ghi (?:năm|ngày) sinh (.+)$/))) {
    const year = Number((m[2].match(/(\d{4})\s*$/) || [])[1]);
    const sameYear = year && Number(r.birth_year) === year;

    if (sameYear && formatDob(r.dob) === m[2]) {
      return {
        title: "Ngày sinh đã khớp",
        detail: `${m[1]} ghi ${m[2]}, giống ngày sinh đang lưu.`,
        todo: "Không cần sửa gì, bấm “Đã xử lý”."
      };
    }

    return sameYear ? {
      title: "Ngày sinh khác nhau giữa các danh sách (cùng năm)",
      detail: `Đang lưu: ${currentDob(r)}. ${m[1]} ghi: ${m[2]}.`,
      todo: `Đối chiếu với CCCD. Nếu ${m[1]} đúng thì sửa Ngày sinh.`
    } : {
      title: "Năm sinh khác nhau giữa các danh sách",
      detail: `Đang lưu: ${currentDob(r)}. ${m[1]} ghi: ${m[2]}.`,
      todo: `Đối chiếu với CCCD. Nếu ${m[1]} đúng thì sửa Ngày sinh / Năm sinh. Nếu lệch nhiều năm, có thể là hai người trùng tên.`
    };
  }

  if ((m = item.match(/^Năm sinh (\d{4}) khác ngày sinh (.+)$/))) {
    return {
      title: "Năm sinh và ngày sinh trong sổ cũ không khớp",
      detail: `Sổ cũ ghi năm sinh ${m[1]} nhưng ngày sinh ${m[2]}. Đang lưu: ${currentDob(r)}.`,
      todo: "Hỏi lại hoặc xem CCCD, rồi sửa cho đúng."
    };
  }

  if ((m = item.match(/^Các nguồn ghi ngày sinh khác nhau: (.+)$/)) ||
      (m = item.match(/^Quân sự 2005 ghi hai ngày sinh: (.+)$/))) {
    return {
      title: "Có nhiều ngày sinh khác nhau",
      detail: `Các ngày được ghi: ${m[1]}. Đang lưu: ${currentDob(r)}.`,
      todo: "Xem CCCD để chọn ngày đúng."
    };
  }

  if ((m = item.match(/^(Giới tính|CCCD) khác (.+?) \((.+)\)$/))) {
    const current = m[1] === "Giới tính" ? r.gender : r.cccd;
    return {
      title: `${m[1]} khác nhau giữa các danh sách`,
      detail: `Đang lưu: ${current || "chưa có"}. ${m[2]} ghi: ${m[3]}.`,
      todo: `Kiểm tra lại và sửa ${m[1]} nếu cần.`
    };
  }

  if ((m = item.match(/^(.+?) ghi tên: (.+)$/)) || (m = item.match(/^Tên khác (.+?): (.+)$/))) {
    return {
      title: "Tên hơi khác trong một danh sách khác",
      detail: `Đang lưu: “${r.full_name}”. ${m[1]} ghi: “${m[2]}”. Hai tên được coi là cùng một người.`,
      todo: "Nếu đúng là cùng một người thì chỉ cần bấm “Đã xử lý”."
    };
  }

  if ((m = item.match(/^Có thể trùng với (.+?) \(/))) {
    const others = m[1].split(",").map(x => x.trim()).map(code => {
      const o = residentByCode(code);
      return o ? `${code} (${o.full_name}, ${dobText(o) || "không rõ năm sinh"}, ${householdAddress(o.household_id)})` : code;
    });
    return {
      title: "Có thể bị nhập trùng",
      detail: `Cùng tên và cùng năm sinh với: ${others.join("; ")}. Người này ở: ${householdAddress(r.household_id)}.`,
      todo: "Nếu là cùng một người, xóa một dòng (giữ dòng đủ thông tin hơn). Nếu là hai người khác nhau, bấm “Đã xử lý”.",
      codes: m[1].split(",").map(x => x.trim())
    };
  }

  if (/^Ghi chú cho thấy/.test(item)) {
    return {
      title: "Có thể không còn ở tổ",
      detail: `Ghi chú của người này: “${r.notes || "(trống)"}”. Tình trạng đang là: ${r.status}.`,
      todo: "Nếu đã chuyển đi hoặc chỉ về quê một thời gian, đổi Tình trạng cho đúng (Đã chuyển đi / Tạm vắng)."
    };
  }

  if (/^Thiếu năm sinh/.test(item)) {
    return { title: "Thiếu năm sinh", detail: "Chưa có ngày sinh và năm sinh.", todo: "Bổ sung để người này có trong các danh sách theo tuổi." };
  }

  if ((m = item.match(/^Ngày sinh không đọc được: (.+)$/))) {
    return { title: "Ngày sinh ghi sai định dạng", detail: `Sổ cũ ghi: ${m[1]}.`, todo: "Nhập lại Ngày sinh đúng." };
  }

  return { title: item, detail: "", todo: "" };
}

/* Short version shown inside the table (Cần kiểm tra list). */
function checkSummary(r) {
  return `<div class="check-summary no-print">${checkItems(r).map(item => esc(explainCheck(item, r).title)).join(" · ")}</div>`;
}

/*
 * Explained list of issues.
 * In the check modal, "Đã xử lý" saves at once.
 * Inside the resident form, it only removes the item; it is saved with "Lưu thay đổi".
 */
function renderCheckItems(r, inForm = false) {
  return `
    <ol class="check-list">
      ${checkItems(r).map((item, i) => {
        const e = explainCheck(item, r);
        const actions = inForm
          ? `<button type="button" class="btn-small" onclick="dismissCheckInForm(this)">Đã xử lý</button>`
          : `${(e.codes || []).map(code => residentByCode(code)
              ? `<button type="button" class="btn-small" onclick="openCheckModal(${residentByCode(code).resident_id})">Xem ${esc(code)}</button>` : "").join("")}
             <button type="button" class="btn-small" onclick="resolveCheck(${r.resident_id}, ${i})">Đã xử lý</button>`;
        return `
          <li data-item="${esc(item)}">
            <strong>${esc(e.title)}</strong>
            ${e.detail ? `<p>${esc(e.detail)}</p>` : ""}
            ${e.todo ? `<p class="check-todo">→ ${esc(e.todo)}</p>` : ""}
            <div class="check-actions">${actions}</div>
          </li>`;
      }).join("")}
    </ol>
  `;
}

/* "Đã xử lý" inside the resident form: remove the item, save later with the form. */
function dismissCheckInForm(button) {
  const form = button.form;
  const box = button.closest(".check-box");
  button.closest("li").remove();

  const left = [...box.querySelectorAll("li")].map(li => li.dataset.item);
  form.elements.check_note.value = left.join("\n");

  if (!left.length) {
    box.innerHTML = `<p class="check-done">Đã xử lý hết. Bấm “Lưu thay đổi” để lưu.</p>`;
  }
}

function openCheckModal(id) {
  const r = DB.residents.find(x => Number(x.resident_id) === Number(id));
  if (!r) return;

  showFormModal(`
    ${formHeader(`Cần kiểm tra: ${r.full_name}`,
      `${r.code} · ${currentDob(r)} · ${householdAddress(r.household_id)}${r.source ? ` · Nguồn: ${r.source}` : ""}`)}
    ${checkItems(r).length ? renderCheckItems(r) : `<div class="form-success">Đã xử lý hết các điểm cần kiểm tra.</div>`}
    <div class="form-actions">
      <button type="button" class="btn-secondary" onclick="closeFormModal()">Đóng</button>
      <button type="button" class="btn-primary" onclick="openMemberForm(${r.resident_id})">Sửa thông tin</button>
    </div>
  `);
}

/* Removes one issue and keeps everything else about the person unchanged. */
async function resolveCheck(id, index) {
  const r = DB.residents.find(x => Number(x.resident_id) === Number(id));
  if (!r) return;

  const items = checkItems(r);
  items.splice(index, 1);

  // No associationIds in the payload, so memberships stay as they are.
  const ok = await saveAndRefresh("resident.save", { ...r, check_note: items.join("\n") });
  if (ok) openCheckModal(id);
}

/* ============================================================
 * HỘI, ĐOÀN THỂ
 * ============================================================ */

function renderAssociations() {
  return `
    <section class="page">
      <div class="page-header">
        <div>
          <h2>Hội, đoàn thể</h2>
          <p>Quản lý các hội và đoàn thể</p>
        </div>
        <button class="btn-primary" onclick="openAssocForm()">+ Thêm hội</button>
      </div>

      <div class="card">
        <div class="table-wrap">
          <table class="tbl">
            <thead><tr><th>Tên hội / đoàn thể</th><th>Số thành viên</th><th>Ghi chú</th><th></th></tr></thead>
            <tbody>
              ${DB.associations.length ? DB.associations.map(a => `
                <tr>
                  <td><strong>${esc(a.name)}</strong></td>
                  <td>${DB.residentAssociations.filter(x => Number(x.association_id) === Number(a.association_id)).length}</td>
                  <td>${esc(a.note)}</td>
                  <td class="actions">
                    <button class="btn-small" onclick="showAssociationMembers(${a.association_id})">Xem danh sách</button>
                    <button class="btn-small" onclick="openAssocForm(${a.association_id})">Sửa</button>
                    <button class="btn-small danger" onclick="removeAssoc(${a.association_id})">Xóa</button>
                  </td>
                </tr>
              `).join("") : `<tr><td colspan="4" class="empty-state">Chưa có hội, đoàn thể.</td></tr>`}
            </tbody>
          </table>
        </div>
      </div>
    </section>
  `;
}

// Opens the Cư dân page filtered on one association.
function showAssociationMembers(id) {
  memPreset = "";
  memFilters = { ...emptyMemberFilters(), association: String(id), status: "" };
  memSortByAge = false;
  setTab("members");
}

function openAssocForm(id = null) {
  const a = id ? DB.associations.find(x => Number(x.association_id) === Number(id)) : null;

  showFormModal(`
    ${formHeader(a ? "Sửa hội, đoàn thể" : "Thêm hội, đoàn thể")}
    <form onsubmit="submitAssocForm(event, ${a?.association_id || 0})">
      ${formInput("Tên hội / đoàn thể", "name", a?.name || "", "text", "required")}
      ${formTextarea("Ghi chú", "note", a?.note || "")}
      ${formActions(a ? "Lưu thay đổi" : "Thêm hội")}
    </form>
  `);
}

async function submitAssocForm(e, id) {
  e.preventDefault();
  if (await saveAndRefresh("association.save", { ...formValues(e.target), association_id: id })) closeFormModal();
}

async function removeAssoc(id) {
  const a = DB.associations.find(x => Number(x.association_id) === Number(id));
  if (!a || !confirm(`Xóa "${a.name}"?`)) return;
  await saveAndRefresh("association.delete", { association_id: id });
}

/* ============================================================
 * HOẠT ĐỘNG CHUNG
 * ============================================================ */

let openActivityId = null;

/* "15/01/2026 – 31/01/2026", "Từ 15/01/2026", "Đến 31/01/2026" or "". */
function activityPeriod(a) {
  const from = formatDob(a.activity_date);
  const to = formatDob(a.end_date);
  if (from && to) return from === to ? from : `${from} – ${to}`;
  if (from) return `Từ ${from}`;
  if (to) return `Đến ${to}`;
  return "";
}

function toggleActivityOpen(id) {
  openActivityId = Number(openActivityId) === Number(id) ? null : id;
  render();
}

function renderActivities() {
  return `
    <section class="page">
      <div class="page-header">
        <div>
          <h2>Hoạt động chung</h2>
          <p>Theo dõi các khoản đóng góp của hộ gia đình</p>
        </div>
        <button class="btn-primary" onclick="openActivityForm()">+ Thêm hoạt động</button>
      </div>

      <div class="activity-list">
        ${DB.activities.length ? DB.activities.map(renderActivityCard).join("") :
          `<div class="card empty-state">Chưa có hoạt động chung.</div>`}
      </div>
    </section>
  `;
}

function renderActivityCard(a) {
  const open = Number(openActivityId) === Number(a.activity_id);
  const rows = DB.households.map(h => ({ h, c: householdActivity(h.household_id, a.activity_id) }));
  const paid = rows.filter(x => Number(x.c?.paid)).length;

  return `
    <div class="card activity-card">
      <div class="activity-header" onclick="toggleActivityOpen(${a.activity_id})">
        <div>
          <h3>${esc(a.name)}</h3>
          <p>
            ${esc(activityPeriod(a))}
            ${a.amount_suggested ? ` · Mức đề nghị: ${money(a.amount_suggested)}` : ""}
          </p>
        </div>
        <div class="activity-summary">
          <strong>${paid}/${rows.length}</strong>
          <span>${open ? "▾" : "▸"}</span>
        </div>
      </div>

      ${open ? `
        <div class="activity-body">
          <div class="table-wrap">
            <table class="tbl">
              <thead><tr><th>Hộ</th><th>Chủ hộ</th><th>Số tiền</th><th>Trạng thái</th></tr></thead>
              <tbody>
                ${rows.map(({ h, c }) => `
                  <tr>
                    <td>${esc(h.address)}</td>
                    <td>${esc(householdHeadName(h.household_id))}</td>
                    <td>
                      <input class="amount-input" type="number" min="0" value="${Number(c?.amount) || 0}"
                        onchange="saveContribution(${h.household_id}, ${a.activity_id}, { amount: this.value })">
                    </td>
                    <td>
                      <button class="status-button ${Number(c?.paid) ? "paid" : "unpaid"}"
                        onclick="saveContribution(${h.household_id}, ${a.activity_id}, { paid: ${Number(c?.paid) ? 0 : 1} })">
                        ${Number(c?.paid) ? "Đã đóng" : "Chưa đóng"}
                      </button>
                    </td>
                  </tr>
                `).join("")}
              </tbody>
            </table>
          </div>

          ${a.notes ? `<div class="activity-note">${esc(a.notes)}</div>` : ""}

          <div class="form-actions">
            <button class="btn-small" onclick="openActivityForm(${a.activity_id})">Sửa hoạt động</button>
            <button class="btn-small danger" onclick="removeActivity(${a.activity_id})">Xóa</button>
          </div>
        </div>` : ""}
    </div>
  `;
}

/* One function for both the amount field and the paid/unpaid button. */
async function saveContribution(householdId, activityId, change) {
  const current = householdActivity(householdId, activityId);

  await saveAndRefresh("contribution.save", {
    household_id: householdId,
    activity_id: activityId,
    paid: Number(current?.paid) || 0,
    amount: Number(current?.amount) || 0,
    notes: current?.notes || "",
    ...change
  });
}

function openActivityForm(id = null) {
  const a = id ? DB.activities.find(x => Number(x.activity_id) === Number(id)) : null;

  showFormModal(`
    ${formHeader(a ? "Sửa hoạt động" : "Thêm hoạt động")}
    <form onsubmit="submitActivityForm(event, ${a?.activity_id || 0})">
      ${formInput("Tên hoạt động", "name", a?.name || "", "text", "required")}
      <div class="form-grid">
        ${formInput("Từ ngày", "activity_date", a?.activity_date || "", "date")}
        ${formInput("Đến ngày", "end_date", a?.end_date || "", "date")}
      </div>
      ${formInput("Mức đóng đề nghị", "amount_suggested", a?.amount_suggested || "", "number", 'min="0"')}
      ${formTextarea("Ghi chú", "notes", a?.notes || "")}
      ${formActions(a ? "Lưu thay đổi" : "Thêm hoạt động")}
    </form>
  `);
}

async function submitActivityForm(e, id) {
  e.preventDefault();
  const values = formValues(e.target);

  if (values.activity_date && values.end_date && values.end_date < values.activity_date) {
    alert("“Đến ngày” phải sau hoặc bằng “Từ ngày”.");
    e.target.elements.end_date.focus();
    return;
  }

  if (await saveAndRefresh("activity.save", { ...values, activity_id: id })) closeFormModal();
}

async function removeActivity(id) {
  const a = DB.activities.find(x => Number(x.activity_id) === Number(id));
  if (!a || !confirm(`Xóa hoạt động "${a.name}"?`)) return;
  openActivityId = null;
  await saveAndRefresh("activity.delete", { activity_id: id });
}