from datetime import datetime
from typing import Dict, Any, Optional
import requests

def _parse_min_date_ddmmyyyy(s: str):
    return datetime.strptime(s, "%d/%m/%Y").date()

def _parse_key_date(s: str):
    # Espera 'YYYY-MM-DD'
    return datetime.strptime(s, "%Y-%m-%d").date()

def _compute_total(inv: Any) -> int:
    if not isinstance(inv, dict):
        return 0
    pcs = int(inv.get("Pcs") or 0)
    packets = inv.get("Packets") or {}
    total_packets = 0
    if isinstance(packets, dict):
        for k, v in packets.items():
            try:
                size = int(k)
                qty = int(v)
            except (ValueError, TypeError):
                continue
            total_packets += size * qty
    return pcs + total_packets

def aggregate_inventory_diffs_by_id(
    data: Dict[str, Any],
    min_date_str: str,
    item_filter: Optional[str] = None,
) -> Dict[str, Dict[str, Any]]:
    """
    data: { 'YYYY-MM-DD': [ { 'type': 'log.inv.item', 'id': 'GC-005', 'old': {...}, 'new': {...} }, ... ], ... }
    min_date_str: 'dd/mm/yyyy'  (ej: '11/08/2025')
    return: { id: { "total": int, "timestamp": str | None } }
    """
    min_date = _parse_min_date_ddmmyyyy(min_date_str)
    result: Dict[str, Dict[str, Any]] = {}

    for date_key, logs in data.items():
        try:
            day = _parse_key_date(date_key)
        except ValueError:
            continue
        if day < min_date:
            continue

        if not isinstance(logs, list):
            continue

        for log in logs:
            if not isinstance(log, dict):
                continue
            if log.get("type") != "log.inv.item":
                continue

            item_id = str(log.get("id") or "")
            if item_filter and item_id != item_filter:
                continue

            if not item_id:
                continue

            old_total = _compute_total(log.get("old"))
            new_total = _compute_total(log.get("new"))
            diff = new_total - old_total  # positivo=entrada, negativo=salida
            # acumular total general
            entry = result.get(item_id, {"total": 0, "timestamp": None, "history_map": {}})
            entry["total"] = int(entry.get("total", 0)) + int(diff)
            entry["timestamp"] = log.get("ttmp")

            # acumular por fecha en history_map
            history_map: Dict[str, Dict[str, Any]] = entry.get("history_map", {})
            per_day = history_map.get(date_key, {"total": 0, "timestamp": None})
            per_day["total"] = int(per_day.get("total", 0)) + int(diff)
            per_day["timestamp"] = log.get("ttmp")
            history_map[date_key] = per_day
            entry["history_map"] = history_map

            result[item_id] = entry

    # convertir history_map a lista ordenada por fecha
    for item_id, entry in result.items():
        history_map = entry.pop("history_map", {})
        history_list = [
            {"date": d, "total": v.get("total", 0), "timestamp": v.get("timestamp")}
            for d, v in sorted(history_map.items())
        ]
        entry["history"] = history_list

    return result

def format_result(result: Dict[str, Dict[str, Any]]) -> str:
    # Devuelve líneas: id : total [timestamp]\n  - date : total [timestamp]
    lines: list[str] = []
    for k, v in result.items():
        total = v.get("total", 0)
        ts = v.get("timestamp")
        suffix = f" [{ts}]" if ts else ""
        lines.append(f"{k} : {total}{suffix}")
        history = v.get("history", [])
        for h in history:
            hts = f" [{h.get('timestamp')}]" if h.get("timestamp") else ""
            lines.append(f"  - {h.get('date')} : {h.get('total')}{hts}")
    return "\n".join(lines)

def fetch_and_aggregate(
    min_date_str: str,
    token: str,
    base_url: str = "http://multipartes.ddnsfree.com:2045/newApi",
    item_filter: Optional[str] = None,
) -> Dict[str, Dict[str, Any]]:
    url = f"{base_url}/logs/inv"
    headers = {
        "Authorization": f"Bearer {token}",
        "Accept": "application/json",
    }

    resp = requests.get(url, headers=headers, timeout=30)
    resp.raise_for_status()
    data = resp.json()
    logs = data.get("logs", {}) if isinstance(data, dict) else {}

    return aggregate_inventory_diffs_by_id(logs, min_date_str, item_filter=item_filter)


# Ejecución directa
if __name__ == "__main__":
    TOKEN = "NS20gEo80zV6F3WoxFOR5UKgztqilJ63"
    MIN_DATE = "28/07/2025"  # Cambia aquí la fecha mínima (dd/mm/yyyy)
    ITEM_FILTER = None  # Ej: "GS-049" para filtrar por un id específico

    aggregated = fetch_and_aggregate(MIN_DATE, TOKEN, item_filter=ITEM_FILTER)
    if not aggregated:
        print("Sin datos para la fecha mínima dada o sin cambios de inventario.")
    else:
        print(format_result(aggregated))