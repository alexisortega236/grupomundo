import math
import os
import requests
from typing import Dict, Any, Tuple, Optional

OVERPASS_ENDPOINTS = [
    endpoint.strip()
    for endpoint in os.getenv("OVERPASS_ENDPOINTS", "https://overpass-api.de/api/interpreter").split(",")
    if endpoint.strip()
]
OVERPASS_TIMEOUT = int(os.getenv("OVERPASS_TIMEOUT", "15"))
OVERPASS_RETRIES = int(os.getenv("OVERPASS_RETRIES", "1"))

class PoiProviderUnavailable(Exception):
    pass

CATEGORY_QUERIES = {
    "schools": [
        'node["amenity"="school"](around:{radius},{lat},{lng});',
        'way["amenity"="school"](around:{radius},{lat},{lng});',
    ],
    "hospitals": [
        'node["amenity"="hospital"](around:{radius},{lat},{lng});',
        'way["amenity"="hospital"](around:{radius},{lat},{lng});',
    ],
    "parks": [
        'node["leisure"="park"](around:{radius},{lat},{lng});',
        'way["leisure"="park"](around:{radius},{lat},{lng});',
    ],
    "supermarkets": [
        'node["shop"="supermarket"](around:{radius},{lat},{lng});',
        'way["shop"="supermarket"](around:{radius},{lat},{lng});',
    ],
    "bus_stops": [
        'node["highway"="bus_stop"](around:{radius},{lat},{lng});',
        'node["public_transport"="platform"](around:{radius},{lat},{lng});',
        'node["public_transport"="stop_position"](around:{radius},{lat},{lng});',
    ],
}

def _build_query(lat: float, lng: float, radius_m: int) -> str:
    parts = []
    for qlist in CATEGORY_QUERIES.values():
        for q in qlist:
            parts.append(q.format(radius=radius_m, lat=lat, lng=lng))

    return f"""
[out:json][timeout:25];
(
  {' '.join(parts)}
);
out center;
"""

def _haversine_m(lat1: float, lon1: float, lat2: float, lon2: float) -> float:
    # Distancia en metros entre dos puntos (lat/lng) usando haversine
    R = 6371000.0
    phi1 = math.radians(lat1)
    phi2 = math.radians(lat2)
    dphi = math.radians(lat2 - lat1)
    dlmb = math.radians(lon2 - lon1)

    a = math.sin(dphi / 2) ** 2 + math.cos(phi1) * math.cos(phi2) * math.sin(dlmb / 2) ** 2
    c = 2 * math.atan2(math.sqrt(a), math.sqrt(1 - a))
    return R * c

def _get_element_latlng(el: Dict[str, Any]) -> Optional[Tuple[float, float]]:
    # node -> lat/lon directos
    if "lat" in el and "lon" in el:
        return float(el["lat"]), float(el["lon"])

    # way -> viene como {"center": {"lat":..., "lon":...}}
    center = el.get("center")
    if isinstance(center, dict) and "lat" in center and "lon" in center:
        return float(center["lat"]), float(center["lon"])

    return None

def _classify(tags: Dict[str, Any]) -> Optional[str]:
    # Regresa la categoría o None si no aplica
    if tags.get("amenity") == "school":
        return "schools"
    if tags.get("amenity") == "hospital":
        return "hospitals"
    if tags.get("leisure") == "park":
        return "parks"
    if tags.get("shop") == "supermarket":
        return "supermarkets"
    if tags.get("highway") == "bus_stop" or tags.get("public_transport") in ("platform", "stop_position"):
        return "bus_stops"
    return None

def _kind(tags: Dict[str, Any]) -> str:
    # Etiqueta "tipo" legible para el front
    # Ej: amenity=school, leisure=park, shop=supermarket, highway=bus_stop, public_transport=platform
    for k in ("amenity", "leisure", "shop", "highway", "public_transport"):
        if tags.get(k):
            return f"{k}={tags.get(k)}"
    return "unknown"

def _best_name(tags: Dict[str, Any], fallback_label: str) -> str:
    """
    Devuelve un nombre "presentable" aunque OSM no tenga tags.name.
    Orden de preferencia:
      name -> name:es -> brand -> operator -> ref -> dirección -> fallback_label
    """
    for k in ("name", "name:es"):
        v = (tags.get(k) or "").strip()
        if v:
            return v

    brand = (tags.get("brand") or "").strip()
    if brand:
        return brand

    operator = (tags.get("operator") or "").strip()
    if operator:
        return operator

    ref = (tags.get("ref") or "").strip()
    if ref:
        return f"{fallback_label} {ref}"

    addr = _address(tags)
    if addr:
        return addr

    return fallback_label    

def _label_for_category(cat: str) -> str:
    return {
        "schools": "Escuela",
        "hospitals": "Hospital",
        "parks": "Parque",
        "supermarkets": "Supermercado",
        "bus_stops": "Transporte",
    }.get(cat, "POI")

def _address(tags: Dict[str, Any]) -> Optional[str]:
    # Arma dirección si existe
    street = tags.get("addr:street")
    housenumber = tags.get("addr:housenumber")
    city = tags.get("addr:city")
    state = tags.get("addr:state")

    parts = []
    if street:
        if housenumber:
            parts.append(f"{street} #{housenumber}")
        else:
            parts.append(str(street))
    if city:
        parts.append(str(city))
    if state:
        parts.append(str(state))

    if not parts:
        return None
    return ", ".join(parts)

def fetch_pois_enriched(lat: float, lng: float, radius_m: int = 1000, top_n: int = 5) -> Dict[str, Any]:
    """
    Devuelve:
      - counts: conteos por categoría
      - nearest_m: distancia al POI más cercano por categoría (o None)
      - details: lista top_n por categoría (más cercanos)
    """
    query = _build_query(lat, lng, radius_m)

    headers = {"User-Agent": "valuador-mvp/1.0 (contact: gonzalezalexis236@gmail.com)"}
    last_error = None
    attempts = max(1, OVERPASS_RETRIES + 1)

    for endpoint in OVERPASS_ENDPOINTS:
        for _ in range(attempts):
            try:
                r = requests.post(endpoint, data=query.encode("utf-8"), headers=headers, timeout=OVERPASS_TIMEOUT)
                if r.status_code in (429,) or 500 <= r.status_code <= 599:
                    last_error = f"Overpass HTTP {r.status_code}"
                    continue
                r.raise_for_status()
                data = r.json()
                break
            except (requests.Timeout, requests.ConnectionError) as exc:
                last_error = str(exc)
                continue
            except requests.RequestException as exc:
                last_error = str(exc)
                continue
        else:
            continue
        break
    else:
        raise PoiProviderUnavailable(last_error or "Overpass no disponible")

    counts: Dict[str, int] = {k: 0 for k in CATEGORY_QUERIES.keys()}
    buckets: Dict[str, list] = {k: [] for k in CATEGORY_QUERIES.keys()}

    for el in data.get("elements", []):
        tags = el.get("tags", {}) or {}
        category = _classify(tags)
        if not category:
            continue

        pos = _get_element_latlng(el)
        if not pos:
            continue

        poi_lat, poi_lng = pos
        dist_m = _haversine_m(lat, lng, poi_lat, poi_lng)

        counts[category] += 1
        buckets[category].append({
            "name": _best_name(tags, _label_for_category(category)),
            "kind": _kind(tags),
            "distance_m": int(round(dist_m)),
            "lat": poi_lat,
            "lng": poi_lng,
            "address": _address(tags),
        })

    # Ordenar por distancia y recortar top_n
    details: Dict[str, list] = {}
    nearest_m: Dict[str, Optional[int]] = {}

    for cat, items in buckets.items():
        items_sorted = sorted(items, key=lambda x: x["distance_m"])
        details[cat] = items_sorted[:top_n]
        nearest_m[cat] = items_sorted[0]["distance_m"] if items_sorted else None

    return {
        "radius_m": radius_m,
        "counts": counts,
        "nearest_m": nearest_m,
        "details": details
    }

def fetch_pois_counts(lat: float, lng: float, radius_m: int = 1000) -> Dict[str, int]:
    """
    Compatibilidad con tu versión anterior: solo conteos.
    """
    enriched = fetch_pois_enriched(lat, lng, radius_m=radius_m, top_n=0)
    return enriched["counts"]
