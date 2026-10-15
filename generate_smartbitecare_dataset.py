#!/usr/bin/env python3
"""
SmartBiteCare simulated forecasting dataset generator (v2, with patterns).

Builds a daily, per-item consumption history for SBI Cainta from
2025-09-30 to 2026-09-30 (366 days) by simulating PATIENTS first and then
deriving supply usage from the clinic's own treatment protocols:

  1. New animal-bite patients per day
       = base level x weekday effect x seasonal effect x slow growth
         x persistent day-to-day noise (AR(1)), drawn as a Poisson count.
  2. Each new patient gets a WHO category (I / II / III), a regimen
     (PEP intradermal Day 0-3-7-28, or intramuscular Day 0-3-7-14-28),
     a body weight and a vaccine brand.
  3. Follow-up visits are scheduled from each patient's Day 0, with
     realistic drop-out (not every patient returns for every dose).
  4. Supplies are consumed by what happens at each visit
     (vaccine sites, ERIG/HRIG mL by weight, tetanus prophylaxis,
     syringes, needles, wound care, take-home medicines, PPE).
  5. Other clinic services (Hepa B, flu, PPD skin tests, pre-exposure)
     add their own seasonal demand.
  6. Stock is simulated with a reorder policy, so
     ending = beginning + received - used holds on every row.

Because follow-up doses are scheduled 3, 7, 14 and 28 days after Day 0,
today's vaccine usage genuinely depends on bite cases 3-28 days ago. That is
the structure the lag_1 / lag_7 / rolling_7 features are meant to learn.

All quantities are in each item's DATABASE BASE UNIT, so import with:
    python import_training_dataset.py smartbitecare_training_2025-09-30_to_2026-09-30.csv --csv-units base --dry-run
    python import_training_dataset.py smartbitecare_training_2025-09-30_to_2026-09-30.csv --csv-units base

Optional end date (to bring the history up to the day before a demo):
    python generate_smartbitecare_dataset.py out.csv 2026-10-20

Deterministic: the same SEED always produces the same file.
"""
import csv
import math
import sys
from collections import defaultdict
from datetime import date, timedelta

import numpy as np

SEED = 20250930
START = date(2025, 9, 30)
END = date.fromisoformat(sys.argv[2]) if len(sys.argv) > 2 else date(2026, 9, 30)
BRANCH = "Cainta"
OUT = sys.argv[1] if len(sys.argv) > 1 else "smartbitecare_training_2025-09-30_to_2026-09-30.csv"

rng = np.random.default_rng(SEED)

# ------------------------------------------------------------------
# Items: database name, base unit, rounding decimals, delivery pack size
# ------------------------------------------------------------------
ITEMS = [
    ("Speeda", "site", 0, 6), ("ABHAYRAB", "site", 0, 6), ("VAXIRAB", "site", 0, 6),
    ("CHIRORAB", "site", 0, 6),
    ("ERIG (Equine Rabies Immunoglobulin)", "mL", 1, 5), ("HRIG", "mL", 1, 5),
    ("HTIG", "mL", 1, 5), ("ATS (Anti-Tetanus Serum)", "mL", 1, 5),
    ('ATS 1,500 "IU"', "mL", 1, 5), ('ATS 3000 "IU"', "mL", 1, 5), ('ATS 5,000 "IU"', "mL", 1, 5),
    ("TT (Tetanus Toxoid)", "Ampule", 0, 10), ("TOXOID BETT", "Ampule", 0, 10),
    ("TOXOID ABHAYTOX", "Ampule", 0, 10),
    ("HEPA B", "mL", 1, 5), ("HEPA BIG", "mL", 1, 5), ("FLU", "mL", 1, 5), ("PPD", "mL", 1, 5),
    ("AMOXICILLIN 500MG", "Piece", 0, 100), ("CEFALEXIN 500 MG", "Piece", 0, 100),
    ("MEFENAMIC 500 MG", "Piece", 0, 100), ("CETIRIZINE", "Piece", 0, 100),
    ("INSULIN", "Piece", 0, 100),            # insulin syringes used for intradermal doses
    ("1CC/3CC", "Box/s", 2, 1), ("5CC/10CC", "Box/s", 2, 1),
    ("G23", "Piece", 0, 100), ("G27", "Piece", 0, 100),
    ("ALCOHOL", "mL", 0, 500), ("BETADINE", "mL", 0, 500), ("STERILE WATER", "mL", 0, 500),
    ("COTTON BALLS", "Packs", 2, 1), ("GAUZE PAD", "Packs", 2, 1), ("MICROPRE", "Piece", 0, 12),
    ("FACEMASK", "Box/s", 2, 1), ("GLOVES", "Box/s", 2, 1),
]
NAMES = [i[0] for i in ITEMS]

# ------------------------------------------------------------------
# Demand patterns
# ------------------------------------------------------------------
BASE_NEW_BITES = 17.0                                   # average new bite patients per day
WEEKDAY = [1.25, 1.00, 0.95, 0.95, 1.00, 1.10, 0.80]    # Mon..Sun (Monday catches weekend bites)
# Month effect (mid-month anchors, interpolated daily): summer peak Mar-May,
# rabies awareness month in March, holiday bump in December, rainy-season dip.
MONTH = {1: 0.95, 2: 0.97, 3: 1.15, 4: 1.30, 5: 1.28, 6: 1.08,
         7: 0.93, 8: 0.88, 9: 0.90, 10: 0.94, 11: 0.98, 12: 1.10}
GROWTH_PER_YEAR = 0.06                                  # clinic slowly gaining patients
AR_PHI, AR_SD = 0.70, 0.10                              # persistent busy/quiet spells
SPECIAL_DAYS = {date(2025, 12, 25): 0.55, date(2026, 1, 1): 0.55,
                date(2025, 12, 24): 0.80, date(2025, 12, 31): 0.80,
                date(2026, 4, 2): 0.85, date(2026, 4, 3): 0.70}   # Holy Week


def seasonal(d: date) -> float:
    """Smooth month effect: interpolate between mid-month anchors."""
    mid = date(d.year, d.month, 15)
    if d >= mid:
        nxt_m = d.month % 12 + 1
        nxt = date(d.year + (d.month == 12), nxt_m, 15)
        w = (d - mid).days / (nxt - mid).days
        return MONTH[d.month] * (1 - w) + MONTH[nxt_m] * w
    prv_m = (d.month - 2) % 12 + 1
    prv = date(d.year - (d.month == 1), prv_m, 15)
    w = (d - prv).days / (mid - prv).days
    return MONTH[prv_m] * (1 - w) + MONTH[d.month] * w


# Protocols
ID_SCHEDULE = [0, 3, 7, 28]          # PEP intradermal, 2 sites per visit
IM_SCHEDULE = [0, 3, 7, 14, 28]      # PEP intramuscular, 1 vial (6 sites) per visit
RETURN_PROB = {0: 1.0, 3: 0.93, 7: 0.86, 14: 0.80, 28: 0.66}
BRAND_SHARE = {"Speeda": 0.55, "ABHAYRAB": 0.15, "VAXIRAB": 0.15, "CHIRORAB": 0.15}
WARMUP = 35                           # simulate earlier days so follow-ups exist on day 1

days = [START - timedelta(days=WARMUP) + timedelta(days=k)
        for k in range((END - START).days + 1 + WARMUP)]

usage = defaultdict(lambda: defaultdict(float))      # usage[day][item]
patients = defaultdict(int)                          # total patient visits per day
bites = defaultdict(int)                             # new animal-bite cases per day
vacc = defaultdict(int)                              # anti-rabies doses given per day
followups = defaultdict(list)                        # day -> list of (regimen, brand)


def add(d, item, qty):
    usage[d][item] += qty


ar = 0.0
for d in days:
    # ---------------- new bite patients ----------------
    ar = AR_PHI * ar + rng.normal(0, AR_SD)
    years = (d - START).days / 365.0
    lam = (BASE_NEW_BITES * WEEKDAY[d.weekday()] * seasonal(d)
           * (1 + GROWTH_PER_YEAR * years) * math.exp(ar) * SPECIAL_DAYS.get(d, 1.0))
    n_new = int(rng.poisson(lam))
    bites[d] += n_new
    patients[d] += n_new

    brands = list(BRAND_SHARE)
    day0_bite = 0
    for _ in range(n_new):
        cat = rng.choice(["I", "II", "III"], p=[0.03, 0.67, 0.30])
        add(d, "ALCOHOL", rng.uniform(4, 7))          # wound washing
        add(d, "COTTON BALLS", 0.03)
        if cat == "I":
            continue                                   # no vaccine for Category I
        weight = float(np.clip(rng.normal(42, 16), 10, 90))
        regimen = "IM" if rng.random() < 0.15 else "ID"
        brand = rng.choice(brands, p=list(BRAND_SHARE.values()))
        schedule = IM_SCHEDULE if regimen == "IM" else ID_SCHEDULE
        day0_bite += 1
        for offset in schedule:
            if rng.random() < RETURN_PROB[offset]:
                followups[d + timedelta(days=offset)].append((regimen, brand))
            else:
                break                                  # a missed dose ends the course
        # Day-0-only care
        add(d, "BETADINE", rng.uniform(2, 4))
        if cat == "III":
            r = rng.random()
            if r < 0.85:
                ml = weight * 0.2                       # ERIG 40 IU/kg at 200 IU/mL
                add(d, "ERIG (Equine Rabies Immunoglobulin)", ml)
            elif r < 0.95:
                ml = weight * 0.133                     # HRIG 20 IU/kg at 150 IU/mL
                add(d, "HRIG", ml)
            else:
                ml = 0
            if ml:
                add(d, "5CC/10CC", (1 + (ml > 10)) / 100)   # box of 100
                add(d, "G27", 2)                              # wound infiltration
                add(d, "GAUZE PAD", 0.1)                      # pack of 10
                add(d, "MICROPRE", 0.05)
                add(d, "STERILE WATER", 2.0)
        # tetanus prophylaxis
        t = rng.random()
        if t < 0.30:
            add(d, "TOXOID BETT", 1)
        elif t < 0.42:
            add(d, "TOXOID ABHAYTOX", 1)
        elif t < 0.50:
            add(d, "TT (Tetanus Toxoid)", 1)
        if cat == "III" and rng.random() < 0.12:
            a = rng.random()
            if a < 0.55:
                add(d, 'ATS 1,500 "IU"', 1.0)
            elif a < 0.75:
                add(d, 'ATS 3000 "IU"', 2.0)
            elif a < 0.85:
                add(d, 'ATS 5,000 "IU"', 3.0)
            elif a < 0.93:
                add(d, "ATS (Anti-Tetanus Serum)", 1.0)
            else:
                add(d, "HTIG", 1.0)
        if t < 0.50:
            add(d, "1CC/3CC", 0.01)
        # take-home medicines (starter packs)
        if cat == "III" or rng.random() < 0.15:
            if rng.random() < 0.75:
                add(d, "AMOXICILLIN 500MG", 6)
            else:
                add(d, "CEFALEXIN 500 MG", 6)
        if rng.random() < 0.40:
            add(d, "MEFENAMIC 500 MG", 4)
        if rng.random() < 0.06:
            add(d, "CETIRIZINE", 2)

    # ---------------- other clinic services ----------------
    weekday = d.weekday() < 5
    m = d.month
    flu_season = 2.4 if m in (4, 5, 6) else (1.4 if m in (10, 11) else 0.6)
    n_flu = rng.poisson(1.2 * flu_season * (1.0 if weekday else 0.5))
    n_hepb = rng.poisson(2.0 * (1.0 if weekday else 0.4))
    n_hbig = rng.poisson(0.12)
    pre_emp = 1.3 if m in (4, 5, 6) else 1.0      # pre-employment PPD tests peak after graduation
    n_ppd = rng.poisson(2.5 * pre_emp * (1.0 if weekday else 0.2))
    n_prep = rng.poisson(1.0 * (1.2 if m in (3, 4, 5) else 1.0))
    add(d, "FLU", 0.5 * n_flu)
    add(d, "HEPA B", 1.0 * n_hepb)
    add(d, "HEPA BIG", 0.5 * n_hbig)
    add(d, "PPD", 0.1 * n_ppd + (0.3 if n_ppd else 0))   # opened-vial waste
    for _ in range(n_prep):                                # pre-exposure Day 0, 7, 21
        brand = rng.choice(brands, p=list(BRAND_SHARE.values()))
        for off in (0, 7, 21):
            if rng.random() < (1.0 if off == 0 else 0.9):
                followups[d + timedelta(days=off)].append(("ID", brand))
    other = n_flu + n_hepb + n_hbig + n_ppd     # pre-exposure visits are counted with vaccine visits
    patients[d] += other
    add(d, "1CC/3CC", (n_flu + n_hepb + n_hbig) / 100)
    add(d, "INSULIN", n_ppd)                               # tuberculin syringes
    add(d, "COTTON BALLS", (n_flu + n_hepb + n_hbig + n_ppd) / 100)
    add(d, "ALCOHOL", 1.0 * other)
    # ---------------- anti-rabies vaccine visits (Day 0 + follow-ups) ----------------
    opened = defaultdict(int)
    for regimen, brand in followups.get(d, []):
        if regimen == "ID":
            add(d, brand, 2)                 # 2 intradermal sites
            add(d, "INSULIN", 2)             # insulin syringe per site
            add(d, "COTTON BALLS", 0.02)
        else:
            add(d, brand, 6)                 # 1 full vial intramuscular
            add(d, "1CC/3CC", 0.01)
            add(d, "COTTON BALLS", 0.01)
        opened[brand] += 1
        vacc[d] += 1
        add(d, "ALCOHOL", 1.0)
    # every vaccine visit except a new bite patient's Day 0 is an extra patient visit
    patients[d] += len(followups.get(d, [])) - day0_bite
    for brand, visits in opened.items():
        vials = math.ceil(visits * 2 / 6)    # reconstitution diluent per vial opened
        add(d, "STERILE WATER", vials * 0.5)
        add(d, "G23", vials)                 # needle for reconstitution

    # PPE: staff baseline + per patient
    add(d, "GLOVES", (12 + 2 * patients[d]) / 100)          # box of 100 gloves
    add(d, "FACEMASK", (8 + 0.25 * patients[d]) / 50)       # box of 50 masks

# ------------------------------------------------------------------
# Round usage, then simulate stock with a reorder policy
# ------------------------------------------------------------------
sim_days = [d for d in days if START <= d <= END]
dec = {n: k for n, _, k, _ in ITEMS}
pack = {n: p for n, _, _, p in ITEMS}


def rnd(x, k):
    return round(x + 1e-9, k)


for d in sim_days:
    for n in NAMES:
        usage[d][n] = rnd(usage[d][n], dec[n])

mean_use = {n: np.mean([usage[d][n] for d in sim_days]) for n in NAMES}
peak = {n: float(np.quantile([usage[d][n] for d in sim_days], 0.99)) for n in NAMES}
LEAD = 3
rows = []
stock = {}
pending = defaultdict(lambda: defaultdict(float))
recommended_min = {}
for n in NAMES:
    mu = max(mean_use[n], 10 ** -dec[n])
    recommended_min[n] = math.ceil(mu * 7 / pack[n]) * pack[n] if pack[n] >= 1 else rnd(mu * 7, 2)
    stock[n] = rnd(math.ceil(max(mu * 20, peak[n] * 4) / pack[n]) * pack[n], dec[n])

for d in sim_days:
    for n in NAMES:
        mu = max(mean_use[n], 10 ** -dec[n])
        begin = stock[n]
        received = rnd(pending[d][n], max(dec[n], 2))
        used = usage[d][n]
        avail = begin + received
        if used > avail:            # never record more than was on the shelf
            used = rnd(avail, dec[n])
        end = rnd(avail - used, max(dec[n], 2))
        stock[n] = end
        # reorder point = 10 days of use (7-day lead + 3-day buffer), or 3 peak days for
        # rarely used items; order enough for about 25 more days
        on_order = sum(v for dd, items in pending.items() if dd > d for k, v in items.items() if k == n)
        rop = max(mu * 10, peak[n] * 3)
        if end + on_order < rop:
            qty = math.ceil((rop + mu * 25 - end - on_order) / pack[n]) * pack[n]
            pending[d + timedelta(days=LEAD + int(rng.integers(0, 3)))][n] += qty
        rows.append({
            "record_date": d.strftime("%d/%m/%Y"),
            "branch_name": BRANCH,
            "total_patient_tally": patients[d],
            "item_name": n,
            "beginning_stock": f"{begin:.4f}".rstrip("0").rstrip("."),
            "quantity_used": f"{used:.4f}".rstrip("0").rstrip(".") if used else "0",
            "stock_received": f"{received:.4f}".rstrip("0").rstrip(".") if received else "0",
            "ending_stock": f"{end:.4f}".rstrip("0").rstrip(".") if end else "0",
            "animal_bite_cases": bites[d],
            "vaccinations_administered": vacc[d],
            "minimum_stock_level": recommended_min[n],
        })

with open(OUT, "w", newline="", encoding="utf-8") as fh:
    w = csv.DictWriter(fh, fieldnames=list(rows[0]))
    w.writeheader()
    w.writerows(rows)

print(f"Wrote {len(rows)} rows, {len(sim_days)} days x {len(NAMES)} items -> {OUT}")