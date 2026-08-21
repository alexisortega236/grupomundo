import unittest

import numpy as np

from app import main
from app.listings.regional import RegionalModelRegistry, pipeline_feature_contract


class RegionalAvmTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.registry = RegionalModelRegistry()
        cls.client = main.app.test_client()

    def test_model_registry_keeps_both_regional_models(self):
        cdmx_spec, cdmx_model = self.registry.model_for_entity("09")
        morelos_spec, morelos_model = self.registry.model_for_entity("17")
        self.assertEqual(cdmx_spec.model_id, "avm_cdmx_v2_1")
        self.assertEqual(morelos_spec.model_id, "avm_residential_v2")
        self.assertEqual(pipeline_feature_contract(cdmx_model)[1], ["property_type", "inegi_cve_ageb"])
        self.assertIn("municipality", pipeline_feature_contract(morelos_model)[1])

    def test_real_coordinates_resolve_cdmx_and_predict_with_regional_model(self):
        cases = [
            ("Benito Juárez", 19.386924, -99.1636016),
            ("Cuauhtémoc", 19.4046574, -99.1302775),
            ("Iztapalapa", 19.3470432, -99.0553574),
            ("Coyoacán", 19.3156259, -99.1329683),
            ("Miguel Hidalgo", 19.43353, -99.190915),
        ]
        for municipality, latitude, longitude in cases:
            with self.subTest(municipality=municipality):
                response = self.client.post("/predict/v2/residential", json={
                    "property_type": "house",
                    "latitude": latitude,
                    "longitude": longitude,
                    "land_area_m2": 180,
                    "construction_area_m2": 160,
                    "bedrooms": 3,
                    "bathrooms": 2,
                    "parking_spaces": 2,
                })
                self.assertEqual(response.status_code, 200)
                self.assertTrue(response.json["eligible"])
                self.assertEqual(response.json["regional_model"], "avm_cdmx_v2_1")
                self.assertEqual(response.json["model_version"], "avm_cdmx_v2_1_hybrid")
                self.assertEqual(response.json["location"]["municipality"], municipality)
                self.assertTrue(response.json["location"]["ageb"])
                self.assertIsInstance(response.json["estimated_value"], int)
                self.assertGreater(response.json["estimated_value"], 0)
                self.assertIn("market", response.json)
                self.assertIn("ml", response.json)

    def test_morelos_coordinate_keeps_existing_model_route(self):
        response = self.client.post("/predict/v2/residential", json={
            "property_type": "house",
            "latitude": 18.8123,
            "longitude": -98.9556,
            "land_area_m2": 200,
            "construction_area_m2": 160,
            "bedrooms": 3,
            "bathrooms": 2,
            "parking_spaces": 2,
        })
        self.assertEqual(response.status_code, 200)
        self.assertTrue(response.json["eligible"])
        self.assertEqual(response.json["model"], "avm_residential_v2_v2")
        self.assertNotIn("regional_model", response.json)

    def test_unsupported_entity_is_controlled(self):
        response = self.client.post("/predict/v2/residential", json={
            "property_type": "house", "latitude": 20.5, "longitude": -100.5,
            "construction_area_m2": 100, "land_area_m2": 120,
        })
        self.assertEqual(response.status_code, 200)
        self.assertFalse(response.json["eligible"])
        self.assertEqual(response.json["reason"], "outside_supported_region")

    def test_feature_mismatch_is_explicit(self):
        context = self.registry.resolve_context(19.386924, -99.1636016)
        self.assertIsNotNone(context)
        with self.assertRaisesRegex(ValueError, "missing"):
            self.registry.predict(context, {"property_type": "casa", "inegi_cve_ageb": context.match.cve_ageb})

    def test_log_target_is_returned_in_mxn(self):
        context = self.registry.resolve_context(19.386924, -99.1636016)
        row = {
            "property_type": "casa", "inegi_cve_ageb": context.match.cve_ageb,
            "land_area_m2": 180, "construction_area_m2": 160, "bedrooms": 3, "bathrooms": 2, "parking_spaces": 2,
            **context.censo_values, **context.denue_values,
        }
        value = self.registry.predict(context, row)
        self.assertTrue(np.isfinite(value))
        self.assertGreater(value, 0)


if __name__ == "__main__":
    unittest.main()
