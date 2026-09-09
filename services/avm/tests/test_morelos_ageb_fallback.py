import unittest
from pathlib import Path
from tempfile import TemporaryDirectory
from unittest.mock import Mock, patch

import numpy as np
from shapely.geometry import Polygon

from app import main
from app.listings.spatial.enrichment import AgebMatch, CensoRepository, InegiSpatialIndex


def record(geometry, **attrs):
    return {"geometry": geometry, "prepared": geometry, "attrs": attrs}


class IdentityTransformer:
    def transform(self, longitude, latitude):
        return longitude, latitude


def synthetic_index(agebs):
    index = object.__new__(InegiSpatialIndex)
    index.transformer = IdentityTransformer()
    index.agebs = agebs
    index.municipalities = [record(
        Polygon([(-10, -10), (10, -10), (10, 10), (-10, 10)]),
        CVE_ENT="17", CVE_MUN="006", NOMGEO="Cuautla",
    )]
    index.localities = [record(
        Polygon([(-1, -1), (1, -1), (1, 1), (-1, 1)]),
        CVE_ENT="17", CVE_MUN="006", CVE_LOC="0059", NOMGEO="Reforma",
    )]
    index.blocks = []
    return index


class MorelosAgebFallbackTest(unittest.TestCase):
    def test_exact_match_has_priority_and_metadata(self):
        exact = record(
            Polygon([(-1, -1), (1, -1), (1, 1), (-1, 1)]),
            CVE_ENT="17", CVE_MUN="006", CVE_LOC="0001", CVE_AGEB="EXACT",
        )
        nearest = record(
            Polygon([(1.01, -1), (2, -1), (2, 1), (1.01, 1)]),
            CVE_ENT="17", CVE_MUN="006", CVE_LOC="0001", CVE_AGEB="NEAR",
        )
        match = synthetic_index([nearest, exact]).match(0, 0, allow_nearest=True)
        self.assertEqual(match.cve_ent, "17")
        self.assertEqual(match.cve_mun, "006")
        self.assertEqual(match.cve_loc, "0001")
        self.assertEqual(match.cve_ageb, "EXACT")
        self.assertEqual(match.point_cve_loc, "0059")
        self.assertEqual(match.ageb_match_method, "exact")
        self.assertEqual(match.ageb_distance_m, 0.0)

    def test_nearest_match_keeps_real_locality_and_uses_candidate_ageb(self):
        nearest = record(
            Polygon([(89.513981, -1), (100, -1), (100, 1), (89.513981, 1)]),
            CVE_ENT="17", CVE_MUN="006", CVE_LOC="0001", CVE_AGEB="0762",
        )
        match = synthetic_index([nearest]).match(0, 0, allow_nearest=True)
        self.assertEqual(match.cve_ageb, "0762")
        self.assertEqual(match.cve_ent, "17")
        self.assertEqual(match.cve_mun, "006")
        self.assertEqual(match.locality, "Reforma")
        self.assertEqual(match.point_cve_loc, "0059")
        self.assertEqual(match.cve_loc, "0001")
        self.assertEqual(match.ageb_match_method, "nearest")
        self.assertAlmostEqual(match.ageb_distance_m, 89.513981, places=5)

    def test_nearest_match_rejects_distance_over_limit(self):
        far = record(
            Polygon([(150.01, -1), (160, -1), (160, 1), (150.01, 1)]),
            CVE_ENT="17", CVE_MUN="006", CVE_LOC="0001", CVE_AGEB="FAR",
        )
        self.assertIsNone(synthetic_index([far]).match(0, 0, allow_nearest=True).cve_ageb)

    def test_nearest_match_rejects_other_municipality(self):
        other = record(
            Polygon([(89, -1), (100, -1), (100, 1), (89, 1)]),
            CVE_ENT="17", CVE_MUN="007", CVE_LOC="0001", CVE_AGEB="OTHER",
        )
        self.assertIsNone(synthetic_index([other]).match(0, 0, allow_nearest=True).cve_ageb)

    def test_legacy_match_call_remains_exact_only(self):
        nearest = record(
            Polygon([(89, -1), (100, -1), (100, 1), (89, 1)]),
            CVE_ENT="17", CVE_MUN="006", CVE_LOC="0001", CVE_AGEB="NEAR",
        )
        match = synthetic_index([nearest]).match(0, 0)
        self.assertIsNone(match.cve_ageb)
        self.assertIsNone(match.ageb_match_method)

    def test_censo_uses_ageb_locality_code_not_point_locality_code(self):
        csv_content = (
            "ENTIDAD,MUN,LOC,AGEB,MZA,POBTOT,TVIVHAB,VPH_AUTOM,VPH_INTER,GRAPROES,PEA,POCUPADA\n"
            "17,006,0001,0762,000,2538,707,315,345,9.73,1334,1317\n"
        )
        with TemporaryDirectory() as directory:
            path = Path(directory) / "censo.csv"
            path.write_text(csv_content, encoding="utf-8")
            repository = CensoRepository(path)
            match = AgebMatch(
                cve_ent="17", cve_mun="006", cve_loc="0001", cve_ageb="0762",
                point_cve_loc="0059", area_km2=0.25,
            )
            features = repository.features(match)

        self.assertEqual(features["population"], 2538.0)
        self.assertEqual(match.cve_loc, "0001")
        self.assertEqual(match.point_cve_loc, "0059")

    def test_v1_endpoint_reaches_inference_with_nearest_match(self):
        match = AgebMatch(
            cve_ent="17", cve_mun="006", cve_loc="0001", cve_ageb="0762",
            municipality="Cuautla", locality="Reforma", area_km2=0.25,
            ageb_match_method="nearest", ageb_distance_m=89.513981, point_cve_loc="0059",
        )
        spatial = Mock(match=Mock(return_value=match))
        censo = Mock(features=Mock(return_value={}))
        denue = Mock(counts=Mock(return_value={}))

        class DummyModel:
            def predict(self, frame):
                self.frame = frame
                return np.array([1234567.0])

        with patch.multiple(main, avm_v2_v1_pipe=DummyModel(), _spatial_services=Mock(return_value=(spatial, censo, denue))):
            response = main.app.test_client().post("/predict/v2/v1", json={
                "property_type": "house", "municipality": "Cuautla", "neighborhood": "Reforma",
                "latitude": 18.7842246, "longitude": -98.9313923,
                "location_precision": "neighborhood", "coordinate_quality": "medium",
                "land_area_m2": 250, "construction_area_m2": 130, "bedrooms": 4,
                "bathrooms": 3, "parking_spaces": 0, "property_age_years": 10,
            })

        self.assertEqual(response.status_code, 200)
        self.assertTrue(response.json["eligible"])
        self.assertEqual(response.json["estimated_value"], 1234567)
        self.assertEqual(response.json["location"]["ageb"], "0762")
        self.assertEqual(response.json["location"]["locality"], "Reforma")
        self.assertEqual(response.json["location"]["locality_cve_loc"], "0059")
        self.assertEqual(response.json["location"]["ageb_cve_loc"], "0001")
        self.assertEqual(response.json["location"]["ageb_match_method"], "nearest")
        self.assertAlmostEqual(response.json["location"]["ageb_distance_m"], 89.513981)


if __name__ == "__main__":
    unittest.main()
