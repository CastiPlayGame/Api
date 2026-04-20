#!/usr/bin/env python3
"""
qr_decode.py – decode QR codes from image file, any angle.
Usage: python qr_decode.py <image_path>
Returns JSON: {"found": true/false, "data": "content"|null}
"""
import warnings
warnings.filterwarnings('ignore')
import os
os.environ['PYTHONWARNINGS'] = 'ignore'
import sys
import json
import numpy as np
import cv2

try:
    from pyzbar.pyzbar import decode as pyzbar_decode
    _HAS_PYZBAR = True
except ImportError:
    _HAS_PYZBAR = False


def _try_pyzbar(img):
    """Try pyzbar on image, return data string or None."""
    if not _HAS_PYZBAR:
        return None
    for code in pyzbar_decode(img):
        if code.type == 'QRCODE':
            return code.data.decode('utf-8', errors='replace')
    return None


def _rotate(img, angle):
    """Rotate image by angle degrees around center."""
    h, w = img.shape[:2]
    M = cv2.getRotationMatrix2D((w / 2, h / 2), angle, 1)
    return cv2.warpAffine(img, M, (w, h), flags=cv2.INTER_LINEAR,
                          borderMode=cv2.BORDER_REPLICATE)


def decode_qr(image_path: str) -> dict:
    # Use imdecode instead of imread so file extension is not required
    # (PHP upload temp files have no extension)
    try:
        with open(image_path, 'rb') as f:
            raw = np.frombuffer(f.read(), dtype=np.uint8)
        img = cv2.imdecode(raw, cv2.IMREAD_COLOR)
    except Exception as e:
        return {"found": False, "error": f"Could not read file: {e}", "data": None}
    if img is None:
        return {"found": False, "error": "Could not decode image", "data": None}

    # ── 1. OpenCV built-in QRCodeDetector (rotation-invariant) ─────────────
    detector = cv2.QRCodeDetector()
    try:
        ok, decoded_list, _, _ = detector.detectAndDecodeMulti(img)
        if ok:
            for text in decoded_list:
                if text:
                    return {"found": True, "data": text}
    except Exception:
        pass

    # ── 2. pyzbar on original ───────────────────────────────────────────────
    data = _try_pyzbar(img)
    if data:
        return {"found": True, "data": data}

    # ── 3. pyzbar with 90° rotations ────────────────────────────────────────
    for angle in (90, 180, 270):
        data = _try_pyzbar(_rotate(img, angle))
        if data:
            return {"found": True, "data": data}

    # ── 4. pyzbar with fine-angle rotations (for slightly tilted labels) ────
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    for angle in range(-30, 31, 5):
        if angle == 0:
            continue
        rotated = _rotate(gray, angle)
        data = _try_pyzbar(rotated)
        if data:
            return {"found": True, "data": data}

    # ── 5. Preprocessed grayscale (high contrast) ───────────────────────────
    clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
    enhanced = clahe.apply(gray)
    data = _try_pyzbar(enhanced)
    if data:
        return {"found": True, "data": data}

    return {"found": False, "data": None}


if __name__ == "__main__":
    if len(sys.argv) < 2:
        print(json.dumps({"error": "Usage: python qr_decode.py <image_path>", "found": False, "data": None}))
        sys.exit(1)

    result = decode_qr(sys.argv[1])
    print(json.dumps(result, ensure_ascii=False))