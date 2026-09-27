"use strict";

/* ============================================================
 * IMPORT DỮ LIỆU
 * Uploads the standard workbook; the server replaces households
 * and residents in one transaction (old data kept if anything fails).
 * ============================================================ */

let importMessage = "";

function renderImport() {
  return `
    <section class="page">
      <div class="page-header">
        <div>
          <h2>Import dữ liệu</h2>
          <p>Nhập dữ liệu dân cư từ file Excel</p>
        </div>
      </div>

      <div class="card import-card">
        <h3>Chọn file Excel</h3>

        <div class="import-notice">
          <strong>Dùng file dân cư chuẩn, có hai sheet "Hộ gia đình" và "Cư dân".</strong>
          <p>Cột được nhận theo tên tiêu đề, nên có thể đổi thứ tự cột. Bắt buộc có: Mã hộ, Địa chỉ (sheet Hộ gia đình); Mã hộ, Họ và tên (sheet Cư dân).</p>
          <p>Nhập file sẽ <strong>thay thế toàn bộ</strong> ${DB.households.length} hộ và ${DB.residents.length} người hiện có,
            đồng thời xóa danh sách thành viên hội và các khoản đóng góp đã ghi (tên hội và hoạt động được giữ).
            Nếu file có lỗi, dữ liệu cũ được giữ nguyên.</p>
        </div>

        <div class="import-actions">
          <input type="file" id="importFile" accept=".xlsx">
          <button class="btn-primary" id="importBtn" onclick="runImport()">Nhập dữ liệu</button>
        </div>

        <div id="importMessage">${importMessage}</div>
      </div>
    </section>
  `;
}

function showImportMessage(type, text) {
  importMessage = `<div class="${type === "error" ? "form-error" : "form-success"}">${esc(text)}</div>`;
  const box = document.getElementById("importMessage");
  if (box) box.innerHTML = importMessage;
}

async function runImport() {
  const file = document.getElementById("importFile")?.files[0];

  if (!file) {
    showImportMessage("error", "Hãy chọn file .xlsx trước.");
    return;
  }

  if (!confirm(`Thay thế toàn bộ dữ liệu dân cư bằng "${file.name}"?`)) return;

  const form = new FormData();
  form.append("file", file);

  const button = document.getElementById("importBtn");
  button.disabled = true;
  setSaving();
  showImportMessage("success", "Đang nhập dữ liệu...");

  try {
    const res = await call("import", form);
    await refreshData();
    setSaved();

    showImportMessage("success",
      `Đã nhập ${res.households} hộ và ${res.residents} người` +
      (res.new_codes ? ` (${res.new_codes} người chưa có mã được cấp mã mới)` : "") + ".");
    render();
  } catch (error) {
    setSaved();
    showImportMessage("error", error.message);
    button.disabled = false;
  }
}
