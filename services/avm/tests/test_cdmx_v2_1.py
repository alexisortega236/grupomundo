import unittest

import numpy as np

from app import main
from app.listings.comparables import ComparableEngine, weighted_quantile
from app.listings.regional import RegionalModelRegistry


class CdmxV21Test(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.registry = RegionalModelRegistry()
        cls.client = main.app.test_client()
        cls.base = {
            "property_type": "house", "latitude": 19.4659567, "longitude": -99.1821386,
            "land_area_m2": 90, "construction_area_m2": 90, "bedrooms": 2,
            "bathrooms": 1, "parking_spaces": 1, "neighborhood": "Clavería",
        }

    def test_weighted_percentile(self):
        self.assertEqual(weighted_quantile([1, 2, 3], [1, 1, 4], .5), 3.0)

    def test_engine_uses_same_type_and_returns_market_fields(self):
        context = self.registry.resolve_context(self.base["latitude"], self.base["longitude"])
        self.assertIsNotNone(context)
        target = {**self.base, "property_type": "casa", "municipality": context.match.municipality, "inegi_cve_ageb": context.match.cve_ageb}
        market = self.registry.cdmx_comparables.find(target)
        self.assertGreaterEqual(market["comparable_count"], 0)
        self.assertIn(market["strategy"], {"same_neighborhood", "same_ageb", "similar_1km", "similar_2km", "municipality_fallback"})
        self.assertGreaterEqual(market["market_strength"], 0)
        self.assertLessEqual(market["market_strength"], 1)

    def test_runtime_safe_policy_does_not_use_target_price(self):
        engine = ComparableEngine(self.registry.cdmx_comparables.csv_path)
        market = {"market_base": 1_300_000, "market_strength": .8, "comparable_count": 10, "dispersion": .2}
        result = engine.reconcile(3_800_000, market)
        self.assertLess(result["estimated_value"], 3_800_000)
        self.assertGreater(result["market_weight"], .75)

    def test_claveria_endpoint_returns_hybrid_contract(self):
        response = self.client.post("/predict/v2/residential", json=self.base)
        self.assertEqual(response.status_code, 200)
        self.assertTrue(response.json["eligible"])
        self.assertEqual(response.json["regional_model"], "avm_cdmx_v2_1")
        self.assertEqual(response.json["model_version"], "avm_cdmx_v2_1_hybrid")
        self.assertIsInstance(response.json["estimated_value"], int)
        self.assertIn("market", response.json)
        self.assertIn("range", response.json)

    def test_health_exposes_cdmx_v21(self):
        response = self.client.get("/health")
        self.assertTrue(response.json["cdmx_v2_1_loaded"])

    def test_morelos_still_routes_to_morelos(self):
        response = self.client.post("/predict/v2/residential", json={
            "property_type": "house", "latitude": 18.8123, "longitude": -98.9556,
            "land_area_m2": 200, "construction_area_m2": 160, "bedrooms": 3,
            "bathrooms": 2, "parking_spaces": 2,
        })
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json["regional_model"] if "regional_model" in response.json else None, None)
        self.assertEqual(response.json["model_version"], "avm_residential_v2_v2_experimental")


if __name__ == "__main__":
    unittest.main()
