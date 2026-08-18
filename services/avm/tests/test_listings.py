import json
import os
import tempfile
import unittest

from app.listings.deduplication import build_dedupe_fingerprint
from app.listings.models import NormalizedListing
from app.listings.normalizer import (
    normalize_area_m2,
    normalize_price,
    normalize_property_type,
    source_id_from_url,
)
from app.listings.sources.base import FetchedListing
from app.listings.sources.easybroker import EasyBrokerPublicSource
from app.listings.storage import ListingStorage
from app.listings.training import market_segment, price_band, price_per_construction_m2, price_per_land_m2, training_readiness


FIXTURE_HTML = """
<!doctype html>
<html>
<head>
  <title>Casa en venta en Cuautla</title>
  <meta property="og:title" content="Casa familiar en venta">
  <meta property="og:description" content="Casa amplia cerca del centro.">
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "Product",
    "name": "Casa familiar en venta",
    "description": "Casa amplia cerca del centro.",
    "offers": {
      "@type": "Offer",
      "price": "2980242",
      "priceCurrency": "MXN"
    },
    "geo": {
      "@type": "GeoCoordinates",
      "latitude": 18.8123,
      "longitude": -98.9556
    },
    "address": {
      "@type": "PostalAddress",
      "addressLocality": "Colonia Centro",
      "postalCode": "62740"
    }
  }
  </script>
</head>
<body>
  <h1>Casa familiar en venta</h1>
  <p>Terreno 200 m²</p>
  <p>Construcción 160 m²</p>
  <p>3 recámaras</p>
  <p>2 baños</p>
  <p>2 estacionamientos</p>
  <p>Antigüedad 8 años</p>
</body>
</html>
"""


class ListingNormalizerTest(unittest.TestCase):
    def test_normalize_price(self):
        self.assertEqual(normalize_price("$1,002,000 MXN"), (1002000.0, "MXN"))
        self.assertEqual(normalize_price("USD 450,000.50"), (450000.5, "USD"))

    def test_normalize_area(self):
        self.assertEqual(normalize_area_m2("200 m²"), 200.0)
        self.assertEqual(normalize_area_m2("1,250 m2"), 1250.0)

    def test_property_type(self):
        self.assertEqual(normalize_property_type("Casa en venta"), "casa")
        self.assertEqual(normalize_property_type("Departamento nuevo"), "departamento")
        self.assertEqual(normalize_property_type("Bodega industrial"), "bodega")

    def test_source_id_from_url_is_deterministic(self):
        url = "https://www.easybroker.com/mx/listings/casa-demo?utm_source=test"
        self.assertEqual(source_id_from_url(url), "casa-demo")
        self.assertEqual(source_id_from_url(url), source_id_from_url("https://www.easybroker.com/mx/listings/casa-demo"))

    def test_fingerprint(self):
        listing = NormalizedListing(
            source="easybroker",
            source_id="a",
            url="https://example.test/a",
            latitude=18.8123,
            longitude=-98.9556,
            price=2980242,
            land_area_m2=200,
            construction_area_m2=160,
            bedrooms=3,
            bathrooms=2,
        )
        self.assertIsNotNone(build_dedupe_fingerprint(listing))


class ListingStorageTest(unittest.TestCase):
    def test_storage_insert_and_update_existing_listing(self):
        with tempfile.TemporaryDirectory() as directory:
            storage = ListingStorage(os.path.join(directory, "listings.sqlite3"))
            raw_id = storage.save_raw("easybroker", "listing-1", "https://example.test/1", 200, FIXTURE_HTML)
            listing = NormalizedListing(
                source="easybroker",
                source_id="listing-1",
                url="https://example.test/1",
                title="Casa inicial",
                operation="venta",
                price=100,
                raw_data={"a": 1},
            )
            storage.save_normalized(listing, raw_id)
            listing.title = "Casa actualizada"
            listing.price = 200
            storage.save_normalized(listing, raw_id)
            rows = storage.rows()
            self.assertEqual(len(rows), 1)
            self.assertEqual(rows[0]["title"], "Casa actualizada")
            self.assertEqual(rows[0]["price"], 200)
            self.assertEqual(json.loads(rows[0]["raw_data_json"]), {"a": 1})
            self.assertTrue(storage.raw_exists("easybroker", "listing-1"))
            self.assertTrue(storage.normalized_exists("easybroker", "listing-1"))
            storage.close()

    def test_refresh_training_metrics(self):
        with tempfile.TemporaryDirectory() as directory:
            storage = ListingStorage(os.path.join(directory, "listings.sqlite3"))
            raw_id = storage.save_raw("icasas", "listing-1", "https://example.test/1", 200, FIXTURE_HTML)
            listing = NormalizedListing(
                source="icasas",
                source_id="listing-1",
                url="https://example.test/1",
                title="Casa completa",
                property_type="casa",
                operation="venta",
                price=1500000,
                latitude=18.82,
                longitude=-98.95,
                land_area_m2=150,
                construction_area_m2=100,
                bedrooms=3,
                bathrooms=2,
                raw_data={},
            )
            storage.save_normalized(listing, raw_id)
            storage.connection.execute(
                """
                UPDATE listing_normalized
                SET coordinate_quality = 'high',
                    inegi_cve_ageb = '0391',
                    population_density = 100,
                    housing_density = 20,
                    establishments_500m = 10,
                    establishments_1km = 30
                WHERE source_id = 'listing-1'
                """
            )
            storage.connection.commit()
            storage.refresh_training_metrics("icasas")
            row = storage.rows()[0]
            self.assertEqual(row["training_readiness"], "A")
            self.assertEqual(row["price_per_construction_m2"], 15000.0)
            storage.close()


class TrainingReadinessTest(unittest.TestCase):
    def test_training_readiness_levels(self):
        base = {
            "price": 1500000,
            "property_type": "casa",
            "inegi_cve_ageb": "0391",
            "population_density": 100,
            "housing_density": 20,
            "establishments_500m": 10,
            "establishments_1km": 30,
            "land_area_m2": 150,
            "construction_area_m2": 100,
            "bedrooms": 3,
            "bathrooms": 2,
            "latitude": 18.82,
            "longitude": -98.95,
        }
        self.assertEqual(training_readiness({**base, "coordinate_quality": "high"}), "A")
        self.assertEqual(training_readiness({**base, "coordinate_quality": "medium"}), "B")
        partial = {**base, "coordinate_quality": "medium", "construction_area_m2": None}
        self.assertEqual(training_readiness(partial), "C")
        weak = {"price": 1, "property_type": "casa", "coordinate_quality": "high", "latitude": 18.82, "longitude": -98.95}
        self.assertEqual(training_readiness(weak), "D")
        self.assertEqual(training_readiness({"price": None, "property_type": "casa"}), "E")

    def test_training_readiness_is_segment_aware(self):
        enriched = {
            "price": 1500000,
            "inegi_cve_ageb": "0391",
            "population_density": 100,
            "housing_density": 20,
            "establishments_500m": 10,
            "establishments_1km": 30,
            "coordinate_quality": "medium",
        }
        land = {**enriched, "property_type": "terreno", "land_area_m2": 500}
        self.assertEqual(training_readiness(land), "B")

        apartment = {
            **enriched,
            "property_type": "departamento",
            "land_area_m2": None,
            "construction_area_m2": 85,
            "bedrooms": 2,
            "bathrooms": 2,
        }
        self.assertEqual(training_readiness(apartment), "B")

        weak_land = {**enriched, "property_type": "terreno", "land_area_m2": None}
        self.assertEqual(training_readiness(weak_land), "D")

    def test_price_per_m2_rules(self):
        self.assertEqual(price_per_construction_m2({"price": 1000000, "construction_area_m2": 100}), 10000.0)
        self.assertIsNone(price_per_construction_m2({"price": 1000000, "construction_area_m2": None}))
        self.assertEqual(price_per_land_m2({"property_type": "terreno", "price": 1000000, "land_area_m2": 500}), 2000.0)
        self.assertIsNone(price_per_land_m2({"property_type": "casa", "price": 1000000, "land_area_m2": 500}))

    def test_market_segment_and_price_band(self):
        self.assertEqual(market_segment({"property_type": "casa"}), "residential")
        self.assertEqual(market_segment({"property_type": "departamento"}), "residential")
        self.assertEqual(market_segment({"property_type": "terreno"}), "land")
        self.assertEqual(price_band({"price": 900000}), "<1M")
        self.assertEqual(price_band({"price": 2500000}), "2M-3M")
        self.assertEqual(price_band({"price": 25000000}), ">20M")


class EasyBrokerParserTest(unittest.TestCase):
    def test_parser_uses_structured_data_and_html_labels(self):
        source = EasyBrokerPublicSource(delay_seconds=0)
        fetched = FetchedListing(
            source="easybroker",
            source_id="casa-familiar",
            url="https://www.easybroker.com/mx/listings/casa-familiar-en-venta-en-cuautla-morelos",
            http_status=200,
            raw_content=FIXTURE_HTML,
        )
        listing = source.parse(fetched, state="Morelos", municipality="Cuautla")

        self.assertEqual(listing.title, "Casa familiar en venta")
        self.assertEqual(listing.operation, "venta")
        self.assertEqual(listing.property_type, "casa")
        self.assertEqual(listing.price, 2980242.0)
        self.assertEqual(listing.currency, "MXN")
        self.assertEqual(listing.latitude, 18.8123)
        self.assertEqual(listing.longitude, -98.9556)
        self.assertEqual(listing.neighborhood, "Colonia Centro")
        self.assertEqual(listing.postal_code, "62740")
        self.assertEqual(listing.land_area_m2, 200.0)
        self.assertEqual(listing.construction_area_m2, 160.0)
        self.assertEqual(listing.bedrooms, 3)
        self.assertEqual(listing.bathrooms, 2.0)
        self.assertEqual(listing.parking_spaces, 2)
        self.assertEqual(listing.age_years, 8)

    def test_generic_area_is_not_duplicated(self):
        source = EasyBrokerPublicSource(delay_seconds=0)
        listing = source.parse(FetchedListing(
            source="easybroker",
            source_id="generic",
            url="https://demo.easybroker.com/property/casa-en-venta",
            http_status=200,
            raw_content="""
            <html><head><meta property="og:title" content="Casa en venta"></head>
            <body><h1>Casa en venta</h1><p>$1,000,000 MXN En Venta</p><p>820 m²</p></body></html>
            """,
        ))

        self.assertIsNone(listing.land_area_m2)
        self.assertIsNone(listing.construction_area_m2)
        self.assertEqual(listing.generic_area_m2, 820.0)
        self.assertEqual(listing.generic_area_source, "unknown")

    def test_explicit_land_and_construction_are_separated(self):
        source = EasyBrokerPublicSource(delay_seconds=0)
        listing = source.parse(FetchedListing(
            source="easybroker",
            source_id="areas",
            url="https://demo.easybroker.com/property/casa-en-venta",
            http_status=200,
            raw_content="""
            <html><head><meta property="og:title" content="Casa en venta"></head>
            <body><p>Terreno: 820 m²</p><p>Construcción: 420 m²</p></body></html>
            """,
        ))

        self.assertEqual(listing.land_area_m2, 820.0)
        self.assertEqual(listing.construction_area_m2, 420.0)
        self.assertIsNone(listing.generic_area_m2)
        self.assertEqual(listing.land_area_source, "visible_label")
        self.assertEqual(listing.construction_area_source, "visible_label")

    def test_land_listing_can_use_generic_area_as_land(self):
        source = EasyBrokerPublicSource(delay_seconds=0)
        listing = source.parse(FetchedListing(
            source="easybroker",
            source_id="land",
            url="https://demo.easybroker.com/property/terreno-en-venta",
            http_status=200,
            raw_content="""
            <html><head><meta property="og:title" content="Terreno en venta"></head>
            <body><p>$1,000,000 MXN En Venta</p><p>2,730 m²</p></body></html>
            """,
        ))

        self.assertEqual(listing.property_type, "terreno")
        self.assertEqual(listing.land_area_m2, 2730.0)
        self.assertIsNone(listing.construction_area_m2)
        self.assertIsNone(listing.generic_area_m2)

    def test_equal_land_and_construction_can_be_explicit(self):
        source = EasyBrokerPublicSource(delay_seconds=0)
        listing = source.parse(FetchedListing(
            source="easybroker",
            source_id="equal",
            url="https://demo.easybroker.com/property/casa-en-venta",
            http_status=200,
            raw_content="""
            <html><head><meta property="og:title" content="Casa en venta"></head>
            <body><p>Terreno 200 m²</p><p>Construcción 200 m²</p></body></html>
            """,
        ))

        self.assertEqual(listing.land_area_m2, 200.0)
        self.assertEqual(listing.construction_area_m2, 200.0)
        self.assertIn("same_land_and_construction_area", listing.quality_flags)

    def test_location_extraction(self):
        source = EasyBrokerPublicSource(delay_seconds=0)
        listing = source.parse(FetchedListing(
            source="easybroker",
            source_id="location",
            url="https://demo.easybroker.com/property/casa-en-venta",
            http_status=200,
            raw_content="""
            <html><body><h1>Casa en venta</h1><h2>Ubicación Benito Juárez, Cuautla, Morelos</h2></body></html>
            """,
        ))

        self.assertEqual(listing.neighborhood, "Benito Juárez")
        self.assertEqual(listing.locality, "Cuautla")
        self.assertIn("Benito Juárez", listing.location_raw)

    def test_coordinate_extraction_from_public_script(self):
        source = EasyBrokerPublicSource(delay_seconds=0)
        listing = source.parse(FetchedListing(
            source="easybroker",
            source_id="coords",
            url="https://demo.easybroker.com/property/casa-en-venta",
            http_status=200,
            raw_content="""
            <html><body><script>window.mapState = {"latitude":18.8123,"longitude":-98.9556};</script></body></html>
            """,
        ))

        self.assertEqual(listing.latitude, 18.8123)
        self.assertEqual(listing.longitude, -98.9556)


if __name__ == "__main__":
    unittest.main()
