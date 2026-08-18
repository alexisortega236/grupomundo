import os
import tempfile
import unittest

from app.listings.geocoding.base import GeocodingProvider
from app.listings.geocoding.models import GeocodingResult, geocode_usability
from app.listings.geocoding.providers.nominatim import infer_precision
from app.listings.geocoding.service import GeocodingService, normalize_geocoding_query
from app.listings.models import NormalizedListing
from app.listings.storage import ListingStorage


class FakeProvider(GeocodingProvider):
    name = "fake"

    def __init__(self, result: GeocodingResult):
        self.result = result
        self.calls = 0
        self.queries = []

    def geocode(self, query):
        self.calls += 1
        self.queries.append(query)
        return self.result


class GeocodingTest(unittest.TestCase):
    def test_normalize_geocoding_query(self):
        self.assertEqual(
            normalize_geocoding_query("  Peña Flores,  Cuautla, Morelos, México "),
            "pena flores, cuautla, morelos, mexico",
        )

    def test_usability_classification(self):
        self.assertEqual(geocode_usability("exact_address"), "high")
        self.assertEqual(geocode_usability("street"), "high")
        self.assertEqual(geocode_usability("neighborhood"), "medium")
        self.assertEqual(geocode_usability("postal_code"), "medium")
        self.assertEqual(geocode_usability("locality"), "low")
        self.assertEqual(geocode_usability("municipality"), "unusable")
        self.assertEqual(geocode_usability("unknown"), "unusable")

    def test_precision_inference(self):
        self.assertEqual(infer_precision({"address": {"house_number": "10", "road": "Av Reforma"}}), "exact_address")
        self.assertEqual(infer_precision({"address": {"road": "Av Reforma"}}), "street")
        self.assertEqual(infer_precision({"address": {"postcode": "62740"}}), "postal_code")
        self.assertEqual(infer_precision({"address": {"neighbourhood": "Centro"}}), "neighborhood")
        self.assertEqual(infer_precision({"address": {"city": "Cuautla"}}), "locality")

    def test_cache_prevents_duplicate_provider_calls(self):
        with tempfile.TemporaryDirectory() as directory:
            storage = ListingStorage(os.path.join(directory, "listings.sqlite3"))
            listing_id = self._insert_listing(storage, "one", "Centro, Cuautla, Morelos")
            provider = FakeProvider(GeocodingResult(
                latitude=18.81,
                longitude=-98.95,
                formatted_address="Centro, Cuautla, Morelos, México",
                precision="neighborhood",
                confidence=0.75,
                provider="fake",
                provider_place_id="abc",
                raw_response={"ok": True},
            ))
            service = GeocodingService(storage, provider)
            row = storage.rows()[0]
            result, consulted = service.geocode_listing(row)
            self.assertTrue(consulted)
            self.assertEqual(provider.calls, 1)

            row = storage.rows()[0]
            result, consulted = service.geocode_listing(row)
            self.assertFalse(consulted)
            self.assertEqual(provider.calls, 1)
            self.assertEqual(result.precision, "neighborhood")
            storage.close()

    def test_not_found_is_persisted_without_overwriting_original_location(self):
        with tempfile.TemporaryDirectory() as directory:
            storage = ListingStorage(os.path.join(directory, "listings.sqlite3"))
            self._insert_listing(storage, "missing", "Centro, Cuautla, Morelos")
            provider = FakeProvider(GeocodingResult(
                latitude=None,
                longitude=None,
                formatted_address=None,
                precision="unknown",
                confidence=0.0,
                provider="fake",
                raw_response={"results": []},
            ))
            service = GeocodingService(storage, provider)
            original = storage.rows()[0]
            original_address = original["address_text"]
            result, consulted = service.geocode_listing(original)

            updated = storage.rows()[0]
            self.assertTrue(consulted)
            self.assertFalse(result.found)
            self.assertEqual(updated["address_text"], original_address)
            self.assertIsNone(updated["latitude"])
            self.assertIsNone(updated["longitude"])
            self.assertEqual(updated["geocode_precision"], "unknown")
            self.assertEqual(updated["geocode_usability"], "unusable")
            storage.close()

    def test_precision_is_capped_by_input_specificity(self):
        with tempfile.TemporaryDirectory() as directory:
            storage = ListingStorage(os.path.join(directory, "listings.sqlite3"))
            self._insert_listing(storage, "street-result", "Centro, Cuautla, Morelos")
            provider = FakeProvider(GeocodingResult(
                latitude=18.85,
                longitude=-98.95,
                formatted_address="Calle Centro, Cuautla, Morelos",
                precision="street",
                confidence=0.9,
                provider="fake",
            ))
            service = GeocodingService(storage, provider)
            service.geocode_listing(storage.rows()[0], force=True)

            updated = storage.rows()[0]
            self.assertEqual(updated["geocode_precision"], "neighborhood")
            self.assertEqual(updated["geocode_usability"], "medium")
            self.assertLessEqual(updated["geocode_confidence"], 0.65)
            storage.close()

    def _insert_listing(self, storage, source_id, address):
        listing = NormalizedListing(
            source="easybroker",
            source_id=source_id,
            url=f"https://example.test/{source_id}",
            title="Casa",
            property_type="casa",
            operation="venta",
            price=1000000,
            state="Morelos",
            municipality="Cuautla",
            neighborhood="Centro",
            address_text=address,
            location_raw=address,
        )
        return storage.save_normalized(listing, raw_id=None)


if __name__ == "__main__":
    unittest.main()
