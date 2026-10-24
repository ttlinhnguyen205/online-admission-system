"""Render clearly fictional evidence; requires Python 3 and Pillow."""
import base64
import io
import json
import os
import sys
from functools import lru_cache
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

sys.stdin.reconfigure(encoding='utf-8')
sys.stdout.reconfigure(encoding='utf-8')
fonts = Path(os.environ.get('WINDIR', 'C:/Windows')) / 'Fonts'
regular = fonts / 'segoeui.ttf'
bold = fonts / 'segoeuib.ttf'
if not regular.exists():
    regular = Path('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf')
    bold = regular.with_name('DejaVuSans-Bold.ttf')

@lru_cache(maxsize=32)
def load_font(size, strong):
    return ImageFont.truetype(str(bold if strong else regular), size)

for record in json.load(sys.stdin):
    for kind in ('profile', 'exam', 'transcript'):
        small = kind == 'profile'
        width, height = (600, 800) if small else (1000, 1300)
        image = Image.new('RGB', (width, height), 'white')
        draw = ImageDraw.Draw(image)
        accent = ('#185B70', '#455A86', '#3C7563')[record['variant'] % 3]

        def text(value, size, x, y, max_width, color='#172B40', strong=False):
            font = load_font(size, strong)
            line = ''
            for word in str(value).split():
                candidate = (line + ' ' + word).strip()
                if draw.textlength(candidate, font=font) > max_width and line:
                    draw.text((x, y), line, font=font, fill=color)
                    y += size + 7
                    line = word
                else:
                    line = candidate
            draw.text((x, y), line, font=font, fill=color)

        draw.rectangle((0, 0, width, 20), fill=accent)
        text(record['labels']['brand'], 20, 45, 44, width-90, accent, True)
        text(record['labels'][kind], 34, 45, 110, width-90, strong=True)
        text(record['name'], 30, 45, 225, width-90, strong=True)
        text(record['code'] + ' | ' + record['birth'], 21, 45, 310, width-90)
        text(record['school'], 22, 45, 365, width-90)
        if small:
            text(record['labels']['profileNote'], 24, 45, 480, width-90)
        else:
            y = 475
            for row in record['examRows' if kind == 'exam' else 'transcriptRows']:
                draw.rectangle((45, y, 955, y+48), fill='#F1F5F8')
                text(row['label'], 22, 65, y+8, 650)
                text(row['value'], 24, 790, y+6, 145, accent, True)
                y += 56
            text(record['examTotal' if kind == 'exam' else 'transcriptTotal'], 24, 45, y+25, 910, accent, True)
            text(record['labels']['note'], 20, 45, 1140, 910)
        text(record['labels']['watermark'], 20, 35, height-85, width-70, '#B12B3C', True)
        buffer = io.BytesIO()
        image.save(buffer, format='PNG')
        print(json.dumps({'code': record['code'], 'kind': kind, 'png': base64.b64encode(buffer.getvalue()).decode('ascii')}))
