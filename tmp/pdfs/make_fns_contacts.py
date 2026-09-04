from pathlib import Path
from PIL import Image, ImageDraw, ImageFont

source = Path(__file__).parent / "fns-pages"
output = Path(__file__).parent / "fns-contacts"
output.mkdir(exist_ok=True)
files = sorted(source.glob("page-*.jpg"), key=lambda p: int(p.stem.split("-")[-1]))
font = ImageFont.load_default(size=18)

for batch_index in range(0, len(files), 20):
    batch = files[batch_index:batch_index + 20]
    canvas = Image.new("RGB", (1000, 1440), "white")
    draw = ImageDraw.Draw(canvas)
    for i, path in enumerate(batch):
        page = int(path.stem.split("-")[-1])
        image = Image.open(path).convert("RGB")
        image.thumbnail((190, 260))
        x = (i % 5) * 200 + 5
        y = (i // 5) * 355 + 30
        draw.text((x, y - 25), f"Page {page}", fill="black", font=font)
        canvas.paste(image, (x, y))
    first = batch_index + 1
    last = batch_index + len(batch)
    canvas.save(output / f"pages-{first:03d}-{last:03d}.jpg", quality=85)
