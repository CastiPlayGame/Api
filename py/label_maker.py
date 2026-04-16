#!/usr/bin/env python3
"""
label_maker.py – headless label renderer/printer called by PHP.

Usage:
    python label_maker.py --payload-file <path> [--print] [--printer <name>]

Outputs JSON to stdout:  {"ok": true, "qr_token": "..."}
"""

import argparse
import base64
import hashlib
import hmac as _hmac
import json
import os
import secrets
import sys
from pathlib import Path

# ── Bootstrap: inject user site-packages before any third-party imports ───────
def _bootstrap_syspath():
    env_file = Path(__file__).parent.parent / '.env'
    pkgs = None
    if env_file.exists():
        for line in env_file.read_text(encoding='utf-8').splitlines():
            line = line.strip()
            if line.startswith('PYTHON_PACKAGES='):
                pkgs = line.split('=', 1)[1].strip().strip('"').strip("'")
                break
    if not pkgs:
        # fallback: derive from current Python executable location
        import site
        pkgs = site.getusersitepackages()
    base = Path(pkgs)
    for sub in [base, base/'win32', base/'win32'/'lib', base/'Pythonwin']:
        s = str(sub)
        if sub.exists() and s not in sys.path:
            sys.path.insert(0, s)
    dll_dir = base / 'pywin32_system32'
    if dll_dir.exists():
        try:
            os.add_dll_directory(str(dll_dir))
        except Exception:
            pass

_bootstrap_syspath()

# ── Optional dependencies ─────────────────────────────────────────────────────
try:
    from PIL import Image as _Img, ImageDraw as _IDraw, ImageFont as _IFont
    _HAS_PIL = True
except ImportError:
    _HAS_PIL = False

try:
    import qrcode as _qrcode
    _HAS_QR = True
except ImportError:
    _HAS_QR = False

try:
    from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
    from cryptography.hazmat.backends import default_backend
    _HAS_CRYPTO = True
except ImportError:
    _HAS_CRYPTO = False

# ── Paths ─────────────────────────────────────────────────────────────────────
_HERE       = Path(__file__).parent
_ROOT       = _HERE.parent
_ENV_FILE   = _ROOT / '.env'
_LAYOUT_FILE = _HERE / 'layout.json'

# ── Label constants ───────────────────────────────────────────────────────────
DPI   = 203
W_MM  = 51.5
H_MM  = 29.0
_SCALE_MM = 10          # canvas px/mm in label_preview (for font size parity)
_SCREEN_DPI = 96        # reference screen DPI used in label_preview

# ── .env loader ───────────────────────────────────────────────────────────────
def _load_env() -> dict:
    env = {}
    if _ENV_FILE.exists():
        for line in _ENV_FILE.read_text(encoding='utf-8').splitlines():
            line = line.strip()
            if not line or line.startswith('#') or '=' not in line:
                continue
            k, _, v = line.partition('=')
            env[k.strip()] = v.strip().strip('"').strip("'")
    return env

# ── Crypto: AES-256-CBC + HMAC-SHA256 (same scheme as PHP LabelController) ───
def _derive_key(aes_key: str) -> bytes:
    return hashlib.sha256(aes_key.encode()).digest()   # 32 bytes

def _b64u_enc(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).rstrip(b'=').decode()

def build_qr_token(qr_data: dict, aes_key: str, hmac_key: str) -> str:
    """
    Encrypt qr_data as CSV to a signed, tamper-proof base64url token.
    CSV order: id,purchase_number,amount(q),num_packages(n)
    Null/None values become empty string.
    Format: base64url( HMAC-SHA256[:10] | IV[16] | AES-CBC-ciphertext )
    """
    def _s(v): return '' if v is None or str(v).lower() in ('null','none') else str(v)
    csv_line = ','.join([_s(qr_data.get('id')),
                         _s(qr_data.get('p')),
                         _s(qr_data.get('q')),
                         _s(qr_data.get('n'))])
    plaintext = csv_line.encode('utf-8')

    # PKCS7 padding to next 16-byte block
    pad = 16 - len(plaintext) % 16
    plaintext += bytes([pad] * pad)

    key = _derive_key(aes_key)
    iv  = secrets.token_bytes(16)

    cipher    = Cipher(algorithms.AES(key), modes.CBC(iv), backend=default_backend())
    enc       = cipher.encryptor()
    ciphertext = enc.update(plaintext) + enc.finalize()

    # Truncate HMAC to 10 bytes (80-bit auth) → total 42 raw bytes → 56-char base64url token
    sig = _hmac.new(hmac_key.encode(), iv + ciphertext, hashlib.sha256).digest()[:10]

    return _b64u_enc(sig + iv + ciphertext)

# ── charge_id → 6-char MD5 hex (matches label_preview.py) ───────────────────
def charge_to_hex(cid) -> str:
    s = str(cid).strip() if cid is not None else ''
    if not s or s.lower() in ('null', 'none'):
        s = '0'
    try:
        s = str(int(s))
    except ValueError:
        pass
    return hashlib.md5(s.encode()).hexdigest()[:6].upper()

# ── Layout ────────────────────────────────────────────────────────────────────
_active_layout: dict = {}   # set by main() before rendering

def _load_layout() -> tuple:
    """Returns (elements_list, print_offset_dict).
    Uses _active_layout if set, otherwise falls back to layout.json.
    """
    d = _active_layout or (json.loads(_LAYOUT_FILE.read_text(encoding='utf-8'))
                           if _LAYOUT_FILE.exists() else {})
    return d.get('layout', []), d.get('print_offset', {'x': 0.0, 'y': 0.0})

# ── Font helper (matches label_preview.py _get_font logic) ───────────────────
def _get_font(style: str, font_num: float, dpi: int = DPI):
    pt_size   = font_num * 4
    screen_px = pt_size * _SCREEN_DPI / 72.0
    height_mm = screen_px / _SCALE_MM
    size_px   = max(8, int(height_mm * dpi / 25.4))

    paths = {
        'Bold':  'C:/Windows/Fonts/arialbd.ttf',
        'Light': 'C:/Windows/Fonts/arial.ttf',
        'Black': 'C:/Windows/Fonts/ariblk.ttf',
    }
    path = paths.get(style, 'C:/Windows/Fonts/arial.ttf')
    try:
        return _IFont.truetype(path, size_px)
    except Exception:
        return _IFont.load_default()

# ── Text wrap (matches label_preview.py _wrap_text) ──────────────────────────
def _wrap_text(draw, text: str, font, max_px: int) -> list:
    words, lines, cur = text.split(), [], ''
    for w in words:
        test = (cur + ' ' + w).strip()
        try:
            bx    = draw.textbbox((0, 0), test, font=font)
            width = bx[2] - bx[0]
        except Exception:
            width, _ = draw.textsize(test, font=font)  # type: ignore
        if width <= max_px:
            cur = test
        else:
            if cur:
                lines.append(cur)
            cur = w
    if cur:
        lines.append(cur)
    return lines or [text]

_ANC_PIL = {
    'nw': 'lt', 'n': 'mt', 'ne': 'rt',
    'w':  'lm', 'center': 'mm', 'e': 'rm',
    'sw': 'lb', 's': 'mb', 'se': 'rb',
}

# ── Label renderer ────────────────────────────────────────────────────────────
def render_label(payload: dict, qr_token: str) -> '_Img.Image':  # type: ignore
    """Render full label to a PIL RGB image at DPI resolution."""
    scale = DPI / 25.4
    w_px  = int(W_MM * scale)
    h_px  = int(H_MM * scale)

    img  = _Img.new('RGB', (w_px, h_px), 'white')
    draw = _IDraw.Draw(img)

    layout, _ = _load_layout()

    # Build text bindings
    code  = payload.get('code', '')
    desc_raw = payload.get('description', '')
    brand    = payload.get('brand', '').strip()
    desc     = (brand + ' ' + desc_raw).strip() if brand else desc_raw
    charge   = 'CB-' + charge_to_hex(payload.get('charge_id'))
    qty_n    = payload.get('content', {}).get('quantity', 0)
    qty      = f'CANT: {qty_n} UND.'

    bind_map = {
        'qr':   qr_token,
        'code': code,
        'desc': desc,
        'cb':   charge,
        'qty':  qty,
    }

    for el in layout:
        kind = el.get('kind')
        ex   = int(el.get('x', 0) * scale)
        ey   = int(el.get('y', 0) * scale)

        # ── QR code ──────────────────────────────────────────────────────────
        if kind == 'qr' and _HAS_QR:
            bind = el.get('bind', 'qr')
            text = bind_map.get(bind, el.get('text', qr_token))
            sz   = int(float(el.get('size', 15)) * scale)
            # Step 1: build at box_size=1 to get module count
            qr = _qrcode.QRCode(
                error_correction=_qrcode.constants.ERROR_CORRECT_M,
                box_size=1, border=0)
            qr.add_data(text)
            qr.make(fit=True)
            modules  = qr.modules_count
            # Step 2: recalculate box_size so QR fills sz exactly (no blur)
            box_size = max(1, sz // modules)
            qr2 = _qrcode.QRCode(
                error_correction=_qrcode.constants.ERROR_CORRECT_M,
                box_size=box_size, border=0)
            qr2.add_data(text)
            qr2.make(fit=True)
            qr_img = qr2.make_image(fill_color='black',
                                    back_color='white').convert('RGB')
            # Crop/pad to exact sz with NEAREST (no interpolation blur)
            qr_img = qr_img.resize((sz, sz), _Img.NEAREST)
            anc = el.get('anchor', 'nw')
            if anc == 'center':
                ex -= sz // 2; ey -= sz // 2
            elif anc == 'ne':
                ex -= sz
            elif anc == 'sw':
                ey -= sz
            elif anc == 'se':
                ex -= sz; ey -= sz
            img.paste(qr_img, (ex, ey))

        # ── Text ──────────────────────────────────────────────────────────────
        elif kind == 'text':
            bind    = el.get('bind', '')
            text    = bind_map.get(bind, el.get('text', ''))
            font    = _get_font(el.get('style', 'Normal'), el.get('font', 3))
            anc_p   = _ANC_PIL.get(el.get('anchor', 'nw'), 'lt')
            just    = el.get('justify', 'left')
            wrap_mm = el.get('wrap', 0)
            spacing = el.get('spacing', 0)

            if wrap_mm and wrap_mm > 0:
                max_px = int(wrap_mm * scale)
                lines  = _wrap_text(draw, text, font, max_px)
                draw.multiline_text((ex, ey), '\n'.join(lines),
                                    font=font, fill='black',
                                    align=just, spacing=spacing)
            else:
                draw.text((ex, ey), text, font=font,
                          fill='black', anchor=anc_p)

        # ── Line ──────────────────────────────────────────────────────────────
        elif kind == 'line':
            direction = el.get('direction', 'h')
            th  = max(1, int(el.get('thickness', 0.5) * scale))
            ln  = int(el.get('length', 10) * scale)
            anc = el.get('anchor', 'nw')

            if direction == 'v':
                # vertical in preview = horizontal on printed label
                if anc == 'center':
                    draw.rectangle([ex, ey - ln//2,
                                    ex + th - 1, ey + ln//2], fill='black')
                else:
                    draw.rectangle([ex, ey,
                                    ex + th - 1, ey + ln], fill='black')
            else:
                if anc == 'center':
                    draw.rectangle([ex - ln//2, ey,
                                    ex + ln//2, ey + th - 1], fill='black')
                else:
                    draw.rectangle([ex, ey,
                                    ex + ln, ey + th - 1], fill='black')

    return img

# ── TSPL image encoder (matches label_preview.py) ────────────────────────────
def _img_to_tspl(img, copies: int = 1,
                 offset_x: float = 0.0, offset_y: float = 0.0,
                 direction: int = 0) -> bytes:
    # Hard threshold (no dithering) → crisp black/white for thermal print
    gray = img.convert('L')
    bw   = gray.point(lambda p: 0 if p < 128 else 255, '1')
    w, h   = bw.size
    wb     = (w + 7) // 8
    pixels = list(bw.getdata())
    raw    = bytearray()
    for row in range(h):
        for bc in range(wb):
            byte = 0
            for bit in range(8):
                col = bc * 8 + bit
                if col >= w:
                    byte |= (1 << (7 - bit))   # padding → white
                elif pixels[row * w + col] == 0:
                    pass                        # black → print (bit=0)
                else:
                    byte |= (1 << (7 - bit))   # white → no-print
            raw.append(byte)
    ox = int(offset_x * DPI / 25.4)
    oy = int(offset_y * DPI / 25.4)
    header = (
        f'SIZE {W_MM} mm, {H_MM} mm\r\n'
        f'GAP 2 mm, 0\r\n'
        f'DIRECTION {direction}\r\n'
        f'REFERENCE 0,0\r\n'
        f'OFFSET 0 mm\r\n'
        f'CLS\r\n'
        f'BITMAP {ox},{oy},{wb},{h},0,'
    ).encode('ascii')
    footer = f'\r\nPRINT {copies}\r\n'.encode('ascii')
    return header + bytes(raw) + footer

# ── Printer ───────────────────────────────────────────────────────────────────
def _send_to_printer(tspl: bytes, printer_name: str):
    import win32print  # type: ignore
    h = win32print.OpenPrinter(printer_name)
    try:
        win32print.StartDocPrinter(h, 1, ('Label', None, 'RAW'))
        try:
            win32print.StartPagePrinter(h)
            win32print.WritePrinter(h, tspl)
            win32print.EndPagePrinter(h)
        finally:
            win32print.EndDocPrinter(h)
    finally:
        win32print.ClosePrinter(h)

# ── Entry point ───────────────────────────────────────────────────────────────
def main():
    global _active_layout

    ap = argparse.ArgumentParser()
    ap.add_argument('--payload-file', required=True, help='JSON payload file')
    ap.add_argument('--layout-file',  default=None,
                    help='JSON layout file (overrides default layout.json)')
    ap.add_argument('--print', action='store_true', dest='do_print')
    ap.add_argument('--printer', default=None)
    ap.add_argument('--copy-num',     type=int, default=1,
                    help='1-based copy index (makes QR unique per copy)')
    ap.add_argument('--total-copies', type=int, default=1)
    args = ap.parse_args()

    with open(args.payload_file, encoding='utf-8') as f:
        payload = json.load(f)

    # Override layout if --layout-file provided
    if args.layout_file:
        with open(args.layout_file, encoding='utf-8') as f:
            _active_layout = json.load(f)

    env      = _load_env()
    aes_key  = env.get('AES_KEY', '')
    hmac_key = env.get('HMAC_KEY', '')

    # ── Build QR payload (CSV format for compact token) ────────────────────
    # Order: id , purchase_number , amount(q) , num_packages(n)
    # AES-256-CBC + HMAC-SHA256 → signed & tamper-proof; PHP can decrypt.
    qr_data: dict = {
        'id': payload.get('code', ''),
        'p':  payload.get('purchase_number', None),
        'q':  payload.get('amount', 0),
        'n':  payload.get('n', 1),
    }

    if not _HAS_CRYPTO:
        raise RuntimeError(
            'La librería "cryptography" no está instalada. '
            'Ejecuta: pip install cryptography'
        )
    if not aes_key or not hmac_key:
        raise RuntimeError(
            'Las variables de entorno AES_KEY y HMAC_KEY son obligatorias.'
        )
    qr_token = build_qr_token(qr_data, aes_key, hmac_key)

    # ── Render ────────────────────────────────────────────────────────────────
    if not _HAS_PIL:
        raise RuntimeError('Pillow not installed. Run: pip install pillow')

    img = render_label(payload, qr_token)

    # ── Print ─────────────────────────────────────────────────────────────────
    if args.do_print:
        _, print_offset = _load_layout()
        try:
            import win32print  # type: ignore
            printer = args.printer or win32print.GetDefaultPrinter()
        except Exception:
            printer = args.printer or 'Xprinter XP-365B #2'

        tspl = _img_to_tspl(img,
                             copies=1,
                             offset_x=print_offset.get('x', 0.0),
                             offset_y=print_offset.get('y', 0.0))
        _send_to_printer(tspl, printer)

    print(json.dumps({'ok': True, 'qr_token': qr_token}, ensure_ascii=False))


if __name__ == '__main__':
    try:
        main()
    except Exception as exc:
        print(json.dumps({'ok': False, 'error': str(exc)}), file=sys.stderr)
        sys.exit(1)
