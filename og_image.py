"""Gera og.png (1200x630): a prévia que aparece ao colar o link no WhatsApp/Discord."""
import io, os
from PIL import Image, ImageDraw, ImageFilter, ImageFont

ROOT = os.path.dirname(os.path.abspath(__file__))
FONTS = os.path.join(ROOT, "data", "fonts")
BG, PANEL, INK, INK2, MUTE, GOLD, GOLD2 = "#0d1112", "#161d1e", "#ece5d3", "#aab1a9", "#728079", "#e2a93b", "#f4cd6e"
W, H = 1200, 630


def font(name, size, variation=None):
    f = ImageFont.truetype(os.path.join(FONTS, name), size)
    if variation:
        f.set_variation_by_name(variation)
    return f


def circle(img_bytes, size, ring, ring_w):
    av = Image.open(io.BytesIO(img_bytes)).convert("RGB").resize((size, size), Image.LANCZOS) if img_bytes else Image.new("RGB", (size, size), PANEL)
    out = Image.new("RGBA", (size + ring_w * 2, size + ring_w * 2), (0, 0, 0, 0))
    d = ImageDraw.Draw(out)
    d.ellipse((0, 0, out.width - 1, out.height - 1), fill=ring)
    mask = Image.new("L", (size, size), 0)
    ImageDraw.Draw(mask).ellipse((0, 0, size - 1, size - 1), fill=255)
    out.paste(av, (ring_w, ring_w), mask)
    return out


def make(podium, updated_text, subtitle, path):
    """podium: lista [(apelido, texto_do_valor, bytes_do_avatar)] com até 3 itens, 1º primeiro."""
    im = Image.new("RGB", (W, H), BG)
    glow = Image.new("RGB", (W, H), BG)
    gd = ImageDraw.Draw(glow)
    gd.ellipse((620, -260, 1420, 420), fill="#3a2c10")
    gd.ellipse((-300, 300, 400, 900), fill="#16261a")
    im = Image.blend(im, glow.filter(ImageFilter.GaussianBlur(120)), 1)
    d = ImageDraw.Draw(im)
    d.rectangle((0, 0, W, 8), fill=GOLD)

    d.text((64, 70), "DOTA 2 · RANKING DA GALERA", font=font("BarlowCondensed-Bold.ttf", 30), fill=GOLD)
    title = font("Cinzel.ttf", 76, "Black")
    d.text((60, 112), "Taverna do", font=title, fill=INK)
    d.text((60, 196), "Ancient", font=title, fill=GOLD2)
    d.text((64, 306), subtitle, font=font("BarlowCondensed-SemiBold.ttf", 34), fill=INK2)
    d.rounded_rectangle((64, 520, 64 + 470, 574), radius=27, fill="#1f2a1c", outline="#7dbf5a", width=2)
    d.ellipse((86, 540, 100, 554), fill="#7dbf5a")
    d.text((112, 530), updated_text, font=font("BarlowCondensed-Bold.ttf", 28), fill=INK)

    # pódio em lista vertical à direita (cabe nome comprido)
    d.text((676, 70), "PÓDIO · TAXA DE VITÓRIA", font=font("BarlowCondensed-Bold.ttf", 26), fill=MUTE)
    place_f, name_f, val_f = font("Cinzel.ttf", 40, "Black"), font("BarlowCondensed-Bold.ttf", 40), font("BarlowCondensed-SemiBold.ttf", 34)
    y = 118
    for i, (nick, value, av) in enumerate(podium[:3]):
        size = 112 if i == 0 else 92
        ring = GOLD if i == 0 else ("#c9d1d3" if i == 1 else "#d08a55")
        c = circle(av, size, ring, 5)
        d.text((676, y + c.height / 2 - 26), f"{i + 1}º", font=place_f, fill=ring)
        im.paste(c, (740, y), c)
        tx = 740 + c.width + 18
        while d.textlength(nick, font=name_f) > W - tx - 40 and len(nick) > 4:
            nick = nick[:-2] + "…"
        d.text((tx, y + c.height / 2 - 42), nick, font=name_f, fill=INK)
        d.text((tx, y + c.height / 2 + 4), value, font=val_f, fill=GOLD2 if i == 0 else INK2)
        y += c.height + 34
    im.save(path, "PNG", optimize=True)
