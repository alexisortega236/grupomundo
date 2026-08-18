import sqlite3
import unittest

from app.listings.spatial.coordinates import coordinate_hash, haversine_m, valid_morelos_coordinate
from app.listings.spatial.quality import validate_coordinate


class SpatialQualityTest(unittest.TestCase):
    def test_haversine_and_hash(self):
        self.assertLess(haversine_m(18.82767, -98.95424, 18.82768, -98.95425), 2)
        self.assertEqual(coordinate_hash(18.8276701, -98.9542401), "18.82767:-98.95424")

    def test_morelos_bounds(self):
        self.assertTrue(valid_morelos_coordinate(18.82767, -98.95424))
        self.assertFalse(valid_morelos_coordinate(20.0, -99.0))

    def test_validate_high_when_specific_and_consistent(self):
        row = fake_row(
            latitude=18.82767,
            longitude=-98.95424,
            street="Calzada Santa Inés 44-48",
            neighborhood="Emiliano Zapata",
            postal_code="62744",
            municipality="Cuautla",
            state="Morelos",
        )
        payload = {
            "address": {
                "road": "Calzada Santa Inés",
                "suburb": "Emiliano Zapata",
                "postcode": "62744",
                "city": "Cuautla",
                "state": "Morelos",
            }
        }
        status, notes, quality = validate_coordinate(row, payload, shared_count=1, nearby_count=1)
        self.assertEqual(status, "consistent")
        self.assertEqual(quality, "high")

    def test_validate_low_when_shared(self):
        row = fake_row(latitude=18.8, longitude=-98.9, municipality="Cuautla", state="Morelos")
        payload = {"address": {"city": "Cuautla", "state": "Morelos"}}
        status, notes, quality = validate_coordinate(row, payload, shared_count=3, nearby_count=3)
        self.assertEqual(status, "partially_consistent")
        self.assertEqual(quality, "low")

    def test_validate_unusable_when_wrong_state(self):
        row = fake_row(latitude=18.8, longitude=-98.9, municipality="Cuautla", state="Morelos")
        payload = {"address": {"city": "Cuautla", "state": "Puebla"}}
        status, notes, quality = validate_coordinate(row, payload, shared_count=1, nearby_count=1)
        self.assertEqual(status, "inconsistent")
        self.assertEqual(quality, "unusable")


def fake_row(**kwargs):
    columns = {
        "latitude": None,
        "longitude": None,
        "street": None,
        "neighborhood": None,
        "postal_code": None,
        "municipality": None,
        "state": None,
    }
    columns.update(kwargs)
    conn = sqlite3.connect(":memory:")
    conn.row_factory = sqlite3.Row
    conn.execute("CREATE TABLE t (" + ",".join(f"{key} TEXT" for key in columns.keys()) + ")")
    conn.execute("INSERT INTO t VALUES (" + ",".join(["?"] * len(columns)) + ")", tuple(columns.values()))
    return conn.execute("SELECT * FROM t").fetchone()


if __name__ == "__main__":
    unittest.main()

