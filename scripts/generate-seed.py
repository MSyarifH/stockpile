"""Regenerates database/seed.sql deterministically.

    docker compose run --rm --no-deps app python3 scripts/generate-seed.py

The bcrypt hash below is a DEMO credential for the seeded accounts
(password: Password123!). It is intentionally committed -- it is not an
active secret, and §7 requires working demo accounts. Real credentials
live in .env, which is git-ignored.
"""
import os, random, datetime, collections
R = random.Random(20261002)          # fixed seed => byte-identical seed.sql every run
q = lambda s: "'" + str(s).replace("\\","\\\\").replace("'","''") + "'"

# Override with SEED_PASSWORD_HASH=... to re-hash; default keeps the file stable.
HASH = os.environ.get(
    "SEED_PASSWORD_HASH",
    "$2y$10$vh5iiFvhaS53oJehaDv9GuBrN3lMIG/uM08JL9rDZZnlua7OePlPi",
)

CATEGORIES = [
 ("Elektronik","Perangkat elektronik dan aksesorinya"),
 ("Alat Tulis","Kebutuhan kantor dan tulis menulis"),
 ("Perkakas","Peralatan bengkel dan pertukangan"),
 ("Rumah Tangga","Peralatan kebutuhan rumah tangga"),
 ("Kesehatan","Alat kesehatan dan kebersihan"),
 ("Makanan & Minuman","Konsumsi kemasan"),
]
WAREHOUSES = [
 ("Gudang Jakarta","Jl. Daan Mogot KM 12, Jakarta Barat"),
 ("Gudang Bandung","Jl. Soekarno Hatta 405, Bandung"),
 ("Gudang Surabaya","Jl. Rungkut Industri 8, Surabaya"),
]
USERS = [
 ("Rizky Admin",      "admin@ioms.test",      "Admin",          1),
 ("Sinta Sales",      "sales1@ioms.test",     "Sales",          1),
 ("Bagus Sales",      "sales2@ioms.test",     "Sales",          1),
 ("Wawan Gudang",     "warehouse1@ioms.test", "WarehouseStaff", 1),
 ("Dewi Gudang",      "warehouse2@ioms.test", "WarehouseStaff", 1),
 ("Nonaktif Sales",   "inactive@ioms.test",   "Sales",          0),  # proves AUTH-01 inactive-login rule
 # Second Admin exists for a reason: approved_by must never equal created_by (D2).
 # With a single Admin, any order an Admin raised could never be approved by anyone.
 ("Putri Admin",      "admin2@ioms.test",     "Admin",          1),
]
SUPPLIERS = [
 ("PT Sinar Elektronik","021-5550101","Jl. Gajah Mada 17, Jakarta"),
 ("CV Mitra Kertas","022-5550202","Jl. Braga 88, Bandung"),
 ("PT Baja Perkasa","031-5550303","Jl. Kedungdoro 12, Surabaya"),
 ("UD Sumber Rejeki","024-5550404","Jl. Pandanaran 45, Semarang"),
 ("PT Anugerah Sehat","021-5550505","Jl. Sudirman 90, Jakarta"),
 ("CV Boga Nusantara","0274-555060","Jl. Malioboro 21, Yogyakarta"),
]
CUSTOMERS = [
 ("Toko Maju Jaya","0812-1111-001","Jl. Pasar Baru 3, Jakarta"),
 ("CV Berkah Abadi","0812-1111-002","Jl. Cihampelas 55, Bandung"),
 ("PT Karya Mandiri","0812-1111-003","Jl. Basuki Rahmat 9, Surabaya"),
 ("Toko Sentosa","0812-1111-004","Jl. Ahmad Yani 71, Semarang"),
 ("UD Harapan Baru","0812-1111-005","Jl. Diponegoro 14, Malang"),
 ("PT Global Retail","0812-1111-006","Jl. Thamrin 1, Jakarta"),
 ("Toko Sahabat","0812-1111-007","Jl. Veteran 33, Bandung"),
 ("CV Prima Niaga","0812-1111-008","Jl. Panglima Sudirman 5, Surabaya"),
]
PRODUCTS = [
 # (name, category_idx, unit, purchase, selling, reorder_point)
 ("Keyboard Mekanik K1",0,"pcs",320000,459000,15),("Mouse Wireless M2",0,"pcs",95000,149000,25),
 ("Monitor 24 inch",0,"unit",1450000,1899000,8),("Headset Gaming H3",0,"pcs",275000,389000,12),
 ("Webcam HD 1080p",0,"pcs",210000,299000,10),("Hub USB-C 6 Port",0,"pcs",165000,239000,20),
 ("Kabel HDMI 2m",0,"pcs",42000,69000,40),("Power Bank 10000mAh",0,"pcs",180000,259000,18),
 ("Pulpen Gel 0.5 Hitam",1,"lusin",18000,29000,50),("Buku Tulis 58 Lembar",1,"pak",32000,48000,40),
 ("Kertas HVS A4 80gsm",1,"rim",52000,72000,30),("Spidol Whiteboard",1,"lusin",36000,55000,25),
 ("Stapler Besar",1,"pcs",45000,68000,15),("Map Plastik Folder",1,"pak",22000,35000,35),
 ("Tinta Printer Hitam",1,"botol",68000,95000,20),
 ("Obeng Set 12 pcs",2,"set",125000,179000,10),("Tang Kombinasi 8 inch",2,"pcs",68000,99000,15),
 ("Kunci Inggris 10 inch",2,"pcs",85000,125000,12),("Meteran 5m",2,"pcs",38000,59000,20),
 ("Bor Listrik 400W",2,"unit",420000,589000,6),("Palu Karet",2,"pcs",42000,65000,15),
 ("Panci Stainless 24cm",3,"pcs",145000,209000,10),("Wajan Anti Lengket 28cm",3,"pcs",128000,185000,12),
 ("Set Pisau Dapur 5 pcs",3,"set",98000,145000,14),("Rak Piring 2 Susun",3,"unit",175000,249000,8),
 ("Termos Air 1.8L",3,"pcs",88000,129000,16),
 ("Masker Medis 3 Ply",4,"box",28000,45000,60),("Hand Sanitizer 500ml",4,"botol",32000,52000,45),
 ("Termometer Digital",4,"pcs",65000,98000,20),("Sarung Tangan Latex",4,"box",48000,72000,30),
 ("Kopi Bubuk 200g",5,"pak",24000,38000,50),("Teh Celup 25 Sachet",5,"box",15000,25000,55),
 ("Air Mineral 600ml",5,"karton",38000,55000,40),("Biskuit Kaleng 700g",5,"kaleng",62000,89000,25),
]

L = []            # SQL lines
add = L.append

add("-- ============================================================")
add("-- Seed data (§7.1 Data Demo Minimum)")
add("-- GENERATED FILE - regenerate with scripts/generate-seed.py, do not hand-edit.")
add("-- Demo password for every account: Password123!")
add("-- ============================================================")
add("SET FOREIGN_KEY_CHECKS = 0;")
for t in ["stock_ledger","sales_order_items","sales_orders","purchase_order_items","purchase_orders",
          "product_stocks","products","categories","suppliers","customers","warehouses","users"]:
    add(f"TRUNCATE TABLE {t};")
add("SET FOREIGN_KEY_CHECKS = 1;")
add("")

add("INSERT INTO categories (id, name, description) VALUES")
add(",\n".join(f"  ({i+1}, {q(n)}, {q(d)})" for i,(n,d) in enumerate(CATEGORIES)) + ";")
add("")
add("INSERT INTO warehouses (id, name, location, is_active) VALUES")
add(",\n".join(f"  ({i+1}, {q(n)}, {q(l)}, 1)" for i,(n,l) in enumerate(WAREHOUSES)) + ";")
add("")
add("INSERT INTO users (id, name, email, password_hash, role, is_active) VALUES")
add(",\n".join(f"  ({i+1}, {q(n)}, {q(e)}, {q(HASH)}, {q(r)}, {a})" for i,(n,e,r,a) in enumerate(USERS)) + ";")
add("")
add("INSERT INTO suppliers (id, name, contact, address, is_active) VALUES")
add(",\n".join(f"  ({i+1}, {q(n)}, {q(c)}, {q(a)}, 1)" for i,(n,c,a) in enumerate(SUPPLIERS)) + ";")
add("")
add("INSERT INTO customers (id, name, contact, address, is_active) VALUES")
add(",\n".join(f"  ({i+1}, {q(n)}, {q(c)}, {q(a)}, 1)" for i,(n,c,a) in enumerate(CUSTOMERS)) + ";")
add("")

# ---- products -------------------------------------------------------
rows=[]
for i,(name,cat,unit,pp,sp,rp) in enumerate(PRODUCTS):
    pid=i+1
    sku = f"SKU-{['ELK','ATK','PRK','RTG','KES','FNB'][cat]}-{pid:04d}"
    active = 0 if pid in (7,34) else 1     # two deactivated products (soft-delete demo)
    rows.append(f"  ({pid}, {q(sku)}, {q(name)}, {cat+1}, {q(unit)}, {pp}.00, {sp}.00, {rp}, {active})")
add("INSERT INTO products (id, sku, name, category_id, unit, purchase_price, selling_price, reorder_point, is_active) VALUES")
add(",\n".join(rows)+";")
add("")

NP=len(PRODUCTS)
# ---- simulate the warehouse ----------------------------------------
stock=collections.defaultdict(int)     # (product, warehouse) -> qty
ledger=[]                              # (pid, wid, type, signed_qty, ref_type, ref_id, user, datetime)
BASE=datetime.datetime(2026,6,1,8,0,0)

def move(pid,wid,typ,qty,ref_type,ref_id,user,when):
    """Single source of truth: every stock change goes through here, so
       product_stocks can never disagree with stock_ledger."""
    signed = qty if typ in ("Receipt","Adjustment") else -qty
    stock[(pid,wid)] += signed
    assert stock[(pid,wid)] >= 0, (pid,wid,stock[(pid,wid)])
    ledger.append((pid,wid,typ,signed,ref_type,ref_id,user,when))

# opening balances: every product exists in WH1 & WH2, half also in WH3
for pid in range(1,NP+1):
    rp = PRODUCTS[pid-1][5]
    for wid in (1,2,3):
        if wid==3 and pid%2==0: continue
        if pid%7==0:   base=R.randint(0, max(1,rp//3))      # deliberately below reorder point
        elif pid%5==0: base=R.randint(rp, rp+5)             # hovering at the line
        else:          base=R.randint(rp+10, rp+90)         # comfortable
        if base>0:
            move(pid,wid,"Adjustment",base,"Manual",None,1,BASE)

# ---- purchase orders ------------------------------------------------
PO_PLAN = [("Received",5),("PartiallyReceived",3),("Ordered",3),("Draft",2),("Cancelled",1)]
po_rows=[]; poi_rows=[]; poi_id=1; po_id=0
d = datetime.date(2026,6,5)
for status,count in PO_PLAN:
    for _ in range(count):
        po_id+=1
        wid=R.choice([1,2,3]); sup=R.randint(1,len(SUPPLIERS))
        creator=R.choice([1,4,5]); d += datetime.timedelta(days=R.randint(1,3))
        num=f"PO-2026-{po_id:04d}"
        po_rows.append(f"  ({po_id}, {q(num)}, {sup}, {wid}, {q(status)}, {q(d.isoformat())}, {creator})")
        for _ in range(R.randint(1,3)):
            pid=R.randint(1,NP); qty=R.randint(10,60)
            if status=="Received":            recv=qty
            elif status=="PartiallyReceived": recv=R.randint(1,qty-1)
            else:                             recv=0
            price=PRODUCTS[pid-1][3]
            poi_rows.append(f"  ({poi_id}, {po_id}, {pid}, {qty}, {recv}, {price}.00)")
            poi_id+=1
            if recv>0:
                when=datetime.datetime.combine(d,datetime.time(10,0))+datetime.timedelta(days=1)
                move(pid,wid,"Receipt",recv,"PurchaseOrder",po_id,creator,when)
add("INSERT INTO purchase_orders (id, po_number, supplier_id, warehouse_id, status, order_date, created_by) VALUES")
add(",\n".join(po_rows)+";")
add("")
add("INSERT INTO purchase_order_items (id, purchase_order_id, product_id, quantity, received_quantity, purchase_price) VALUES")
add(",\n".join(poi_rows)+";")
add("")

# ---- sales orders ---------------------------------------------------
SO_PLAN=[("Fulfilled",5),("Approved",3),("PendingApproval",4),("Draft",2),("Cancelled",2)]
so_rows=[]; soi_rows=[]; soi_id=1; so_id=0
d = datetime.date(2026,6,8)
for status,count in SO_PLAN:
    for _ in range(count):
        so_id+=1
        wid=R.choice([1,2]); cust=R.randint(1,len(CUSTOMERS))
        creator=R.choice([2,3])                     # always a Sales user
        # approved_by must be an Admin and must differ from creator (§1.2)
        approver = R.choice([1, 7]) if status in ("Approved","Fulfilled") else None
        d += datetime.timedelta(days=R.randint(1,2))
        num=f"SO-2026-{so_id:04d}"
        appr = f"{approver}, {q(str(datetime.datetime.combine(d,datetime.time(9,30))))}" if approver else "NULL, NULL"
        so_rows.append(f"  ({so_id}, {q(num)}, {cust}, {wid}, {q(status)}, {q(d.isoformat())}, {creator}, {appr})")
        for _ in range(R.randint(1,3)):
            pid=R.randint(1,NP)
            avail=stock[(pid,wid)]
            if status=="Fulfilled" and avail<2: continue      # never seed an oversell
            qty=R.randint(1,max(1,min(20,avail))) if status=="Fulfilled" else R.randint(1,15)
            soi_rows.append(f"  ({soi_id}, {so_id}, {pid}, {qty}, {PRODUCTS[pid-1][4]}.00)")
            soi_id+=1
            if status=="Fulfilled":
                when=datetime.datetime.combine(d,datetime.time(14,0))
                move(pid,wid,"Issue",qty,"SalesOrder",so_id,R.choice([4,5]),when)
add("INSERT INTO sales_orders (id, so_number, customer_id, warehouse_id, status, order_date, created_by, approved_by, approved_at) VALUES")
add(",\n".join(so_rows)+";")
add("")
add("INSERT INTO sales_order_items (id, sales_order_id, product_id, quantity, selling_price) VALUES")
add(",\n".join(soi_rows)+";")
add("")

# ---- emit derived tables -------------------------------------------
add("-- product_stocks and stock_ledger below are BOTH derived from the same")
add("-- simulation, so SUM(stock_ledger.quantity) == product_stocks.quantity")
add("-- holds for every (product, warehouse) pair in this seed.")
srows=[f"  ({i+1}, {p}, {w}, {qty})" for i,((p,w),qty) in enumerate(sorted(stock.items()))]
add("INSERT INTO product_stocks (id, product_id, warehouse_id, quantity) VALUES")
add(",\n".join(srows)+";")
add("")
ledger.sort(key=lambda r: r[7])
lrows=[]
for i,(p,w,t,qt,rt,ri,u,when) in enumerate(ledger):
    lrows.append(f"  ({i+1}, {p}, {w}, {q(t)}, {qt}, {q(rt)}, {'NULL' if ri is None else ri}, {u}, {q(str(when))})")
add("INSERT INTO stock_ledger (id, product_id, warehouse_id, movement_type, quantity, reference_type, reference_id, performed_by, created_at) VALUES")
add(",\n".join(lrows)+";")

open("database/seed.sql","w").write("\n".join(L)+"\n")

low=sum(1 for (p,w),qty in stock.items() if qty < PRODUCTS[p-1][5])
print(f"products={NP} warehouses={len(WAREHOUSES)} users={len(USERS)}")
print(f"PO={po_id} SO={so_id} total_orders={po_id+so_id}")
print(f"stock_rows={len(stock)} ledger_rows={len(ledger)} below_reorder_point={low}")
