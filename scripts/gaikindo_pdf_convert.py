#!/usr/bin/env python3
"""
Konverter PDF wholesales GAIKINDO -> CSV siap import (alur vehicle-sales:preview
+ importer CSV backend). Mesin ekstraksi: gaikindo_pdf_rows.py (v4
header-anchored) di direktori yang sama.

Pemakaian:
  python3 gaikindo_pdf_convert.py <file.pdf> [-o out.csv] [--year=YYYY]
                                  [--inspect] [--verify-only]

Tanpa -o, CSV ditulis di samping PDF dengan nama sama (.csv). --inspect hanya
menampilkan struktur hasil ekstraksi; --verify-only menjalankan seluruh
verifikasi tanpa menulis CSV.

Verifikasi TOTAL <-> JAN..DES berjalan dua arah:
  - per baris: TOTAL dari PDF dicek terhadap Σ JAN..DES (sebaliknya, TOTAL
    kosong diisi dari Σ bulan);
  - per kolom: Σ model per bulan dicek terhadap rekap resmi PDF
    (DOMESTIC/PASSENGER/COMMERCIAL SALES TOTAL).
CSV tetap ditulis bila hanya ada warning; gagal keras (rekap tak terbaca /
parse > 110% rekap) -> exit 1 tanpa CSV — hasil parse tidak pernah lolos
diam-diam.

Kolom CSV: BRAND, TYPE MODEL, CC, TRANS, FUEL, + semua kolom speksifikasi kiri
yang terbaca dari PDF (union antar segmen; FUEL memang boleh kosong sesuai
cetakan), JAN..DEC (bulan depan "-"), TOTAL (= YTD; fallback Σ bulan).
"""

import argparse
import csv
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import gaikindo_pdf_rows as engine  # noqa: E402

MONTH_COLS = ["JAN", "FEB", "MAR", "APR", "MAY", "JUN",
              "JUL", "AUG", "SEP", "OCT", "NOV", "DEC"]

FIXED_COLS = ["BRAND", "TYPE MODEL", "CC", "TRANS", "FUEL"]

COL_ALIASES = {"MODEL/TYPE": "TYPE MODEL", "MODEL TYPE": "TYPE MODEL"}


def norm_col(name):
    name = re.sub(r"\s+", " ", name).strip()
    if not name:
        return name
    return COL_ALIASES.get(name.upper(), name)


def gather_models(data):
    """Baris model semua halaman/segmen + brand carry-forward per segmen."""
    rows = []
    for page in data["pages"]:
        for section in page["sections"]:
            carry = ""
            for r in section["rows"]:
                if r["type"] != "model":
                    continue
                brand = (r["brand"] or "").strip()
                # glyph liar cetakan menempel di depan brand ("0BMW", "nHINO")
                brand = re.sub(r"^[^A-Z]+", "", brand)
                if brand:
                    carry = brand
                cols = {norm_col(k): (v or "").strip()
                        for k, v in r["cols"].items()}
                raw_months = r["months"]
                months = {m: raw_months.get(m, raw_months.get(str(m)))
                          for m in range(1, 13)}
                rows.append({"page": page["index"], "y": r["y"],
                             "brand": carry, "model": (r["model"] or "").strip(),
                             "cols": cols, "months": months, "total": r["total"]})
    return rows


def gather_officials(data):
    """Rekap resmi per jenis — pilih baris official yang BERISI NILAI."""
    out = {}
    for page in data["pages"]:
        for section in page["sections"]:
            for r in section["rows"]:
                if r["type"] != "official" or not r["official"]:
                    continue
                has_values = (r["total"] is not None
                              or any(v is not None for v in r["months"].values()))
                cur = out.get(r["official"])
                cur_has = bool(cur) and (cur["total"] is not None
                                         or any(v is not None
                                                for v in cur["months"].values()))
                if has_values and not cur_has:
                    out[r["official"]] = r
    return out


def build_column_order(data):
    """Kolom CSV = FIXED_COLS + kolom speks lain (urut median x-nya)."""
    xs = {}
    for page in data["pages"]:
        for section in page["sections"]:
            for col in section["columns"]:
                name = norm_col(col["name"])
                if not name or name in FIXED_COLS or name in MONTH_COLS \
                        or name.upper() in ("TOTAL", "SHARE"):
                    continue
                xs.setdefault(name, []).append(col["lo"])
    extra = sorted(xs, key=lambda n: sorted(xs[n])[len(xs[n]) // 2])
    return FIXED_COLS + extra


def main():
    ap = argparse.ArgumentParser(description="Konverter PDF GAIKINDO -> CSV")
    ap.add_argument("pdf")
    ap.add_argument("-o", "--out", help="path CSV output (default: di samping PDF)")
    ap.add_argument("--year", type=int, help="tahun periode (default: dari nama file)")
    ap.add_argument("--inspect", action="store_true", help="tampilkan struktur ekstraksi")
    ap.add_argument("--verify-only", action="store_true",
                    help="validasi tanpa menulis CSV")
    args = ap.parse_args()

    if not os.path.isfile(args.pdf):
        print(f"File tidak ditemukan: {args.pdf}", file=sys.stderr)
        sys.exit(2)

    year = args.year
    if year is None:
        m = re.search(r"(20\d{2})", os.path.basename(args.pdf))
        if not m:
            print("Tahun tidak terdeteksi dari nama file — pakai --year=YYYY.",
                  file=sys.stderr)
            sys.exit(2)
        year = int(m.group(1))

    data = engine.extract(args.pdf)
    rows = gather_models(data)
    officials = gather_officials(data)

    if args.inspect:
        for page in data["pages"]:
            for si, section in enumerate(page["sections"]):
                kinds = {}
                for r in section["rows"]:
                    kinds[r["type"]] = kinds.get(r["type"], 0) + 1
                ms = [x["m"] for x in section["months"]]
                cols = [norm_col(c["name"]) for c in section["columns"]]
                print(f"p{page['index']}#{si} bulan {ms[0]}..{ms[-1]} "
                      f"({len(ms)}) rows={kinds}")
                print(f"   kolom: {cols}")
        for k, r in sorted(officials.items()):
            gm = {int(m): v for m, v in r["months"].items() if v}
            print(f"official[{k}]: total={r['total']} bulan={gm}")
        return

    # ------------------------------------------------ verifikasi dua arah
    warnings = []
    checked = mismatches = filled_total = 0
    month_sum = {m: 0 for m in range(1, 13)}
    out_rows = []
    for r in rows:
        ssum = sum(v for v in r["months"].values() if v)
        total = r["total"]
        if total is None:
            # TOTAL kosong di PDF -> isi dari Σ bulan (verifikasi arah kedua)
            total = ssum or None
            if total is not None:
                filled_total += 1
        else:
            checked += 1
            if total != ssum:
                mismatches += 1
                warnings.append(
                    f"p{r['page']} y={r['y']} {r['brand']} {r['model']}: "
                    f"Σ JAN..DES={ssum} != TOTAL={total}")
        if total or any(r["months"].values()):
            # penanda selisih TOTAL vs Σ JAN..DES ikut tertulis di CSV
            # (kolom CEK diabaikan importer; murni untuk kurasi manual).
            if r["total"] is None:
                r["cek"] = "ISI-DARI-BULAN"
            elif r["total"] != ssum:
                r["cek"] = f"SELISIH Δ{r['total'] - ssum:+d}"
            else:
                r["cek"] = "OK"
            r["csv_total"] = total
            out_rows.append(r)
        for m, v in r["months"].items():
            if v:
                month_sum[m] += v

    parsed_total = sum(r["csv_total"] or 0 for r in out_rows)

    grand = officials.get("grand")
    if grand is None:
        print("GAGAL: rekap resmi DOMESTIC SALES TOTAL tidak terbaca dari PDF — "
              "CSV tidak ditulis.", file=sys.stderr)
        sys.exit(1)
    # TOTAL rekap bisa kosong di cetakan -> isi dari Σ bulan resmi (dua arah).
    grand_total = grand["total"]
    if grand_total is None:
        grand_total = sum(v for v in grand["months"].values() if v)
    if not grand_total:
        print("GAGAL: total rekap DOMESTIC SALES kosong — CSV tidak ditulis.",
              file=sys.stderr)
        sys.exit(1)

    coverage = parsed_total / grand_total if grand_total else 0.0
    if coverage > 1.10:
        print(f"GAGAL: hasil parse {coverage*100:.1f}% dari total resmi "
              f"({grand_total}) — PDF gagal direkonstruksi faithful. "
              f"CSV tidak ditulis.", file=sys.stderr)
        sys.exit(1)
    if coverage < 0.90:
        warnings.append(f"coverage {coverage*100:.1f}% < 90%")

    # verifikasi per bulan vs rekap resmi DOMESTIC
    gm = {int(m): v for m, v in grand["months"].items() if v is not None}
    month_report = []
    for m in range(1, 13):
        official_m = gm.get(m)
        if official_m is None and month_sum[m] == 0:
            continue
        month_report.append((m, month_sum[m], official_m))

    # ------------------------------------------------------------ laporan
    filled = [m for m, v in month_sum.items() if v > 0]
    print(f"File          : {os.path.basename(args.pdf)} (tahun {year})")
    if filled:
        print(f"Periode terisi: bulan {min(filled)}..{max(filled)}")
    print(f"Baris model   : {len(out_rows)} masuk CSV "
          f"({len(rows) - len(out_rows)} dilewati kosong)")
    print(f"Verifikasi baris (TOTAL vs Σ JAN..DES): {checked} dicek, "
          f"{mismatches} selisih; {filled_total} TOTAL kosong diisi dari Σ bulan")
    shown = 0
    for w in warnings:
        if "Σ JAN..DES" in w and shown < 20:
            print(f"  SELISIH: {w}")
            shown += 1
    if mismatches > shown:
        print(f"  ... dan {mismatches - shown} selisih lainnya")
    print("Verifikasi bulan (Σ model vs rekap DOMESTIC resmi):")
    for m, parsed_m, official_m in month_report:
        if official_m is None:
            print(f"  {MONTH_COLS[m-1]}: {parsed_m} (rekap tak memuat bulan ini)")
        else:
            delta = parsed_m - official_m
            mark = "✓" if delta == 0 else f"Δ{delta:+d}"
            print(f"  {MONTH_COLS[m-1]}: {parsed_m} vs {official_m} {mark}")
    print(f"Coverage total : {parsed_total} / {grand_total} = {coverage*100:.2f}%")
    for k, label in (("passenger", "PASSENGER"), ("commercial", "COMMERCIAL")):
        r = officials.get(k)
        if r and r["total"]:
            print(f"Rekap {label:10}: resmi {r['total']} "
                  f"(Σbulan resmi {sum(v for v in r['months'].values() if v)})")

    extra_warnings = [w for w in warnings if "Σ JAN..DES" not in w]
    if extra_warnings:
        print("WARNING:")
        for w in extra_warnings:
            print(f"  - {w}")
    elif mismatches == 0:
        print("Status: OK — semua verifikasi hijau")
    else:
        print(f"Status: OK dengan catatan — {mismatches} baris TOTAL != Σ bulan "
              f"(lihat daftar SELISIH di atas)")

    if args.verify_only:
        return

    # ------------------------------------------------------------ tulis CSV
    out_path = args.out or os.path.splitext(args.pdf)[0] + ".csv"
    columns = build_column_order(data)
    with open(out_path, "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(columns + MONTH_COLS + ["TOTAL", "CEK"])
        for r in out_rows:
            head = []
            for name in columns:
                if name == "BRAND":
                    head.append(r["brand"])
                elif name == "TYPE MODEL":
                    head.append(r["model"])
                else:
                    head.append(r["cols"].get(name, ""))
            months = ["-" if r["months"][m] is None else r["months"][m]
                      for m in range(1, 13)]
            w.writerow(head + months +
                       [r["csv_total"] if r["csv_total"] is not None else "",
                        r["cek"]])
    print(f"✓ CSV ditulis: {out_path}")


if __name__ == "__main__":
    main()
