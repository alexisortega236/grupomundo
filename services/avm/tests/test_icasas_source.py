import unittest

from app.listings.sources.base import FetchedListing
from app.listings.sources.icasas import IcasasPublicSource


HTML = """
<html>
<head>
<meta property="og:title" content="Casa en fraccionamiento en venta Calzada Santa Inés 44-48, Emiliano Zapata, Cuautla, Morelos, 62744, Mex"/>
<meta property="og:description" content="Terreno 150m2. Construcción 100m2. 3 recamaras. 2 baños. 2 lugares de estacionamiento."/>
</head>
<body>
<span class="title">Casa en fraccionamiento en venta Calzada Santa Inés 44-48, Emiliano Zapata, Cuautla, Morelos, 62744, Mex</span>
<p class="price">1,500,000 MX$</p>
<ul class="details_list">
<li class="dimensions">100m2</li>
<li class="bedrooms">3 Rec&aacute;maras</li>
<li class="bathrooms">2 Baños</li>
</ul>
<div class="title location"><h2><span itemprop="address">Localización: Calzada santa inés 44-48, emiliano zapata, cuautla, morelos, 62744, mex</span></h2></div>
<button id="see-map" data-x="18.82767" data-y="-98.95424">Ver mapa</button>
</body>
</html>
"""


class IcasasSourceTest(unittest.TestCase):
    def test_parse_detail_html(self):
        source = IcasasPublicSource(delay_seconds=0)
        listing = source.parse(FetchedListing("icasas", "demo", "https://www.icasas.mx/propiedad/demo", 200, HTML), state="Morelos", municipality="Cuautla")

        self.assertEqual(listing.source, "icasas")
        self.assertEqual(listing.operation, "venta")
        self.assertEqual(listing.property_type, "casa")
        self.assertEqual(listing.price, 1500000.0)
        self.assertEqual(listing.currency, "MXN")
        self.assertEqual(listing.latitude, 18.82767)
        self.assertEqual(listing.longitude, -98.95424)
        self.assertEqual(listing.street, "Calzada santa inés 44-48")
        self.assertEqual(listing.neighborhood, "emiliano zapata")
        self.assertEqual(listing.locality, "cuautla")
        self.assertEqual(listing.postal_code, "62744")
        self.assertEqual(listing.land_area_m2, 150.0)
        self.assertEqual(listing.construction_area_m2, 100.0)
        self.assertEqual(listing.bedrooms, 3)
        self.assertEqual(listing.bathrooms, 2.0)
        self.assertEqual(listing.parking_spaces, 2)

    def test_missing_fields_do_not_crash(self):
        source = IcasasPublicSource(delay_seconds=0)
        listing = source.parse(FetchedListing("icasas", "missing", "https://www.icasas.mx/propiedad/missing", 200, "<html><body>Sin datos</body></html>"))

        self.assertIsNone(listing.price)
        self.assertIsNone(listing.latitude)
        self.assertIsNone(listing.longitude)
        self.assertIn("missing_price", listing.quality_flags)


if __name__ == "__main__":
    unittest.main()

