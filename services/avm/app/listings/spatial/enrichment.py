from __future__ import annotations

import csv
import math
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import pandas as pd

try:
    import shapefile
    from pyproj import Transformer
    from shapely.geometry import Point, shape
    from shapely.prepared import prep
except ImportError:  # pragma: no cover - exercised by runtime validation
    shapefile = None
    Transformer = None
    Point = None
    shape = None
    prep = None

from app.listings.spatial.datasets import DatasetPaths


DENUE_CATEGORIES = {
    "retail": ("46",),
    "restaurants_hotels": ("72",),
    "health": ("62",),
    "education": ("61",),
    "financial": ("52",),
    "professional_services": ("54",),
}


@dataclass
class AgebMatch:
    cve_ent: str | None = None
    cve_mun: str | None = None
    cve_loc: str | None = None
    cve_ageb: str | None = None
    cve_mza: str | None = None
    municipality: str | None = None
    locality: str | None = None
    area_km2: float | None = None


class InegiSpatialIndex:
    def __init__(self, paths: DatasetPaths):
        _require_geo_dependencies()
        self.paths = paths
        self.transformer = Transformer.from_crs("EPSG:4326", _read_prj(paths.ageb_shp), always_xy=True)
        self.agebs = self._load_polygons(paths.ageb_shp)
        self.municipalities = self._load_polygons(paths.municipality_shp)
        self.localities = self._load_polygons(paths.locality_shp)
        self.blocks = self._load_polygons(paths.block_shp) if paths.block_shp.exists() else []

    def match(self, latitude: float, longitude: float) -> AgebMatch:
        point = Point(*self.transformer.transform(longitude, latitude))
        ageb_record = self._find(self.agebs, point)
        if not ageb_record:
            return AgebMatch()
        attrs = ageb_record["attrs"]
        municipality_record = self._find(self.municipalities, point)
        locality_record = self._find(self.localities, point)
        block_record = self._find(self.blocks, point) if self.blocks else None
        return AgebMatch(
            cve_ent=_get(attrs, "CVE_ENT", "ENTIDAD"),
            cve_mun=_get(attrs, "CVE_MUN", "MUN"),
            cve_loc=_get(attrs, "CVE_LOC", "LOC"),
            cve_ageb=_get(attrs, "CVE_AGEB", "AGEB"),
            cve_mza=_get(block_record["attrs"], "CVE_MZA", "MZA") if block_record else None,
            municipality=_get(municipality_record["attrs"], "NOMGEO", "NOM_MUN") if municipality_record else None,
            locality=_get(locality_record["attrs"], "NOMGEO", "NOM_LOC") if locality_record else None,
            area_km2=ageb_record["geometry"].area / 1_000_000,
        )

    def _load_polygons(self, shp_path: Path) -> list[dict[str, Any]]:
        reader = shapefile.Reader(str(shp_path), encoding=_shape_encoding(shp_path), encodingErrors="replace")
        fields = [field[0] for field in reader.fields[1:]]
        records = []
        for shape_record in reader.iterShapeRecords():
            geom = shape(shape_record.shape.__geo_interface__)
            attrs = dict(zip(fields, shape_record.record))
            records.append({"geometry": geom, "prepared": prep(geom), "attrs": attrs})
        return records

    def _find(self, records: list[dict[str, Any]], point) -> dict[str, Any] | None:
        for record in records:
            if record["prepared"].covers(point):
                return record
        return None


class CensoRepository:
    def __init__(self, csv_path: Path):
        self.by_key = {}
        with csv_path.open("r", encoding="utf-8-sig", newline="") as handle:
            reader = csv.DictReader(handle)
            for row in reader:
                if row.get("MZA") != "000":
                    continue
                key = (_clean_code(row.get("ENTIDAD"), 2), _clean_code(row.get("MUN"), 3), _clean_code(row.get("LOC"), 4), _clean_code(row.get("AGEB"), 4))
                self.by_key[key] = row

    def features(self, match: AgebMatch) -> dict[str, float | int | None]:
        row = self.by_key.get((match.cve_ent, match.cve_mun, match.cve_loc, match.cve_ageb))
        if not row:
            return {}
        population = _num(row.get("POBTOT"))
        occupied_housing = _num(row.get("TVIVHAB"))
        pea = _num(row.get("PEA"))
        occupied = _num(row.get("POCUPADA"))
        return {
            "population": population,
            "occupied_housing": occupied_housing,
            "population_density": _safe_div(population, match.area_km2),
            "housing_density": _safe_div(occupied_housing, match.area_km2),
            "car_ownership_ratio": _safe_div(_num(row.get("VPH_AUTOM")), occupied_housing),
            "internet_access_ratio": _safe_div(_num(row.get("VPH_INTER")), occupied_housing),
            "average_schooling": _num(row.get("GRAPROES")),
            "employment_ratio": _safe_div(occupied, pea),
        }


class DenueIndex:
    def __init__(self, csv_path: Path):
        usecols = ["codigo_act", "latitud", "longitud"]
        self.frame = pd.read_csv(csv_path, encoding="latin-1", usecols=usecols)
        self.frame = self.frame.dropna(subset=["latitud", "longitud"])
        self.frame["codigo_act"] = self.frame["codigo_act"].astype(str)

    def counts(self, latitude: float, longitude: float) -> dict[str, int]:
        distances = self.frame.apply(lambda row: haversine_m(latitude, longitude, row["latitud"], row["longitud"]), axis=1)
        result: dict[str, int] = {}
        for radius in (500, 1000):
            within = self.frame[distances <= radius]
            suffix = "500m" if radius == 500 else "1km"
            result[f"establishments_{suffix}"] = int(len(within))
            for label, prefixes in DENUE_CATEGORIES.items():
                result[f"{label}_{suffix}"] = int(within["codigo_act"].str.startswith(prefixes).sum())
        return result


def haversine_m(lat1: float, lon1: float, lat2: float, lon2: float) -> float:
    radius_m = 6371000
    phi1 = math.radians(lat1)
    phi2 = math.radians(lat2)
    dphi = math.radians(lat2 - lat1)
    dlambda = math.radians(lon2 - lon1)
    a = math.sin(dphi / 2) ** 2 + math.cos(phi1) * math.cos(phi2) * math.sin(dlambda / 2) ** 2
    return 2 * radius_m * math.atan2(math.sqrt(a), math.sqrt(1 - a))


def _read_prj(shp_path: Path) -> str:
    prj_path = shp_path.with_suffix(".prj")
    return prj_path.read_text(encoding="utf-8", errors="ignore")


def _shape_encoding(shp_path: Path) -> str:
    cpg_path = shp_path.with_suffix(".cpg")
    if cpg_path.exists():
        value = cpg_path.read_text(encoding="ascii", errors="ignore").strip()
        if value:
            if value.upper() in ("ANSI 1252", "WINDOWS-1252"):
                return "cp1252"
            if value.upper().replace("-", "").replace("_", "").replace(" ", "") in ("ISO88591", "ISO8859"):
                return "iso-8859-1"
            return value
    return "latin-1"


def _require_geo_dependencies() -> None:
    missing = []
    if shapefile is None:
        missing.append("pyshp")
    if Transformer is None:
        missing.append("pyproj")
    if shape is None:
        missing.append("shapely")
    if missing:
        raise RuntimeError(f"Faltan dependencias geoespaciales: {', '.join(missing)}")


def _get(attrs: dict[str, Any], *keys: str) -> str | None:
    if not attrs:
        return None
    lowered = {str(key).lower(): value for key, value in attrs.items()}
    for key in keys:
        value = lowered.get(key.lower())
        if value not in (None, ""):
            return str(value).strip()
    return None


def _clean_code(value: object, width: int) -> str | None:
    if value in (None, ""):
        return None
    return str(value).strip().zfill(width)


def _num(value: object) -> float | None:
    if value in (None, "", "*", "N/D"):
        return None
    try:
        return float(value)
    except (TypeError, ValueError):
        return None


def _safe_div(numerator: float | None, denominator: float | None) -> float | None:
    if numerator is None or denominator in (None, 0):
        return None
    return numerator / denominator
