import importlib
import os
import tempfile
import unittest
from contextlib import ExitStack
from types import SimpleNamespace
from unittest.mock import Mock, patch

import numpy as np
import requests

os.environ["POI_CACHE_DB"] = os.path.join(tempfile.gettempdir(), "avm_test_poi_cache.sqlite3")
os.environ["OVERPASS_TIMEOUT"] = "1"
os.environ["OVERPASS_RETRIES"] = "0"

main = importlib.import_module("app.main")
pois_overpass = importlib.import_module("app.services.pois_overpass")
poi_cache = importlib.import_module("app.services.poi_cache_sqlite")


def poi_payload(schools=1, bus_stops=1):
    return {
        "counts": {
            "schools": schools,
            "hospitals": 0,
            "parks": 0,
            "supermarkets": 0,
            "bus_stops": bus_stops,
        },
        "nearest_m": {
            "schools": 120,
            "hospitals": None,
            "parks": None,
            "supermarkets": None,
            "bus_stops": 80,
        },
        "details": {
            "schools": [{"name": "Escuela", "distance_m": 120}],
            "hospitals": [],
            "parks": [],
            "supermarkets": [],
            "bus_stops": [{"name": "Transporte", "distance_m": 80}],
        },
    }


class AvmServiceTest(unittest.TestCase):
    def setUp(self):
        self.client = main.app.test_client()

    def test_model_loads(self):
        self.assertIsNotNone(main.pipe)
        self.assertEqual(
            list(main.pipe.feature_names_in_),
            [
                "tipo",
                "colonia",
                "zona",
                "factor_colonia",
                "cerca_transporte",
                "cerca_escuelas",
                "m2_terreno",
                "m2_construccion",
                "recamaras",
                "banos",
                "estacionamientos",
                "antiguedad_anios",
            ],
        )

    def test_health(self):
        response = self.client.get("/health")
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json["status"], "ok")
        self.assertTrue(response.json["model_loaded"])
        self.assertIn("legacy_model_loaded", response.json)
        self.assertIn("residential_v2_model_loaded", response.json)
        self.assertIn("avm_v2_v1_model_loaded", response.json)

    def test_avm_v2_v1_model_loads(self):
        self.assertIsNotNone(main.avm_v2_v1_pipe)

    def test_invalid_request_without_colonia(self):
        response = self.client.post("/predict", json={})
        self.assertEqual(response.status_code, 400)

    def test_missing_lat_lng(self):
        response = self.client.post("/predict", json={"tipo": "casa", "colonia": "COL_13"})
        self.assertEqual(response.status_code, 400)
        self.assertEqual(response.json["error"], "invalid_coordinates")

    def test_invalid_type(self):
        response = self.client.post("/predict", json={"tipo": "local", "colonia": "COL_13", "lat": 18.8, "lng": -98.9})
        self.assertEqual(response.status_code, 400)
        self.assertEqual(response.json["error"], "invalid_property_type")

    def test_invalid_colonia(self):
        response = self.client.post("/predict", json={"tipo": "casa", "colonia": "CENTRO", "lat": 18.8, "lng": -98.9})
        self.assertEqual(response.status_code, 400)

    @patch("app.services.pois_overpass.requests.post")
    def test_overpass_success(self, post):
        post.return_value = Mock(
            status_code=200,
            raise_for_status=Mock(),
            json=Mock(return_value={
                "elements": [
                    {"lat": 18.8124, "lon": -98.9555, "tags": {"amenity": "school", "name": "Escuela Demo"}},
                ]
            }),
        )

        result = pois_overpass.fetch_pois_enriched(18.8123, -98.9556)

        self.assertEqual(result["counts"]["schools"], 1)
        self.assertEqual(result["details"]["schools"][0]["name"], "Escuela Demo")

    @patch("app.services.pois_overpass.requests.post")
    def test_overpass_timeout(self, post):
        post.side_effect = requests.Timeout("timeout")

        with self.assertRaises(pois_overpass.PoiProviderUnavailable):
            pois_overpass.fetch_pois_enriched(18.8123, -98.9556)

    @patch("app.services.pois_overpass.requests.post")
    def test_overpass_504(self, post):
        post.return_value = Mock(status_code=504, text="Gateway Timeout")

        with self.assertRaises(pois_overpass.PoiProviderUnavailable):
            pois_overpass.fetch_pois_enriched(18.8123, -98.9556)

    @patch("app.main.fetch_pois_enriched")
    def test_cache_hit(self, fetch):
        key = main._grid_key(18.8123, -98.9556, main.POI_RADIUS_M)
        poi_cache.set(key, {
            "radius_m": 1000,
            "counts": poi_payload()["counts"],
            "nearest_m": poi_payload()["nearest_m"],
            "details": poi_payload()["details"],
            "cache_hit": False,
        })

        response = self.client.post("/predict", json=self.baseline_payload())

        self.assertEqual(response.status_code, 200)
        self.assertTrue(response.json["pois"]["cache_hit"])
        fetch.assert_not_called()

    @patch("app.main.fetch_pois_enriched")
    def test_prediction_with_mocked_provider(self, fetch):
        fetch.return_value = poi_payload()

        response = self.client.post("/predict", json={**self.baseline_payload(), "lat": 18.7, "lng": -98.7})

        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json["zona_inferida"], "media")
        self.assertEqual(response.json["features_derivadas"]["cerca_escuelas"], 1)

    @patch("app.main.fetch_pois_enriched")
    def test_baseline_regression(self, fetch):
        fetch.return_value = poi_payload()

        response = self.client.post("/predict", json=self.baseline_payload())

        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json["precio_estimado"], 2980242)
        self.assertEqual(response.json["zona_inferida"], "media")
        self.assertEqual(response.json["moneda"], "MXN")

    def test_residential_v2_valid_house(self):
        with self.fake_residential_v2():
            response = self.client.post("/predict/v2/residential", json={
                "property_type": "house",
                "latitude": 18.8123,
                "longitude": -98.9556,
                "land_area_m2": 200,
                "construction_area_m2": 160,
                "bedrooms": 3,
                "bathrooms": 2,
                "parking_spaces": 2,
                "age_years": 8,
            })

        self.assertEqual(response.status_code, 200)
        self.assertTrue(response.json["eligible"])
        self.assertEqual(response.json["model"], "avm_residential_v2_v2")
        self.assertEqual(response.json["estimated_value"], 6250000)
        self.assertEqual(response.json["currency"], "MXN")
        self.assertEqual(response.json["range"]["nominal_coverage"], 0.9)
        self.assertEqual(response.json["confidence"], "MEDIUM")
        self.assertEqual(response.json["location"]["municipality"], "Cuautla")

    def test_avm_v2_v1_valid_house_uses_real_location_context(self):
        with self.fake_avm_v2_v1():
            response = self.client.post("/predict/v2/v1", json={
                "property_type": "house",
                "latitude": 18.8123,
                "longitude": -98.9556,
                "land_area_m2": 100,
                "construction_area_m2": 80,
                "bedrooms": 3,
                "bathrooms": 2,
                "parking_spaces": 2,
                "municipality": "Cuautla",
                "neighborhood": "Centro",
                "location_precision": "device",
            })

        self.assertEqual(response.status_code, 200)
        self.assertTrue(response.json["eligible"])
        self.assertEqual(response.json["model"], "avm_v2_v1")
        self.assertEqual(response.json["estimated_value"], 2980242)
        self.assertEqual(response.json["location"]["municipality"], "Cuautla")
        self.assertEqual(response.json["location"]["coordinate_quality"], "high")

    def test_avm_v2_v1_rejects_missing_location(self):
        response = self.client.post("/predict/v2/v1", json={"property_type": "house"})
        self.assertEqual(response.status_code, 200)
        self.assertFalse(response.json["eligible"])
        self.assertEqual(response.json["reason"], "missing_location")

    def test_avm_v2_v1_reports_spatial_failure(self):
        with patch("app.main._spatial_services", side_effect=RuntimeError("missing datasets")):
            response = self.client.post("/predict/v2/v1", json={
                "property_type": "house",
                "latitude": 18.8123,
                "longitude": -98.9556,
            })

        self.assertEqual(response.status_code, 503)
        self.assertEqual(response.json["reason"], "spatial_enrichment_failed")

    def test_residential_v2_valid_apartment_without_land_area(self):
        with self.fake_residential_v2():
            response = self.client.post("/predict/v2/residential", json={
                "property_type": "apartment",
                "latitude": 18.8123,
                "longitude": -98.9556,
                "construction_area_m2": 90,
                "bedrooms": 2,
                "bathrooms": 2,
                "parking_spaces": 1,
            })

        self.assertEqual(response.status_code, 200)
        self.assertTrue(response.json["eligible"])
        self.assertEqual(response.json["property_type"], "apartment")

    def test_residential_v2_rejects_land(self):
        response = self.client.post("/predict/v2/residential", json={
            "property_type": "land",
            "latitude": 18.8123,
            "longitude": -98.9556,
            "land_area_m2": 200,
        })

        self.assertEqual(response.status_code, 200)
        self.assertFalse(response.json["eligible"])
        self.assertEqual(response.json["reason"], "unsupported_property_type")

    def test_residential_v2_rejects_missing_construction(self):
        response = self.client.post("/predict/v2/residential", json={
            "property_type": "house",
            "latitude": 18.8123,
            "longitude": -98.9556,
            "land_area_m2": 200,
        })

        self.assertEqual(response.status_code, 200)
        self.assertFalse(response.json["eligible"])
        self.assertEqual(response.json["reason"], "missing_construction_area")

    def test_residential_v2_rejects_location_outside_validated_area(self):
        with self.fake_residential_v2(ageb=None):
            response = self.client.post("/predict/v2/residential", json={
                "property_type": "house",
                "latitude": 19.4,
                "longitude": -99.1,
                "land_area_m2": 200,
                "construction_area_m2": 160,
            })

        self.assertEqual(response.status_code, 200)
        self.assertFalse(response.json["eligible"])
        self.assertEqual(response.json["reason"], "outside_validated_location")

    def baseline_payload(self):
        return {
            "tipo": "casa",
            "colonia": "COL_13",
            "m2_terreno": 200,
            "m2_construccion": 160,
            "recamaras": 3,
            "banos": 2,
            "estacionamientos": 2,
            "antiguedad_anios": 8,
            "lat": 18.8123,
            "lng": -98.9556,
        }

    def fake_residential_v2(self, ageb="001A"):
        class DummyModel:
            def predict(self, frame):
                return np.array([np.log1p(6250000)])

        match = SimpleNamespace(
            cve_ageb=ageb,
            municipality="Cuautla",
            locality="Cuautla",
            area_km2=1.0,
        )
        spatial = Mock(match=Mock(return_value=match))
        censo = Mock(features=Mock(return_value={
            "population_density": 3500,
            "housing_density": 900,
            "car_ownership_ratio": 0.6,
            "internet_access_ratio": 0.7,
            "average_schooling": 10.2,
            "employment_ratio": 0.95,
        }))
        denue = Mock(counts=Mock(return_value={
            "establishments_500m": 20,
            "establishments_1km": 80,
            "retail_500m": 5,
            "retail_1km": 20,
            "restaurants_hotels_500m": 3,
            "restaurants_hotels_1km": 12,
            "health_500m": 1,
            "health_1km": 4,
            "education_500m": 1,
            "education_1km": 3,
            "financial_500m": 1,
            "financial_1km": 2,
            "professional_services_500m": 1,
            "professional_services_1km": 2,
        }))

        return patch.multiple(
            main,
            residential_v2_pipe=DummyModel(),
            residential_v2_previous_predictions=main.pd.DataFrame({
                "property_type": ["casa"] * 25,
                "percentage_error": [20] * 20 + [35] * 5,
            }),
            _spatial_services=Mock(return_value=(spatial, censo, denue)),
        )

    def fake_avm_v2_v1(self):
        class DummyModel:
            def predict(self, frame):
                return np.array([2980242.0])

        stack = ExitStack()
        stack.enter_context(self.fake_residential_v2())
        stack.patch = stack.enter_context(patch.object(main, "avm_v2_v1_pipe", DummyModel()))
        return stack


if __name__ == "__main__":
    unittest.main()
