# DOMIVA – Sổ Quản Lý Dân Cư (refactored, same design)

Same page, same look (index.php and css/style.css are your originals; a small block
for the new filter area is appended at the end of style.css).

    index.php           unchanged
    css/style.css       unchanged + "CƯ DÂN – DANH SÁCH NHANH & BỘ LỌC" block at the end
    js/common.js        helpers: server calls, formatting, age, modal and form building blocks
    js/modules.js       pages: Tổng quan, Hộ gia đình, Cư dân, Hội đoàn thể, Hoạt động chung
    js/import.js        Import page
    js/script.js        tabs, state, loading
    api/api.php         the only endpoint (see the list of actions at the top of the file)
    api/db.php          database connection, tables (created automatically), helpers
    api/xlsx-reader.php Excel reader (your import-reader.php, unchanged)
    data/               dancu.db is created here automatically

Requirements: PHP 8.1+ with pdo_sqlite, zip and simplexml.

## Install

1. Back up your project, then copy these files over it.
2. Delete the old API files: api/common.php, households.php, persons.php, activities.php,
   associations.php, household-activities.php, import.php, import-parser.php,
   import-reader.php, auth.php.
3. Open the site > Import dữ liệu > import Dan_cu_to_23_chuan.xlsx.

The old database (data/domiva.db) is not touched. Associations and activities
from it are not carried over; re-create them in the app (a handful of rows).

## Adding a quick list

Add one line to PRESETS in js/modules.js, e.g.

    { id: "thanhnien", label: "Thanh niên (16–30)", f: { ageMin: 16, ageMax: 30 } },

Filter keys: q, address, gender ("Nam" | "Nữ" | "?"), ageMin, ageMax, status, voter ("1" | "0"),
month (1–12), relation, association (id), missing ("gender" | "dob" | "birth_year" | "cccd" | "phone"),
check (true), longevity (true).
