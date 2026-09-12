#!/usr/bin/env python3
"""
Rekonstruksi baris PDF wholesales GAIKINDO — v4 header-anchored.

Menggantikan v3 grid-line (yang mati total: bug cluster_lines membuat tiap
karakter jadi baris sendiri, dan deteksi token bulan gagal karena header
terjalin di templat tahunan lama). Fakta geometri hasil bedah templat 2026
(GAIKINDO_2026_JAN_AUG = templat baru, GAIKINDO_2026_JAN_JUL = templat lama):

- Teks mikro (font 1,3-1,8pt), tiap glyph objek teks terpisah, dan dicetak
  dua lapis: pasangan ghost identik, offset (dy 0,02-0,03; x sama persis).
- Baris cetak pitch >= 2,0pt; templat lama menambah sub-baris lanjutan
  (+0,48pt) berisi speks lanjutan (ban, wheelbase) tanpa brand/model.
- Baris header segmen memuat token bulan JAN..DEC UTUH (kedua templat 2026);
  pusat token berpitch seragam (15,3pt baru / 20,7pt lama) sehingga batas
  interval kolom bulan = midpoint antar pusat token.
- Kolom kiri (CATEGORY, BRAND, MODEL/TYPE, CC, TRANS, FUEL, ...) di-anchor
  token header-nya (termasuk header bertumpuk 2-3 baris); batas kolom =
  tick vertikal berfrekuensi tinggi terdekat, fallback midpoint antar anchor.
- Nilai TOTAL = sel numerik terkanan setelah DEC; sel ber-% = share (dibuang).
- Rekap resmi: "DOMESTIC SALES TOTAL", "PASSENGER CAR SALES TOTAL",
  "COMMERCIAL VEHICLE SALES TOTAL" (varian CUMULATIVE ditandai off).

Output JSON ke stdout (SKEMA v4 — beda dari v3; konsumen: gaikindo_pdf_convert.py):
  {"file": "...", "pages": [
      {"index": 1, "sections": [
          {"months": [{"m": 1, "lo": 440.9, "hi": 456.3}, ...],
           "columns": [{"name": "BRAND", "lo": 215.0, "hi": 236.0}, ...],
           "rows": [
              {"y": 24.3, "type": "model",          # model|official|subtotal|junk
               "official": null,                    # grand|passenger|commercial
               "brand": "BMW", "model": "X3 sDrive20i G01 CKD",
               "cols": {"CATEGORY": "4X2 CC 1.501 - 2.500", ...},
               "months": {"1": 1, "2": null, ...},  # null = kosong/dash
               "total": 1, "share": ["0%", "0%"]}
          ]}
      ]}
  ]}
"""

import json
import re
import sys
from collections import defaultdict

import pdfplumber

ROW_TOL = 0.3          # < pitch sub-baris lanjutan (0,48) & baris utama (>=2,0);
                       # > offset pasangan cetak label+nilai rekap (0,24)
GHOST_DX = 0.1         # ghost cetak dua lapis: x identik (0-0,03), teks sama
SUBROW_GAP = 1.0       # jarak y maksimum sub-baris lanjutan dari baris induk
TOKEN_GAP = 1.0        # gap maksimum antar glyph untuk jadi satu token
ANCHOR_MERGE_DX = 4.0  # dua header token < 4pt = satu kolom (header bertumpuk)
HEADER_RADIUS = 4.0    # baris header bertumpuk maksimal sejauh ini dari anchor
GRID_SPLIT_GAP = 1.5   # header grid-sama < 1,5pt = kelanjutan cetak; >= itu = segmen baru
PREHEADER_GAP = 1.5    # baris header tercetak di ATAS anchor maks 1,5pt (di-replay)
TICK_MIN_RATIO = 0.3   # tick vertikal dipakai bila count >= 30% tick terpadat
TICK_MERGE_DX = 1.5    # tick berjarak < 1,5pt dilebur
GRID_TOL = 2.0         # toleransi kesamaan grid bulan antar baris header

MONTHS = ["JAN", "FEB", "MAR", "APR", "MAY", "JUN",
          "JUL", "AUG", "SEP", "OCT", "NOV", "DEC"]

RE_INT = re.compile(r"^\d{1,3}(?:[.,]\d{3})+$|^\d+$")
RE_PCT = re.compile(r"%")
RE_NAME_TOKEN = re.compile(r"^[A-Za-z][A-Za-z./&() ]{0,24}$")  # kandidat nama kolom

OFFICIAL_PATTERNS = [
    (re.compile(r"DOMESTIC\s*SALES\s*TOTAL", re.I), "grand"),
    (re.compile(r"PASSENGER\s*CAR\s*SALES\s*TOTAL", re.I), "passenger"),
    (re.compile(r"COMMERCIAL\s*VEHICLE\s*SALES\s*TOTAL", re.I), "commercial"),
]
RE_CUMULATIVE = re.compile(r"CUMULATIVE", re.I)
# Label di-join dengan nilai sel, jadi TANPA anchor $. Kata TOTAL/CUMULATIVE
# sebagai kata utuh + divider CC + subjudul "SALES (" — model tak mungkin bernama
# demikian, sedangkan baris rekap/section selalu memuatnya.
RE_SUBTOTAL = re.compile(
    r"\b(?:TOTAL|CUMULATIVE)\b|\bSALES\s*\(|^CC\b|"
    r"^(?:BRAND|TYPE|MODEL|CATEGORY)$", re.I)


# ----------------------------------------------------------------- klasterisasi

def cluster_rows(chars):
    """Char -> baris cetak (tol ROW_TOL); buang ghost duplikat cetak dua lapis.

    Ghost layer muncul SETELAH semua char layer utama (urut top), sehingga
    dedup dilakukan sebagai pass kedua per baris: pasangan tetangga-x dengan
    teks sama dan dx < GHOST_DX adalah kembaran cetak, bukan huruf kembar
    (advance huruf sah >= 0,3pt)."""
    rows = []
    last_top = None
    for c in sorted(chars, key=lambda c: (c["top"], c["x0"])):
        if last_top is None or c["top"] - last_top > ROW_TOL:
            rows.append({"top": c["top"], "chars": [c]})
        else:
            rows[-1]["chars"].append(c)
        last_top = c["top"]
    for row in rows:
        kept = []
        for c in sorted(row["chars"], key=lambda c: c["x0"]):
            if kept and c["text"] == kept[-1]["text"] \
                    and c["x0"] - kept[-1]["x0"] < GHOST_DX:
                continue
            kept.append(c)
        row["chars"] = kept
    return rows


def row_tokens(row, xmin=None, gap=TOKEN_GAP):
    """Char baris -> token {x,x1,text}; opsional hanya yang x0 >= xmin."""
    out = []
    for c in sorted(row["chars"], key=lambda c: c["x0"]):
        if xmin is not None and c["x0"] < xmin:
            continue
        if out and c["x0"] - out[-1]["x1"] <= gap:
            out[-1]["text"] += c["text"]
            out[-1]["x1"] = max(out[-1]["x1"], c["x1"])
        else:
            out.append({"x": c["x0"], "x1": c["x1"], "text": c["text"]})
    return out


def merge_duplicate_ticks(pairs):
    """Lebur tick berdekatan (< TICK_MERGE_DX), count maksimum yang dipertahankan."""
    merged = []
    for x, n in sorted(pairs):
        if merged and x - merged[-1][0] < TICK_MERGE_DX:
            px, pn = merged[-1]
            merged[-1] = (px if pn >= n else x, max(pn, n))
        else:
            merged.append((x, n))
    return merged


def vertical_ticks(page):
    """Posisi tick vertikal frekuensi-tinggi (batas kolom nyata templat)."""
    counts = defaultdict(int)
    for e in page.edges:
        if e["orientation"] == "v":
            counts[round(e["x0"])] += 1
    if not counts:
        return []
    threshold = TICK_MIN_RATIO * max(counts.values())
    return [x for x, _ in merge_duplicate_ticks(
        [(x, n) for x, n in counts.items() if n >= threshold])]


# -------------------------------------------------------------------- layout

def build_columns(anchors, ticks, right_edge=float("inf")):
    """Anchor (name, x0, x1) -> kolom [lo, hi). Batas = tick terdekat ke midpoint
    antar anchor (fallback midpoint); tepi kiri halaman untuk anchor pertama,
    dan kolom terakhir dibatasi right_edge (month_lo) agar tak menelan tail."""
    if not anchors:
        return []
    bounds = [0.0]
    for i in range(1, len(anchors)):
        mid = (anchors[i - 1][1] + anchors[i][1]) / 2.0
        near = [t for t in ticks if abs(t - mid) <= 4.0]
        bounds.append(min(near, key=lambda t: abs(t - mid)) if near else mid)
    bounds.append(right_edge)
    return [{"name": name, "lo": bounds[i], "hi": bounds[i + 1]}
            for i, (name, _, _) in enumerate(anchors)]


class Section(object):
    """Satu segmen tabel: grid bulan + kolom kiri + baris-barisnya."""

    def __init__(self, month_cells, anchor_tokens, ticks):
        self.ticks = ticks
        self.month_cells = month_cells
        self.month_lo = min(lo for lo, _ in month_cells.values())
        self.month_hi = max(hi for _, hi in month_cells.values())
        self.anchors = []          # [(name, x0, x1)]
        self.rows = []
        self.last_header_y = None  # y header terakhir (radius absorbsi & split)
        for tk in anchor_tokens:
            text = tk["text"].strip()
            if text and tk["x1"] < self.month_lo and RE_NAME_TOKEN.match(text):
                self.anchors.append((text, tk["x"], tk["x1"]))
        self.anchors.sort(key=lambda a: a[1])
        self.columns = build_columns(self.anchors, ticks, self.month_lo)

    def same_grid(self, cells):
        common = set(cells) & set(self.month_cells)
        if not common:
            return False
        return all(abs((cells[m][0] + cells[m][1]) / 2
                       - (self.month_cells[m][0] + self.month_cells[m][1]) / 2) <= GRID_TOL
                   for m in common)

    def merge_header_row(self, tokens, y):
        """Header bertumpuk (TANK/CAPT (KG)/Share/...) -> anchor & nama kolom tambahan."""
        for tk in tokens:
            text = tk["text"].strip()
            if not text or tk["x1"] >= self.month_lo or not RE_NAME_TOKEN.match(text):
                continue
            near = [a for a in self.anchors if abs(a[1] - tk["x"]) < ANCHOR_MERGE_DX]
            if near:
                idx = self.anchors.index(near[0])
                old = self.anchors[idx][0]
                if text.lower() not in old.lower():
                    self.anchors[idx] = (old.rstrip("/") + " " + text,
                                         self.anchors[idx][1], self.anchors[idx][2])
            elif not any(a[0].upper() == text.upper() for a in self.anchors):
                # nama sudah ada di x lain (mis. "BRAND" dalam frasa "BY BRAND
                # SHARE") -> jangan bikin kolom kembar; bukan anchor baru.
                self.anchors.append((text, tk["x"], tk["x1"]))
        self.anchors.sort(key=lambda a: a[1])
        self.columns = build_columns(self.anchors, self.ticks, self.month_lo)
        self.last_header_y = y

    # -- pemakaian -----------------------------------------------------------

    def col_text(self, row, name):
        for col in self.columns:
            if col["name"] == name:
                return cell_text(row, col["lo"], col["hi"])
        return ""

    def identity(self, row):
        """Teks brand+model — pembeda baris model vs sub-baris spek."""
        parts = []
        for col in self.columns:
            if col["name"].upper() in ("BRAND", "MODEL/TYPE", "TYPE MODEL", "MODEL TYPE"):
                parts.append(cell_text(row, col["lo"], col["hi"]))
        return " ".join(p for p in parts if p)


# --------------------------------------------------------------- parse sel

def cell_text(row, lo, hi):
    """Concat char baris pada [lo, hi) — nilai kerning ('1 .998') menyatu."""
    parts = [c["text"] for c in row["chars"] if lo <= c["x0"] < hi]
    return re.sub(r"\s+", " ", "".join(parts)).strip()


def parse_units(text):
    """Teks sel angka bulatan -> int; None bila kosong/dash/kotor/desimal."""
    t = re.sub(r"\s+", "", text)
    if t == "" or set(t) <= {"-", "–"}:
        return None
    if t.endswith("%"):
        return None
    if RE_INT.match(t):
        return int(re.sub(r"[.,]", "", t))
    return None


def month_cells_from(tokens):
    """Token bulan satu baris header -> {m: (lo, hi)} via midpoint antar pusat.
    None bila bukan deret bulan sah (>=3, mulai JAN, berurutan)."""
    found = {}
    for tk in tokens:
        key = tk["text"].strip().upper()
        if key in MONTHS and len(tk["text"]) <= 9:
            found.setdefault(MONTHS.index(key) + 1, (tk["x"] + tk["x1"]) / 2.0)
    ms = sorted(found)
    if len(ms) < 3 or ms[0] != 1 or ms != list(range(ms[0], ms[0] + len(ms))):
        return None
    cells = {}
    for i, m in enumerate(ms):
        c = found[m]
        if i == 0:
            lo = c - (found[ms[1]] - c) / 2.0
        else:
            lo = (found[ms[i - 1]] + c) / 2.0
        if i + 1 < len(ms):
            hi = (c + found[ms[i + 1]]) / 2.0
        else:
            hi = c + (c - found[ms[i - 1]]) / 2.0
        cells[m] = (lo, hi)
    return cells


def classify_row(row, section):
    """Klasifikasi baris cetak -> dict hasil (model|official|subtotal|junk)."""
    cols = {col["name"]: cell_text(row, col["lo"], col["hi"])
            for col in section.columns if col["name"]}
    months = {m: parse_units(cell_text(row, lo, hi))
              for m, (lo, hi) in sorted(section.month_cells.items())}

    tail = row_tokens(row, xmin=section.month_hi)
    share = [t["text"].strip() for t in tail if RE_PCT.search(t["text"])]
    numeric_tail = [t["text"] for t in tail
                    if not RE_PCT.search(t["text"]) and parse_units(t["text"]) is not None]
    total = parse_units(numeric_tail[-1]) if numeric_tail else None

    brand = cols.get("BRAND", "")
    model = cols.get("MODEL/TYPE") or cols.get("TYPE MODEL") or ""
    label = " ".join(v for v in cols.values() if v)

    # Label bisa terfragmentasi antar kolom ("PASS"+"ENGER CAR SALES TOTAL"),
    # maka pencarian rekap resmi & kata kunci subtotal memakai label tanpa spasi.
    flat = re.sub(r"\s+", "", label).upper()
    row_type, official = "model", None
    for pattern, kind in OFFICIAL_PATTERNS:
        if pattern.search(flat):
            official = kind
            row_type = "subtotal" if RE_CUMULATIVE.search(flat) else "official"
            break
    else:
        # Kata kunci diuji pada label flat; subjudul "SALES (" dan divider
        # "CC ..." tertangkap lewat pola berspasi pada teks asli.
        if (RE_SUBTOTAL.search(brand) or RE_SUBTOTAL.search(model)
                or "TOTAL" in flat or "CUMULATIVE" in flat
                or RE_SUBTOTAL.search(label)):
            row_type = "subtotal"

    if row_type == "model" and not model:
        # baris model selalu punya teks MODEL/TYPE. Tanpa itu tapi berisi nilai
        # bulanan = baris nilai TOTAL/CUMULATIVE (labelnya tercetak di baris
        # terpisah) — jangan pernah dihitung sebagai model.
        row_type = "subtotal" if any(v is not None for v in months.values()) \
            or total is not None else "junk"

    return {"y": round(row["top"], 1), "type": row_type, "official": official,
            "brand": brand, "model": model, "cols": cols,
            "months": months, "total": total, "share": share}


# ----------------------------------------------------------------- ekstraksi

def header_like(section, row, tokens):
    """Baris tampak seperti header bertumpuk: zona bulan kosong + token kiri
    didominasi nama kolom. Baris data (brand/model/nilai speks) selalu
    bercampur angka/dash sehingga rasio nama rendah — ditolak agar anchor
    tidak ikut menelan nilai ("BRAND"+"BMW" -> "BRAND BMW")."""
    left_tokens = [t for t in tokens if t["x1"] < section.month_lo]
    if not left_tokens:
        return False
    month_zone_empty = all(
        parse_units(cell_text(row, lo, hi)) is None
        for lo, hi in section.month_cells.values())
    if not month_zone_empty:
        return False
    content = [t for t in left_tokens if t["text"].strip() not in ("", "-")]
    named = [t for t in content if RE_NAME_TOKEN.match(t["text"].strip())]
    if not named or not content:
        return False
    ratio = len(named) / len(content)
    return ratio >= 0.6 and (len(named) >= 2 or len(content) == 1)


def extract(path):
    """PDF -> dict hasil (lihat docstring modul untuk skema)."""
    out = {"file": path, "pages": []}
    with pdfplumber.open(path) as pdf:
        for index, page in enumerate(pdf.pages, start=1):
            rows = cluster_rows(page.chars)
            ticks = vertical_ticks(page)

            sections = []
            section = None
            recent = []  # (top, row, tokens) baris terakhir — untuk replay pra-header
            for row in rows:
                tokens = row_tokens(row)
                recent.append((row["top"], row, tokens))
                recent = recent[-8:]
                cells = month_cells_from(tokens)
                if cells is not None:
                    continuation = (
                        section is not None and section.same_grid(cells)
                        and section.last_header_y is not None
                        and row["top"] - section.last_header_y <= GRID_SPLIT_GAP)
                    if continuation:
                        section.merge_header_row(tokens, row["top"])  # header terpotong cetak
                        continue
                    section = Section(cells, tokens, ticks)
                    section.last_header_y = row["top"]
                    # baris header yang tercetak di ATAS anchor (CATEGORY, TANK,
                    # WHEEL & TYRE, ...) — replay sebagai anchor kolom tambahan.
                    for rtop, rrow, rtokens in reversed(recent[:-1]):
                        if not (row["top"] - PREHEADER_GAP <= rtop < row["top"]):
                            break
                        if header_like(section, rrow, rtokens):
                            section.merge_header_row(rtokens, rtop)
                    sections.append(section)
                    recent = []
                    continue

                if section is None:
                    continue  # judul halaman / baris pra-header

                # 1) sub-baris lanjutan: tanpa identitas ATAU tanpa teks model
                #    (fragmen brand+spek), menempel baris model di atasnya.
                if (section.rows and section.rows[-1]["type"] == "model"
                        and row["top"] - section.rows[-1]["y"] <= SUBROW_GAP):
                    model_text = ""
                    for col in section.columns:
                        if col["name"].upper() in ("MODEL/TYPE", "TYPE MODEL",
                                                   "MODEL TYPE"):
                            t = cell_text(row, col["lo"], col["hi"])
                            if t:
                                model_text = t
                                break
                    has_months = any(
                        parse_units(cell_text(row, lo, hi)) is not None
                        for lo, hi in section.month_cells.values())
                    if not has_months and (not section.identity(row)
                                           or not model_text):
                        section.rows[-1].setdefault("_extra", []).extend(row["chars"])
                        continue

                # 2) header bertumpuk di dekat anchor: tambah anchor kolom.
                #    Baris header boleh punya angka di TAIL (mis. tahun "2 .026"
                #    / "TOTAL") — yang wajib kosong hanyalah INTERVAL BULAN.
                if (section.last_header_y is not None
                        and row["top"] - section.last_header_y <= HEADER_RADIUS
                        and header_like(section, row, tokens)):
                    section.merge_header_row(tokens, row["top"])
                    continue

                # 3) baris data / rekap / subtotal / divider.
                section.rows.append(classify_row(row, section))
                section.last_header_y = None  # data sudah mulai; stop absorbsi

            pages_out = []
            for sec in sections:
                rows_out = []
                for r in sec.rows:
                    extra = r.pop("_extra", None)
                    if extra:
                        merged = {"chars": extra}
                        for col in sec.columns:
                            val = cell_text(merged, col["lo"], col["hi"])
                            if val and not r["cols"].get(col["name"]):
                                r["cols"][col["name"]] = val
                        # brand/model terisi saat klasifikasi (sebelum merge) —
                        # perbarui bila fragmen lanjutan membawanya.
                        if not r["brand"]:
                            r["brand"] = (r["cols"].get("BRAND") or "").strip()
                        if not r["model"]:
                            r["model"] = (r["cols"].get("MODEL/TYPE")
                                          or r["cols"].get("TYPE MODEL") or "").strip()
                    rows_out.append(r)
                pages_out.append({
                    "months": [{"m": m, "lo": lo, "hi": hi}
                               for m, (lo, hi) in sorted(sec.month_cells.items())],
                    "columns": sec.columns,
                    "rows": rows_out,
                })
            out["pages"].append({"index": index, "sections": pages_out})
    return out


def main():
    if len(sys.argv) != 2:
        print("usage: gaikindo_pdf_rows.py <file.pdf>", file=sys.stderr)
        sys.exit(2)
    json.dump(extract(sys.argv[1]), sys.stdout, ensure_ascii=False)


if __name__ == "__main__":
    main()
