import os
from PIL import Image

SRC = os.path.join(os.path.dirname(__file__), "..", "assets", "images", "raw")
DST = os.path.join(os.path.dirname(__file__), "..", "assets", "images")

# name -> max width (px)
TARGETS = {
    "hero-visitekaartje-aluminium": 1400,
    "hero-collage-a": 1400,
    "snijplank-just-married": 1200,
    "hero-collage-b": 1000,
    "urn-hartvormig-ketting": 1000,
    "visitekaartje-metaal-barbershop": 1200,
    "hero-collage-c": 1400,
    "mama-puzzelbord": 1200,
    "medaille-heuvelenloop": 1200,
    "nec-stadion-wanddecoratie": 1200,
    "skyline-nijmegen-hout": 1600,
    "naambordje-olifant": 1000,
    "naambordje-dinosaurus": 1000,
    "naambordje-vleermuis": 1000,
    "naambordje-dolfijn": 1000,
    "naambordje-beer": 1000,
}

os.makedirs(DST, exist_ok=True)

for name, max_w in TARGETS.items():
    found = None
    for ext in (".png", ".jpg", ".jpeg"):
        p = os.path.join(SRC, name + ext)
        if os.path.exists(p):
            found = p
            break
    if not found:
        print("MISSING", name)
        continue
    im = Image.open(found).convert("RGB")
    w, h = im.size
    if w > max_w:
        new_h = int(h * (max_w / w))
        im = im.resize((max_w, new_h), Image.LANCZOS)
    out = os.path.join(DST, name + ".webp")
    im.save(out, "WEBP", quality=82, method=6)
    print(name, "->", im.size, os.path.getsize(out) // 1024, "KB")
