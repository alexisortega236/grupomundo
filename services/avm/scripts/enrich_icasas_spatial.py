#!/usr/bin/env python3
from __future__ import annotations

import argparse
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from app.listings.storage import ListingStorage  # noqa: E402
from app.listings.spatial.datasets import dataset_paths, validate_datasets  # noqa: E402
from app.listings.spatial.enrichment import CensoRepository, DenueIndex, InegiSpatialIndex  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser(description="Prepara enriquecimiento AGEB/Censo/DENUE para listings geocodificados.")
    parser.add_argument("--source", default="icasas")
    parser.add_argument("--db-path", default=str(ROOT / "data" / "listings.sqlite3"))
    args = parser.parse_args()

    storage = ListingStorage(args.db_path)
    rows = list(storage.connection.execute("SELECT * FROM listing_normalized WHERE source = ? ORDER BY id", (args.source,)))
    paths = dataset_paths()
    validation_errors = validate_datasets(paths)
    eligible = [row for row in rows if spatial_quality(row) in ("high", "medium")]

    for row in rows:
        quality_value = spatial_quality(row)
        if quality_value == "high":
            quality = "exact_coordinate"
        elif quality_value == "medium":
            quality = "approximate_coordinate"
        else:
            quality = "unusable"
        storage.connection.execute(
            """
            UPDATE listing_normalized
            SET ageb_assignment_quality = ?,
                coordinate_quality = CASE
                    WHEN coordinate_quality IS NULL AND geocode_usability IN ('high', 'medium', 'low', 'unusable')
                    THEN geocode_usability
                    ELSE coordinate_quality
                END,
                spatial_enriched_at = datetime('now')
            WHERE id = ?
            """,
            (quality, row["id"]),
        )
    storage.connection.commit()

    print(f"Total {args.source} listings: {len(rows)}")
    print(f"Elegibles por calidad espacial high/medium: {len(eligible)}")
    print("Rutas utilizadas:")
    print(f"- AVM_DATA_DIR={paths.avm_data_dir}")
    print(f"- INEGI_MORELOS_DIR={paths.inegi_morelos_dir}")
    print(f"- CENSO_AGEB_CSV={paths.censo_ageb_csv}")
    print(f"- DENUE_MORELOS_DIR={paths.denue_morelos_dir}")
    print(f"- DENUE_CSV={paths.denue_csv}")
    blocking_errors = [e for e in validation_errors if not e.startswith("INEGI opcional")]
    if blocking_errors:
        print("Insumos geoespaciales incompletos; no se asignó AGEB/Censo/DENUE:")
        for item in blocking_errors:
            print(f"- {item}")
    else:
        if validation_errors:
            for item in validation_errors:
                print(f"Advertencia: {item}")
        spatial = InegiSpatialIndex(paths)
        censo = CensoRepository(paths.censo_ageb_csv)
        denue = DenueIndex(paths.denue_csv)
        enriched_ageb = 0
        enriched_censo = 0
        enriched_denue = 0
        for row in eligible:
            lat, lng = listing_coordinates(row)
            if lat is None or lng is None:
                continue
            match = spatial.match(lat, lng)
            ageb_quality = "exact_coordinate" if spatial_quality(row) == "high" else "approximate_coordinate"
            censo_values = censo.features(match) if match.cve_ageb else {}
            denue_values = denue.counts(lat, lng)
            if match.cve_ageb:
                enriched_ageb += 1
            if censo_values:
                enriched_censo += 1
            if denue_values:
                enriched_denue += 1
            update_listing(storage, row["id"], match, ageb_quality, censo_values, denue_values)
        print(f"AGEB asignado: {enriched_ageb}")
        print(f"Censo enriquecido: {enriched_censo}")
        print(f"DENUE enriquecido: {enriched_denue}")
    storage.close()
    return 0


def spatial_quality(row) -> str | None:
    return row["coordinate_quality"] or row["geocode_usability"]


def listing_coordinates(row) -> tuple[float | None, float | None]:
    lat = row["latitude"] if row["latitude"] is not None else row["geocode_latitude"]
    lng = row["longitude"] if row["longitude"] is not None else row["geocode_longitude"]
    return lat, lng


def update_listing(storage, listing_id, match, ageb_quality, censo_values, denue_values) -> None:
    values = {
        "inegi_cve_ent": match.cve_ent,
        "inegi_cve_mun": match.cve_mun,
        "inegi_cve_loc": match.cve_loc,
        "inegi_cve_ageb": match.cve_ageb,
        "inegi_cve_mza": match.cve_mza,
        "inegi_municipality": match.municipality,
        "inegi_locality": match.locality,
        "ageb_assignment_quality": ageb_quality if match.cve_ageb else "unusable",
        "spatial_enriched_at": "datetime('now')",
        **censo_values,
        **denue_values,
    }
    assignments = []
    params = []
    for key, value in values.items():
        if key == "spatial_enriched_at":
            assignments.append(f"{key} = datetime('now')")
        else:
            assignments.append(f"{key} = ?")
            params.append(value)
    params.append(listing_id)
    storage.connection.execute(
        f"UPDATE listing_normalized SET {', '.join(assignments)} WHERE id = ?",
        params,
    )
    storage.connection.commit()


if __name__ == "__main__":
    raise SystemExit(main())
