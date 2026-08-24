import unittest
from unittest.mock import Mock

from app.listings.sources.base import FetchedListing
from app.listings.sources.mercadolibre import MercadoLibrePublicSource


ML_HTML = """
<html>
<head>
<title>Casa En Venta, Maravillas, 4 Recámaras | MercadoLibre</title>
<meta property="og:title" content="Casa En Venta, Maravillas, 4 Recámaras">
<script type="application/ld+json">
{"@context":"https://schema.org","@type":"Product","name":"Casa En Venta, Maravillas, 4 Recámaras","offers":{"price":7800000,"priceCurrency":"MXN"},"sku":"MLM4972124122"}
</script>
<script type="application/ld+json">
{"@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":[
{"position":1,"item":{"name":"Inmuebles","@id":"https://www.mercadolibre.com.mx/c/inmuebles/"},"@type":"ListItem"},
{"position":2,"item":{"name":"Casas","@id":"https://inmuebles.mercadolibre.com.mx/casas/"},"@type":"ListItem"},
{"position":3,"item":{"name":"Venta","@id":"https://inmuebles.mercadolibre.com.mx/casas/venta/"},"@type":"ListItem"},
{"position":4,"item":{"name":"Morelos","@id":"https://inmuebles.mercadolibre.com.mx/casas/venta/morelos/"},"@type":"ListItem"},
{"position":5,"item":{"name":"Cuernavaca","@id":"https://inmuebles.mercadolibre.com.mx/casas/venta/morelos/cuernavaca/"},"@type":"ListItem"},
{"position":6,"item":{"name":"Maravillas","@id":"https://inmuebles.mercadolibre.com.mx/casas/venta/morelos/cuernavaca/maravillas/"},"@type":"ListItem"}]}
</script>
</head>
<body>
Casa en Venta | Publicado hace 5 meses
MXN 7,800,000 4 rec. 4 baños 728 m² totales
Características del inmueble
Estacionamientos: 4
Principales
Superficie total 728 m²
Superficie construida 341 m²
Recámaras 4
Baños 4
Estacionamientos 4
</body>
</html>
"""


class MercadoLibreSourceTest(unittest.TestCase):
    def test_slug_supports_municipality_and_neighborhood_accents(self):
        source = MercadoLibrePublicSource(delay_seconds=0)

        self.assertEqual(source._slug("Álvaro Obregón"), "alvaro-obregon")
        self.assertEqual(source._slug("Del Valle Centro"), "del-valle-centro")
        self.assertEqual(source._slug("San Pedro de los Pinos"), "san-pedro-de-los-pinos")

    def test_search_urls_include_neighborhood_and_paginate(self):
        source = MercadoLibrePublicSource(property_types=["departamento"], delay_seconds=0)

        urls = source._search_urls(
            "Ciudad de México",
            "Benito Juárez",
            "venta",
            2,
            neighborhood="Del Valle Centro",
        )

        self.assertEqual(
            urls,
            [
                "https://inmuebles.mercadolibre.com.mx/departamentos/venta/ciudad-de-mexico/benito-juarez/del-valle-centro/",
                "https://inmuebles.mercadolibre.com.mx/departamentos/venta/ciudad-de-mexico/benito-juarez/del-valle-centro/_Desde_49",
            ],
        )

    def test_discovery_telemetry_counts_page_links_and_mlm_duplicates(self):
        source = MercadoLibrePublicSource(property_types=["departamento"], delay_seconds=0)
        first = Mock(
            status_code=200,
            url="https://inmuebles.mercadolibre.com.mx/departamentos/venta/ciudad-de-mexico/benito-juarez/del-valle-centro/",
            text='''<a href="https://departamento.mercadolibre.com.mx/MLM-1-a">a</a>
                     <a href="https://departamento.mercadolibre.com.mx/MLM-1-b">b</a>''',
        )
        second = Mock(
            status_code=200,
            url="https://inmuebles.mercadolibre.com.mx/departamentos/venta/ciudad-de-mexico/benito-juarez/del-valle-centro/_Desde_49",
            text='<a href="https://departamento.mercadolibre.com.mx/MLM-1-c">c</a>',
        )
        source.session.get = Mock(side_effect=[first, second])
        audit = []

        urls = source.discover(
            state="Ciudad de México",
            municipality="Benito Juárez",
            neighborhood="Del Valle Centro",
            operation="venta",
            max_pages=2,
            audit_sink=audit,
        )

        self.assertEqual(urls, ["https://departamento.mercadolibre.com.mx/MLM-1-a"])
        self.assertEqual(len(audit), 2)
        self.assertEqual(audit[0]["page"], 0)
        self.assertEqual(audit[0]["offset"], 1)
        self.assertEqual(audit[0]["links_found"], 2)
        self.assertEqual(audit[0]["ids_unique"], 1)
        self.assertEqual(audit[0]["duplicates_discarded"], 1)
        self.assertEqual(audit[1]["page"], 1)
        self.assertEqual(audit[1]["offset"], 49)
        self.assertEqual(audit[1]["duplicates_discarded"], 1)

    def test_search_urls_without_neighborhood_preserve_current_mode(self):
        source = MercadoLibrePublicSource(property_types=["casa"], delay_seconds=0)

        self.assertEqual(
            source._search_urls("Morelos", "Cuernavaca", "venta", 1),
            ["https://inmuebles.mercadolibre.com.mx/casas/venta/morelos/cuernavaca/"],
        )

    def test_parser_extracts_structured_price_and_separate_areas(self):
        source = MercadoLibrePublicSource(delay_seconds=0)
        listing = source.parse(FetchedListing(
            source="mercadolibre",
            source_id="MLM-4972124122",
            url="https://casa.mercadolibre.com.mx/MLM-4972124122-casa-en-venta-maravillas-4-recamaras-_JM",
            http_status=200,
            raw_content=ML_HTML,
        ), state="Morelos", municipality="Cuernavaca")

        self.assertEqual(listing.source_id, "MLM-4972124122")
        self.assertEqual(listing.price, 7800000.0)
        self.assertEqual(listing.currency, "MXN")
        self.assertEqual(listing.operation, "venta")
        self.assertEqual(listing.property_type, "casa")
        self.assertEqual(listing.land_area_m2, 728.0)
        self.assertEqual(listing.construction_area_m2, 341.0)
        self.assertIsNone(listing.generic_area_m2)
        self.assertEqual(listing.bedrooms, 4)
        self.assertEqual(listing.bathrooms, 4.0)
        self.assertEqual(listing.parking_spaces, 4)
        self.assertEqual(listing.neighborhood, "Maravillas")
        self.assertIsNone(listing.latitude)
        self.assertIsNone(listing.longitude)

    def test_generic_area_is_not_copied_to_land_and_construction(self):
        source = MercadoLibrePublicSource(delay_seconds=0)
        listing = source.parse(FetchedListing(
            source="mercadolibre",
            source_id="MLM-1",
            url="https://casa.mercadolibre.com.mx/MLM-1-casa-en-venta-_JM",
            http_status=200,
            raw_content="""
            <html><head><meta property="og:title" content="Casa en venta">
            <script type="application/ld+json">{"@type":"Product","name":"Casa en venta","offers":{"price":1000000,"priceCurrency":"MXN"}}</script>
            </head><body>Casa en venta $1,000,000 820 m² 3 recámaras 2 baños</body></html>
            """,
        ))

        self.assertIsNone(listing.land_area_m2)
        self.assertIsNone(listing.construction_area_m2)
        self.assertEqual(listing.generic_area_m2, 820.0)
        self.assertIn("ambiguous_area", listing.quality_flags)

    def test_land_listing_can_use_generic_area_as_land(self):
        source = MercadoLibrePublicSource(delay_seconds=0)
        listing = source.parse(FetchedListing(
            source="mercadolibre",
            source_id="MLM-2",
            url="https://terreno.mercadolibre.com.mx/MLM-2-terreno-en-venta-_JM",
            http_status=200,
            raw_content="""
            <html><head><meta property="og:title" content="Terreno en venta">
            <script type="application/ld+json">{"@type":"Product","name":"Terreno en venta","offers":{"price":1000000,"priceCurrency":"MXN"}}</script>
            </head><body>Terreno en venta $1,000,000 2,730 m²</body></html>
            """,
        ))

        self.assertEqual(listing.property_type, "terreno")
        self.assertEqual(listing.land_area_m2, 2730.0)
        self.assertIsNone(listing.construction_area_m2)
        self.assertIsNone(listing.generic_area_m2)

    def test_fetch_source_id_uses_mlm_identifier(self):
        source = MercadoLibrePublicSource(delay_seconds=0)
        self.assertEqual(
            source._source_id("https://casa.mercadolibre.com.mx/MLM-4972124122-casa-en-venta-_JM"),
            "MLM-4972124122",
        )


if __name__ == "__main__":
    unittest.main()
