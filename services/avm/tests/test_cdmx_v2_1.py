import unittest
from pathlib import Path
from tempfile import TemporaryDirectory

import numpy as np
import pandas as pd

from app import main
from app.listings.comparables import ComparableEngine, normalize_location, physical_metrics, weighted_quantile
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
        self.assertIn(market["strategy"], {"same_neighborhood", "same_ageb", "similar_1km", "similar_2km", "municipality_filtered", "insufficient_market_evidence"})
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

    def test_location_normalization_is_conservative(self):
        self.assertEqual(normalize_location(" General Pedro María   Anaya "), "general pedro maria anaya")
        self.assertEqual(normalize_location("Benito\u00a0Juárez"), "benito juarez")
        self.assertNotEqual(normalize_location("Del Valle Norte"), normalize_location("Del Valle Sur"))

    def test_physical_metrics_expose_tolerances_and_house_similarity(self):
        frame = pd.DataFrame([{"construction_area_m2": 144, "land_area_m2": 108}])
        metrics = physical_metrics({"property_type": "casa", "construction_area_m2": 120, "land_area_m2": 90}, frame)
        self.assertAlmostEqual(metrics["construction_similarity"][0], .8)
        self.assertAlmostEqual(metrics["land_similarity"][0], .8)
        self.assertTrue(metrics["both_areas_within_20pct"][0])
        self.assertTrue(metrics["both_areas_within_30pct"][0])

    def test_audited_case_no_longer_uses_indiscriminate_municipality(self):
        context = self.registry.resolve_context(19.3606838, -99.1589048)
        target = {"property_type": "casa", "latitude": 19.3606838, "longitude": -99.1589048,
                  "municipality": "Benito Juárez", "neighborhood": "General Pedro María Anaya",
                  "inegi_cve_ageb": context.match.cve_ageb, "land_area_m2": 90,
                  "construction_area_m2": 120, "bedrooms": 2, "bathrooms": 1, "parking_spaces": 1}
        market = self.registry.cdmx_comparables.find(target)
        self.assertNotEqual(market["strategy"], "municipality_fallback")
        self.assertLess(market["market_strength"], .5047)
        self.assertLessEqual(market["comparable_count"], 10)
        self.assertIn(market["strategy"], {"municipality_filtered", "insufficient_market_evidence"})
        reconciliation = ComparableEngine.reconcile(self.registry.predict(context, {**target, **context.censo_values, **context.denue_values}), market)
        self.assertEqual(reconciliation["confidence"], "LOW")
        self.assertIsNone(market["market_base"])

    def test_same_colony_three_good_comparables_produce_market(self):
        rows = self._synthetic_rows("Colonia Centro", "AGEB1", [(19.0, -99.0, 120, 90, 2_000_000), (19.0001, -99.0, 125, 95, 2_100_000), (19.0, -99.0001, 115, 85, 1_900_000)])
        market = self._engine(rows).find(self._target("Colonia Centro", "AGEB1"))
        self.assertEqual(market["strategy"], "same_neighborhood")
        self.assertEqual(market["comparable_count"], 3)
        self.assertEqual(market["both_areas_within_20pct_count"], 3)
        self.assertIsNotNone(market["market_base"])

    def test_same_ageb_three_good_comparables_use_ageb_strategy(self):
        rows = self._synthetic_rows("Otra Colonia", "AGEB1", [(19.0, -99.0, 120, 90, 2_000_000), (19.0001, -99.0, 125, 95, 2_100_000), (19.0, -99.0001, 115, 85, 1_900_000)])
        market = self._engine(rows).find(self._target("Objetivo", "AGEB1"))
        self.assertEqual(market["strategy"], "same_ageb")

    def test_one_km_and_two_km_strategies_are_reached_in_order(self):
        rows_1km = self._synthetic_rows("Cerca", "AGEB2", [(19.0, -99.0, 120, 90, 2_000_000), (19.0001, -99.0, 125, 95, 2_100_000), (19.0, -99.0001, 115, 85, 1_900_000)])
        market_1km = self._engine(rows_1km).find(self._target("Objetivo", "AGEB1"))
        self.assertEqual(market_1km["strategy"], "similar_1km")
        rows_2km = self._synthetic_rows("Lejos", "AGEB2", [(19.01, -99.0, 120, 90, 2_000_000), (19.0101, -99.0, 125, 95, 2_100_000), (19.01, -99.0001, 115, 85, 1_900_000)])
        market_2km = self._engine(rows_2km).find(self._target("Objetivo", "AGEB1"))
        self.assertEqual(market_2km["strategy"], "similar_2km")

    def test_house_construction_or_land_mismatch_is_not_accepted(self):
        rows = self._synthetic_rows("Centro", "A1", [(19.0, -99.0, 120, 300, 2_000_000), (19.0001, -99.0, 300, 90, 2_100_000), (19.0, -99.0001, 300, 300, 1_900_000)])
        market = self._engine(rows).find(self._target("Centro", "A1"))
        self.assertEqual(market["strategy"], "insufficient_market_evidence")
        self.assertEqual(market["qualified_count"], 0)

    def test_outlier_and_high_dispersion_do_not_raise_confidence(self):
        rows = self._synthetic_rows("Centro", "A1", [(19.0, -99.0, 120, 90, 1_000_000), (19.0001, -99.0, 120, 90, 10_000_000), (19.0, -99.0001, 120, 90, 50_000_000)])
        market = self._engine(rows).find(self._target("Centro", "A1"))
        result = ComparableEngine.reconcile(5_000_000, market)
        self.assertEqual(result["confidence"], "LOW")

    def test_reconciliation_respects_unavailable_and_strong_market(self):
        unavailable = ComparableEngine.reconcile(4_000_000, {"market_base": None, "market_strength": .9})
        self.assertEqual(unavailable["estimated_value"], 4_000_000)
        self.assertEqual(unavailable["market_weight"], 0.0)
        strong = {"market_base": 3_000_000, "market_strength": .9, "strategy": "same_neighborhood",
                  "comparable_count": 6, "high_quality_count": 3, "dispersion": .2}
        result = ComparableEngine.reconcile(3_100_000, strong)
        self.assertGreater(result["market_weight"], .8)
        self.assertEqual(result["confidence"], "HIGH")

    def test_municipality_bad_many_records_is_insufficient(self):
        rows = self._synthetic_rows("Otra", "AGEB2", [(19.0, -99.0, 300, 300, 8_000_000)] * 8)
        market = self._engine(rows).find(self._target("Objetivo", "AGEB1"))
        self.assertEqual(market["strategy"], "insufficient_market_evidence")
        self.assertIsNone(market["market_base"])
        self.assertIsNone(market["range_low"])

    def test_department_does_not_require_land_similarity(self):
        rows = [{"source_id": "D1", "property_type": "departamento", "price": 2_000_000,
                 "land_area_m2": np.nan, "construction_area_m2": 100, "bedrooms": 2,
                 "bathrooms": 1, "parking_spaces": 1, "latitude": 19.0, "longitude": -99.0,
                 "municipality": "Benito Juárez", "neighborhood": "Centro", "inegi_cve_ageb": "A1"}]
        market = self._engine(rows).find({"property_type": "departamento", "latitude": 19.0, "longitude": -99.0,
                                           "municipality": "Benito Juárez", "neighborhood": "Centro", "inegi_cve_ageb": "A1",
                                           "construction_area_m2": 100, "land_area_m2": None, "bedrooms": 2, "bathrooms": 1, "parking_spaces": 1})
        self.assertEqual(market["comparable_count"], 1)
        self.assertEqual(market["both_areas_within_30pct_count"], 1)

    @staticmethod
    def _target(neighborhood, ageb):
        return {"property_type": "casa", "latitude": 19.0, "longitude": -99.0,
                "municipality": "Benito Juárez", "neighborhood": neighborhood, "inegi_cve_ageb": ageb,
                "land_area_m2": 90, "construction_area_m2": 120, "bedrooms": 2, "bathrooms": 1, "parking_spaces": 1}

    @staticmethod
    def _synthetic_rows(neighborhood, ageb, values):
        return [{"source_id": f"S{i}", "property_type": "casa", "price": price, "land_area_m2": land,
                 "construction_area_m2": construction, "bedrooms": 2, "bathrooms": 1, "parking_spaces": 1,
                 "latitude": lat, "longitude": lng, "municipality": "Benito Juárez",
                 "neighborhood": neighborhood, "inegi_cve_ageb": ageb}
                for i, (lat, lng, construction, land, price) in enumerate(values)]

    def _engine(self, rows):
        columns = ["source_id", "property_type", "price", "land_area_m2", "construction_area_m2", "bedrooms", "bathrooms", "parking_spaces", "latitude", "longitude", "municipality", "neighborhood", "inegi_cve_ageb"]
        with TemporaryDirectory() as directory:
            path = Path(directory) / "comparables.csv"
            pd.DataFrame(rows, columns=columns).to_csv(path, index=False)
            # Keep the temporary CSV alive for the engine's eager read.
            return ComparableEngine(path)


if __name__ == "__main__":
    unittest.main()
