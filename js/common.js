"use strict";

/* ============================================================
 * Shared constants and helpers
 * ============================================================ */

const API_URL = "api/api.php";
let CSRF_TOKEN = "";

class ApiError extends Error {
  constructor(message, status) {
    super(message);
    this.name = "ApiError";
    this.status = status;
  }
}

const RELATION_OPTIONS = ["Chủ hộ", "Vợ / Chồng", "Con", "Cha / Mẹ", "Ông / Bà", "Cháu", "Khác"];
const STATUS_OPTIONS = ["Đang ở", "Tạm vắng", "Đã chuyển đi", "Đã mất"];

const AGE_GROUPS = ["Trẻ em", "Học sinh / Sinh viên", "Đang đi làm", "Nghỉ hưu", "Thiếu thông tin"];

const ageGroupColors = {
  "Trẻ em": "age-child",
  "Học sinh / Sinh viên": "age-student",
  "Đang đi làm": "age-working",
  "Nghỉ hưu": "age-retired",
  "Thiếu thông tin": "age-unknown"
};

/* ---------- Server ---------- */

/*
 * call("data")                      -> GET
 * call("resident.save", {...})      -> POST JSON
 * call("import", formData)          -> POST multipart
 */
async function requestJson(url, options = {}) {
  const response = await fetch(url, { credentials: "same-origin", ...options });
  const raw = await response.text();
  let data;

  try {
    data = JSON.parse(raw);
  } catch {
    throw new ApiError(`Máy chủ không trả về JSON (HTTP ${response.status}).`, response.status);
  }

  if (!response.ok || !data.ok) {
    const error = new ApiError(data.error || `HTTP ${response.status}`, response.status);
    if (response.status === 401) {
      document.dispatchEvent(new CustomEvent("sql-dc:unauthorized"));
    }
    throw error;
  }

  return data;
}

async function call(action, payload) {
  const isForm = payload instanceof FormData;
  const opts = { method: payload === undefined ? "GET" : "POST", headers: {} };

  if (payload !== undefined) {
    if (!CSRF_TOKEN) {
      throw new ApiError("Phiên bảo mật chưa sẵn sàng. Hãy tải lại trang.", 401);
    }
    opts.headers["X-CSRF-Token"] = CSRF_TOKEN;
    if (isForm) {
      opts.body = payload;
    } else {
      opts.headers["Content-Type"] = "application/json";
      opts.body = JSON.stringify(payload);
    }
  }

  return requestJson(`${API_URL}?action=${encodeURIComponent(action)}`, opts);
}

async function logoutRequest() {
  if (!CSRF_TOKEN) throw new ApiError("Phiên bảo mật đã hết hạn.", 401);
  return requestJson("api/google-logout.php", {
    method: "POST",
    headers: { "X-CSRF-Token": CSRF_TOKEN }
  });
}

/*
 * Runs a save/delete, shows the save indicator, reloads the data and redraws.
 * Returns true on success; shows the error and returns false otherwise.
 */
async function saveAndRefresh(action, payload) {
  setSaving();

  try {
    await call(action, payload);
    await refreshData();
    setSaved();
    render();
    return true;
  } catch (error) {
    setSaved();
    if (error.status !== 401) alert(error.message);
    return false;
  }
}

/* Save indicator in the sidebar; its text comes from the CSS ("Đang lưu…", "✓ Đã lưu"). */
function setSaveState(state) {
  const el = document.getElementById("save-indicator");
  if (el) el.className = `sidebar-foot save-indicator ${state}`;
}

function setSaving() { setSaveState("saving"); }
function setSaved()  { setSaveState("saved"); }

/* ---------- Text & numbers ---------- */

function esc(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

// Lower case, no accents: "Nguyễn Đức" -> "nguyen duc", so searches work without accents.
function plain(value) {
  return String(value ?? "").normalize("NFD").replace(/[\u0300-\u036f]/g, "")
    .replace(/đ/g, "d").replace(/Đ/g, "D").toLowerCase();
}

function money(value) {
  return (Number(value) || 0).toLocaleString("vi-VN");
}

// "1992-08-12" -> "12/08/1992"
function formatDob(dob) {
  if (!dob) return "";
  const m = String(dob).match(/^(\d{4})-(\d{2})-(\d{2})$/);
  return m ? `${m[3]}/${m[2]}/${m[1]}` : String(dob);
}

/* ---------- Age (counted by year, as the tổ does) ---------- */

const AGE_YEAR = new Date().getFullYear();

function ageOf(r) {
  return r?.birth_year ? AGE_YEAR - Number(r.birth_year) : null;
}

function ageGroupOf(r) {
  const age = ageOf(r);
  if (age === null) return "Thiếu thông tin";
  if (age < 6) return "Trẻ em";
  if (age < 23) return "Học sinh / Sinh viên";
  if (age < 60) return "Đang đi làm";
  return "Nghỉ hưu";
}

// Full date if known, otherwise the year.
function dobText(r) {
  return r.dob ? formatDob(r.dob) : (r.birth_year ? String(r.birth_year) : "");
}

/* ---------- Lookups ---------- */

function isLiving(r) {
  return r.status === "Đang ở";
}

function membersOf(hhId) {
  return DB.residents.filter(r => Number(r.household_id) === Number(hhId));
}

function householdById(hhId) {
  return DB.households.find(h => Number(h.household_id) === Number(hhId)) || null;
}

function householdAddress(hhId) {
  return householdById(hhId)?.address || "";
}

// The person marked "Chủ hộ", or the first member if nobody is.
function householdHead(hhId) {
  const members = membersOf(hhId);
  return members.find(r => r.relation === "Chủ hộ") || members[0] || null;
}

function householdHeadName(hhId) {
  return householdHead(hhId)?.full_name || "";
}

function householdPhone(hhId) {
  return householdHead(hhId)?.phone || "";
}

function associationIdsOf(residentId) {
  return DB.residentAssociations
    .filter(x => Number(x.resident_id) === Number(residentId))
    .map(x => Number(x.association_id));
}

function residentHasAssociation(residentId, associationId) {
  return associationIdsOf(residentId).includes(Number(associationId));
}

function householdActivity(householdId, activityId) {
  return DB.householdActivities.find(x =>
    Number(x.household_id) === Number(householdId) &&
    Number(x.activity_id) === Number(activityId)
  );
}

/* ---------- Modal & form building blocks ---------- */

function showFormModal(content) {
  closeFormModal();

  const modal = document.createElement("div");
  modal.className = "form-modal";
  modal.innerHTML = `
    <div class="form-modal-backdrop" onclick="closeFormModal()"></div>
    <div class="form-modal-box">${content}</div>
  `;

  document.body.appendChild(modal);
  document.body.classList.add("modal-open");

  // Start typing in the first text field (not a checkbox such as "Là cử tri").
  modal.querySelector("input:not([type=checkbox]):not([type=hidden]), select, textarea")?.focus();
}

function closeFormModal() {
  document.querySelectorAll(".form-modal").forEach(el => el.remove());
  document.body.classList.remove("modal-open");
}

function handleModalEscape(e) {
  if (e.key === "Escape") closeFormModal();
}

function formHeader(title, subtitle = "") {
  return `
    <div class="form-header">
      <div>
        <h3>${esc(title)}</h3>
        ${subtitle ? `<p>${esc(subtitle)}</p>` : ""}
      </div>
      <button type="button" class="btn-icon" title="Đóng" aria-label="Đóng" onclick="closeFormModal()">×</button>
    </div>
  `;
}

function formInput(label, name, value = "", type = "text", extra = "") {
  return `
    <label class="form-field">
      <span>${esc(label)}</span>
      <input type="${type}" name="${esc(name)}" value="${esc(value)}" ${extra}>
    </label>
  `;
}

function formTextarea(label, name, value = "", extra = "") {
  return `
    <label class="form-field">
      <span>${esc(label)}</span>
      <textarea name="${esc(name)}" ${extra}>${esc(value)}</textarea>
    </label>
  `;
}

/* options: ["A", "B"] or [["value", "label"], ...] */
function formSelect(label, name, value, options, extra = "") {
  return `
    <label class="form-field">
      <span>${esc(label)}</span>
      <select name="${esc(name)}" ${extra}>${selectOptions(options, value)}</select>
    </label>
  `;
}

function selectOptions(options, value) {
  return options.map(o => {
    const [v, l] = Array.isArray(o) ? o : [o, o];
    return `<option value="${esc(v)}" ${String(v) === String(value ?? "") ? "selected" : ""}>${esc(l)}</option>`;
  }).join("");
}

function formActions(submitLabel) {
  return `
    <div class="form-actions">
      <button type="button" class="btn-secondary" onclick="closeFormModal()">Hủy</button>
      <button type="submit" class="btn-primary">${esc(submitLabel)}</button>
    </div>
  `;
}

// FormData -> plain object, with checkboxes as true/false.
function formValues(form) {
  const data = Object.fromEntries(new FormData(form));
  form.querySelectorAll("input[type=checkbox][name]").forEach(el => { data[el.name] = el.checked; });
  return data;
}