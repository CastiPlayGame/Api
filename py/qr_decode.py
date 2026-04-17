#!/usr/bin/env python3
"""
qr_decode.py – decode QR codes from image file.

Usage:
    python qr_decode.py <image_path>

Returns JSON to stdout: {"found": true/false, "data": "content"/null}
"""
import json
import sys
from pathlib import Path

# Bootstrap sys.path for cv2
def _bootstrap():
    import site
    pkgs = site.getusersitepackages()
    base = Path(pkgs)
    for sub in [base, base/'cv2']:
        s = str(sub)
        if sub.exists() and s not in sys.path:
            sys.path.insert(0, s)
_bootstrap()

try:
    import cv2
    from pyzbar.pyzbar import decode as zbar_decode
    _HAS_LIBS = True
except ImportError:
    _HAS_LIBS = False


def decode_qr(image_path: str) -> dict:
    if not _HAS_LIBS:
        return {"found": False, "error": "OpenCV or pyzbar not installed", "data": None}

    img = cv2.imread(image_path)
    if img is None:
        return {"found": False, "error": "Could not load image", "data": None}

    # Try to decode QR codes
    codes = zbar_decode(img)
    for code in codes:
        if code.type == 'QRCODE':
            return {"found": True, "data": code.data.decode('utf-8', errors='replace')}

    return {"found": False, "data": None}


def main():
    if len(sys.argv) < 2:
        print(json.dumps({"error": "Usage: python qr_decode.py <image_path>"}), file=sys.stderr)
        sys.exit(1)

    image_path = sys.argv[1]
    result = decode_qr(image_path)
    print(json.dumps(result, ensure_ascii=False))


if __name__ == "__main__":
    main()
