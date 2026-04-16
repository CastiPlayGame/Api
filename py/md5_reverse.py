#!/usr/bin/env python3
"""
md5_reverse.py – recover original number from MD5[:6] hash via brute force.

Usage:
    python md5_reverse.py <hash_hex>

Returns JSON to stdout: {"found": true/false, "number": int/null}
"""
import hashlib
import json
import sys


def reverse_md5(hash_hex: str, max_search: int = 999999) -> dict:
    """Search for number (1..max_search) whose MD5 starts with hash_hex."""
    target = hash_hex.upper()
    for i in range(1, max_search + 1):
        if hashlib.md5(str(i).encode()).hexdigest().upper().startswith(target):
            return {"found": True, "number": i}
    return {"found": False, "number": None}


def main():
    if len(sys.argv) < 2:
        print(json.dumps({"error": "Usage: python md5_reverse.py <hash_hex>"}), file=sys.stderr)
        sys.exit(1)

    hash_hex = sys.argv[1].strip().upper()
    result = reverse_md5(hash_hex)
    print(json.dumps(result, ensure_ascii=False))


if __name__ == "__main__":
    main()
