import json
import tempfile
import unittest
from pathlib import Path

from scripts.collect_listings import flatten_unique, load_neighborhood_catalog


class CollectListingsTest(unittest.TestCase):
    def test_cdmx_catalog_is_complete_utf8_and_nonempty(self):
        catalog_path = Path(__file__).resolve().parents[1] / "app" / "data" / "mercadolibre_neighborhoods_cdmx.json"
        catalog = load_neighborhood_catalog(str(catalog_path))
        expected = {
            "Álvaro Obregón", "Azcapotzalco", "Benito Juárez", "Coyoacán",
            "Cuajimalpa de Morelos", "Cuauhtémoc", "Gustavo A. Madero", "Iztacalco",
            "Iztapalapa", "La Magdalena Contreras", "Miguel Hidalgo", "Milpa Alta",
            "Tláhuac", "Tlalpan", "Venustiano Carranza", "Xochimilco",
        }

        self.assertEqual(set(catalog), expected)
        self.assertTrue(all(catalog.values()))
        for neighborhoods in catalog.values():
            self.assertEqual(len(neighborhoods), len(set(neighborhoods)))
        self.assertIn("Del Valle Centro", catalog["Benito Juárez"])
        self.assertIn("San Ángel", catalog["Álvaro Obregón"])

    def test_load_neighborhood_catalog_validates_and_deduplicates(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "neighborhoods.json"
            path.write_text(
                json.dumps({"Benito Juárez": ["Del Valle Centro", "Del Valle Centro", "Narvarte Poniente"]}),
                encoding="utf-8",
            )

            self.assertEqual(
                load_neighborhood_catalog(str(path)),
                {"Benito Juárez": ["Del Valle Centro", "Narvarte Poniente"]},
            )

    def test_flatten_unique_deduplicates_same_mlm_id_across_neighborhoods(self):
        targets = {
            "one": {
                "municipality": "Benito Juárez",
                "neighborhood": "Del Valle Centro",
                "urls": ["https://departamento.mercadolibre.com.mx/MLM-1-first"],
            },
            "two": {
                "municipality": "Benito Juárez",
                "neighborhood": "Narvarte Poniente",
                "urls": [
                    "https://departamento.mercadolibre.com.mx/MLM-1-second",
                    "https://departamento.mercadolibre.com.mx/MLM-2-second",
                ],
            },
        }

        flattened = flatten_unique(targets)

        self.assertEqual([item[0] for item in flattened], [
            "https://departamento.mercadolibre.com.mx/MLM-1-first",
            "https://departamento.mercadolibre.com.mx/MLM-2-second",
        ])


if __name__ == "__main__":
    unittest.main()
