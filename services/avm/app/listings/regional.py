"""Regional model and spatial-data registry for AVM residential inference."""

from __future__ import annotations

import csv
import math
import os
from dataclasses import dataclass
from pathlib import Path

import joblib
import numpy as np
import pandas as pd
from pyproj import Transformer
from scipy.spatial import cKDTree
from shapely.geometry import Point, shape
from shapely.strtree import STRtree

import shapefile

from app.listings.spatial.datasets import dataset_paths
from app.listings.spatial.enrichment import AgebMatch, CensoRepository, DenueIndex, InegiSpatialIndex, _clean_code, _read_prj, _shape_encoding


AVM_ROOT = Path(__file__).resolve().parents[2]
CDMX_RUNTIME = AVM_ROOT / "runtime_data" / "cdmx_v1"
CDMX_MODEL = CDMX_RUNTIME / "model_best_experimental.joblib"
CDMX_VERSION = "avm_cdmx_v1_experimental"
MORELOS_VERSION = "avm_residential_v2_v2_experimental"

CDMX_CENSO_FEATURES = ["population_density", "housing_density", "car_ownership_ratio", "internet_access_ratio", "average_schooling", "employment_ratio"]
CDMX_DENUE_FEATURES = ["retail_500m", "retail_1km", "restaurants_hotels_500m", "restaurants_hotels_1km", "health_500m", "health_1km", "education_500m", "education_1km", "financial_500m", "financial_1km", "professional_services_500m", "professional_services_1km"]
CDMX_NUMERIC = ["land_area_m2", "construction_area_m2", "bedrooms", "bathrooms", "parking_spaces", *CDMX_CENSO_FEATURES, *CDMX_DENUE_FEATURES]


@dataclass(frozen=True)
class RegionalModelSpec:
    entity_code: str
    model_id: str
    version: str
    artifact_path: Path
    target_transform: str
    observed_municipalities: tuple[str, ...]


@dataclass
class RegionalContext:
    spec: RegionalModelSpec
    match: AgebMatch
    censo_values: dict
    denue_values: dict


class RegionalModelRegistry:
    def __init__(self):
        paths = dataset_paths()
        morelos_path = _configured_path("RESIDENTIAL_V2_MODEL_PATH", AVM_ROOT / "experiments/avm_v2_v2/model_residential_experimental.joblib")
        self.specs = {
            "09": RegionalModelSpec("09", "avm_cdmx_v1", CDMX_VERSION, CDMX_MODEL, "log1p_price", ("Azcapotzalco", "Benito Juárez", "Coyoacán", "Cuajimalpa de Morelos", "Cuauhtémoc", "Gustavo A. Madero", "Iztacalco", "Iztapalapa", "La Magdalena Contreras", "Miguel Hidalgo", "Milpa Alta", "Tláhuac", "Tlalpan", "Venustiano Carranza", "Xochimilco")),
            "17": RegionalModelSpec("17", "avm_residential_v2", MORELOS_VERSION, morelos_path, "log1p_price", ()),
        }
        self.models = {}
        self._validate_and_load("09")
        self._validate_and_load("17")
        self.providers = {"09": CdmxDataProvider(CDMX_RUNTIME), "17": MorelosDataProvider(paths)}

    def _validate_and_load(self, entity_code: str):
        spec = self.specs[entity_code]
        if not spec.artifact_path.exists():
            if entity_code == "09":
                raise RuntimeError(f"CDMX model artifact not found: {spec.artifact_path}")
            self.models[entity_code] = None
            return
        model = joblib.load(spec.artifact_path)
        numeric, categorical = pipeline_feature_contract(model)
        if entity_code == "09":
            expected_numeric = CDMX_NUMERIC
            expected_categorical = ["property_type", "inegi_cve_ageb"]
        else:
            expected_numeric = ["land_area_m2", "construction_area_m2", "bedrooms", "bathrooms", "parking_spaces", *CDMX_CENSO_FEATURES, "establishments_500m", "establishments_1km", *CDMX_DENUE_FEATURES]
            expected_categorical = ["property_type", "municipality", "inegi_cve_ageb"]
        if numeric != expected_numeric or categorical != expected_categorical:
            raise RuntimeError(f"Feature contract mismatch for entity {entity_code}: numeric={numeric}, categorical={categorical}")
        self.models[entity_code] = model

    def model_for_entity(self, entity_code: str) -> tuple[RegionalModelSpec, object]:
        spec = self.specs.get(_entity_code(entity_code))
        if spec is None or self.models.get(spec.entity_code) is None:
            raise KeyError(f"No AVM model configured for entity {entity_code}")
        return spec, self.models[spec.entity_code]

    def resolve_context(self, latitude: float, longitude: float) -> RegionalContext | None:
        # CDMX is checked first because its southern boundary is close to Morelos.
        for entity_code in ("09", "17"):
            provider = self.providers[entity_code]
            match = provider.match(latitude, longitude)
            if match.cve_ageb:
                spec = self.specs[entity_code]
                return RegionalContext(spec, match, provider.censo.features(match), provider.denue.counts(latitude, longitude))
        return None

    def predict(self, context: RegionalContext, row: dict) -> float:
        spec, model = self.model_for_entity(context.spec.entity_code)
        numeric, categorical = pipeline_feature_contract(model)
        missing = [key for key in [*numeric, *categorical] if key not in row]
        if missing:
            raise ValueError(f"Runtime feature mismatch for {spec.model_id}: missing {missing}")
        frame = pd.DataFrame([row])
        raw = float(model.predict(frame)[0])
        return float(max(1, np.expm1(raw) if spec.target_transform == "log1p_price" else raw))


class MorelosDataProvider:
    def __init__(self, paths):
        self.spatial = InegiSpatialIndex(paths)
        self.censo = CensoRepository(paths.censo_ageb_csv)
        self.denue = DenueIndex(paths.denue_csv)

    def match(self, latitude, longitude):
        return self.spatial.match(latitude, longitude)


class CdmxDataProvider:
    def __init__(self, root: Path):
        self.spatial = CdmxSpatialIndex(root / "09a.shp", root / "censo_ageb_features.csv")
        self.censo = CensoRepository(root / "censo_ageb_features.csv")
        self.denue = CdmxDenueIndex(root / "denue_points.csv", self.spatial.transformer)

    def match(self, latitude, longitude):
        return self.spatial.match(latitude, longitude)


class CdmxSpatialIndex:
    def __init__(self, ageb_path: Path, censo_path: Path):
        self.transformer = Transformer.from_crs("EPSG:4326", _read_prj(ageb_path), always_xy=True)
        reader = shapefile.Reader(str(ageb_path), encoding=_shape_encoding(ageb_path), encodingErrors="replace")
        fields = [field[0] for field in reader.fields[1:]]
        self.geometries = []
        self.attrs = []
        for item in reader.iterShapeRecords():
            self.geometries.append(shape(item.shape.__geo_interface__))
            self.attrs.append(dict(zip(fields, item.record)))
        self.tree = STRtree(self.geometries)
        self.names = {}
        with censo_path.open("r", encoding="utf-8-sig", newline="") as handle:
            for row in csv.DictReader(handle):
                key = (_clean_code(row.get("ENTIDAD"), 2), _clean_code(row.get("MUN"), 3), _clean_code(row.get("LOC"), 4))
                self.names[key] = (row.get("NOM_MUN"), row.get("NOM_LOC"))

    def match(self, latitude: float, longitude: float) -> AgebMatch:
        x, y = self.transformer.transform(longitude, latitude)
        point = Point(x, y)
        for candidate in self.tree.query(point):
            index = int(candidate)
            if not self.geometries[index].covers(point):
                continue
            attrs = self.attrs[index]
            ent = _clean_code(_attr(attrs, "CVE_ENT", "ENTIDAD"), 2)
            mun = _clean_code(_attr(attrs, "CVE_MUN", "MUN"), 3)
            loc = _clean_code(_attr(attrs, "CVE_LOC", "LOC"), 4)
            ageb = _clean_code(_attr(attrs, "CVE_AGEB", "AGEB"), 4)
            municipality, locality = self.names.get((ent, mun, loc), (None, None))
            return AgebMatch(cve_ent=ent, cve_mun=mun, cve_loc=loc, cve_ageb=ageb, municipality=municipality, locality=locality, area_km2=self.geometries[index].area / 1_000_000)
        return AgebMatch()


class CdmxDenueIndex:
    def __init__(self, path: Path, transformer: Transformer):
        frame = pd.read_csv(path, encoding="utf-8", usecols=["codigo_act", "latitud", "longitud"], dtype={"codigo_act": str})
        frame["latitud"] = pd.to_numeric(frame["latitud"], errors="coerce")
        frame["longitud"] = pd.to_numeric(frame["longitud"], errors="coerce")
        frame = frame.dropna(subset=["latitud", "longitud"])
        x, y = transformer.transform(frame["longitud"].to_numpy(), frame["latitud"].to_numpy())
        self.codes = frame["codigo_act"].fillna("").astype(str).to_numpy()
        self.tree = cKDTree(np.column_stack([x, y]))
        self.transformer = transformer

    def counts(self, latitude: float, longitude: float) -> dict[str, int]:
        x, y = self.transformer.transform(longitude, latitude)
        result = {}
        for radius in (500, 1000):
            indices = self.tree.query_ball_point([x, y], radius)
            codes = self.codes[indices]
            suffix = "500m" if radius == 500 else "1km"
            result[f"establishments_{suffix}"] = int(len(indices))
            for label, prefixes in {"retail": ("46",), "restaurants_hotels": ("72",), "health": ("62",), "education": ("61",), "financial": ("52",), "professional_services": ("54",)}.items():
                result[f"{label}_{suffix}"] = int(sum(code.startswith(prefixes) for code in codes))
        return result


def pipeline_feature_contract(model) -> tuple[list[str], list[str]]:
    preprocess = getattr(model, "named_steps", {}).get("preprocess")
    if preprocess is None:
        raise RuntimeError("Model does not contain expected preprocess step")
    numeric = []
    categorical = []
    for name, _, columns in preprocess.transformers_:
        if name == "num":
            numeric = list(columns)
        elif name == "cat":
            categorical = list(columns)
    return numeric, categorical


def _configured_path(name: str, default: Path) -> Path:
    value = os.getenv(name)
    return Path(value).expanduser().resolve() if value else default


def _entity_code(value: object) -> str:
    return str(value or "").strip().zfill(2)


def _attr(attrs: dict, *keys):
    lowered = {str(key).lower(): value for key, value in attrs.items()}
    for key in keys:
        value = lowered.get(key.lower())
        if value not in (None, ""):
            return value
    return None
