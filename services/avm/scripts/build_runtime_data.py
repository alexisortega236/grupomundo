"""Build the compact spatial inference bundle from the local raw datasets."""

from __future__ import annotations

import csv
import json
import os
import shutil
import sys
from datetime import datetime, timezone
from pathlib import Path

import pandas as pd

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from app.listings.spatial.datasets import dataset_paths


CENSO_COLUMNS = [
    "ENTIDAD", "MUN", "LOC", "AGEB", "MZA", "POBTOT", "TVIVHAB",
    "VPH_AUTOM", "VPH_INTER", "GRAPROES", "PEA", "POCUPADA",
]
SHAPE_LAYERS = ("17a", "17mun", "17l")
DENUE_COLUMNS = ["codigo_act", "latitud", "longitud"]


def build() -> None:
    paths = dataset_paths()
    output = paths.runtime_data_dir
    output.mkdir(parents=True, exist_ok=True)

    source_shapes = paths.inegi_data_dir
    raw_censo = Path(os.getenv("CENSO_AGEB_CSV", paths.avm_data_dir / "RESAGEBURB_17CSV20.csv")).expanduser().resolve()
    raw_denue = Path(os.getenv("DENUE_CSV", paths.avm_data_dir / "denue" / "denue_17_csv" / "conjunto_de_datos" / "denue_inegi_17_.csv")).expanduser().resolve()
    shape_files: list[str] = []
    for layer in SHAPE_LAYERS:
        for suffix in (".shp", ".shx", ".dbf", ".prj", ".cpg"):
            source = source_shapes / f"{layer}{suffix}"
            target = output / source.name
            shutil.copyfile(source, target)
            shape_files.append(target.name)

    censo = _build_censo(raw_censo, output / "censo_ageb_features.csv")
    denue = _build_denue(raw_denue, output / "denue_points.csv")
    metadata = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "purpose": "AVM v2 spatial inference only",
        "source_datasets": {
            "ageb": str(source_shapes / "17a.shp"),
            "municipality": str(source_shapes / "17mun.shp"),
            "locality": str(source_shapes / "17l.shp"),
            "censo": str(raw_censo),
            "denue": str(raw_denue),
        },
        "shape_files": shape_files,
        "censo_columns": CENSO_COLUMNS,
        "censo_rows_mza_000": censo,
        "denue_columns": DENUE_COLUMNS,
        "denue_rows": denue,
        "denue_categories": {
            "retail": ["46"],
            "restaurants_hotels": ["72"],
            "health": ["62"],
            "education": ["61"],
            "financial": ["52"],
            "professional_services": ["54"],
        },
    }
    (output / "metadata.json").write_text(json.dumps(metadata, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(json.dumps(metadata, indent=2, ensure_ascii=False))


def _build_censo(source: Path, target: Path) -> int:
    frame = pd.read_csv(source, encoding="latin-1", dtype=str)
    frame.rename(columns={frame.columns[0]: "ENTIDAD"}, inplace=True)
    frame = frame.loc[frame["MZA"].eq("000"), CENSO_COLUMNS]
    frame.to_csv(target, index=False, encoding="utf-8")
    return len(frame)


def _build_denue(source: Path, target: Path) -> int:
    frame = pd.read_csv(source, encoding="latin-1", usecols=DENUE_COLUMNS, dtype={"codigo_act": str})
    frame = frame.dropna(subset=["latitud", "longitud"])
    frame.to_csv(target, index=False, encoding="utf-8", quoting=csv.QUOTE_MINIMAL)
    return len(frame)


if __name__ == "__main__":
    build()
