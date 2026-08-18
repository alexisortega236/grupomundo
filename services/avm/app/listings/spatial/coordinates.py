from __future__ import annotations

import math


def haversine_m(lat1: float, lon1: float, lat2: float, lon2: float) -> float:
    radius_m = 6371000
    phi1 = math.radians(lat1)
    phi2 = math.radians(lat2)
    dphi = math.radians(lat2 - lat1)
    dlambda = math.radians(lon2 - lon1)
    a = math.sin(dphi / 2) ** 2 + math.cos(phi1) * math.cos(phi2) * math.sin(dlambda / 2) ** 2
    return 2 * radius_m * math.atan2(math.sqrt(a), math.sqrt(1 - a))


def coordinate_hash(latitude: float, longitude: float, decimals: int = 6) -> str:
    return f"{round(float(latitude), decimals)}:{round(float(longitude), decimals)}"


def valid_morelos_coordinate(latitude: float | None, longitude: float | None) -> bool:
    if latitude is None or longitude is None:
        return False
    return 18.0 <= float(latitude) <= 19.2 and -99.6 <= float(longitude) <= -98.4

