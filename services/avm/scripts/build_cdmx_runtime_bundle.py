#!/usr/bin/env python3
"""Build the isolated CDMX v1 runtime bundle from audited local datasets."""

from __future__ import annotations

import csv
import json
import shutil
from datetime import datetime, timezone
from pathlib import Path

import pandas as pd


ROOT = Path(__file__).resolve().parents[1]
REPO = ROOT.parents[1]
OUTPUT = ROOT / "runtime_data" / "cdmx_v1"
AGEB_SOURCE = REPO / "storage/app/data/2025_1_09_A.shp"
CENSO_SOURCE = REPO / "storage/app/data/conjunto_de_datos/conjunto_de_datos_ageb_urbana_09_cpv2020.csv"
DENUE_SOURCE = REPO / "storage/app/data/denue_09_csv/conjunto_de_datos/denue_inegi_09_.csv"
MODEL_SOURCE = ROOT / "data/experiments/cdmx_v1/model_best_experimental.joblib"

CENSO_COLUMNS = ["ENTIDAD", "NOM_ENT", "MUN", "NOM_MUN", "LOC", "NOM_LOC", "AGEB", "MZA", "POBTOT", "TVIVHAB", "VPH_AUTOM", "VPH_INTER", "GRAPROES", "PEA", "POCUPADA"]
DENUE_COLUMNS = ["codigo_act", "latitud", "longitud"]


def main() -> int:
    OUTPUT.mkdir(parents=True, exist_ok=True)
    copied = []
    for suffix in (".shp", ".shx", ".dbf", ".prj", ".cpg"):
        source = AGEB_SOURCE.with_suffix(suffix)
        target = OUTPUT / f"09a{suffix}"
        shutil.copyfile(source, target)
        copied.append(target.name)
    model_target = OUTPUT / "model_best_experimental.joblib"
    shutil.copyfile(MODEL_SOURCE, model_target)
    copied.append(model_target.name)

    censo = pd.read_csv(CENSO_SOURCE, encoding="utf-8-sig", dtype=str)
    censo.columns = [str(column).replace("ï»¿", "").replace("\ufeff", "").strip() for column in censo.columns]
    censo = censo[(censo["ENTIDAD"] == "09") & (censo["MZA"] == "000") & (censo["AGEB"] != "0000")][CENSO_COLUMNS]
    censo_path = OUTPUT / "censo_ageb_features.csv"
    censo.to_csv(censo_path, index=False, encoding="utf-8")

    denue = pd.read_csv(DENUE_SOURCE, encoding="latin-1", usecols=DENUE_COLUMNS, dtype={"codigo_act": str})
    denue["latitud"] = pd.to_numeric(denue["latitud"], errors="coerce")
    denue["longitud"] = pd.to_numeric(denue["longitud"], errors="coerce")
    denue = denue.dropna(subset=["latitud", "longitud"])
    outside = (denue["latitud"] > 20) | (denue["longitud"] < -100)
    excluded = int(outside.sum())
    denue = denue.loc[~outside, DENUE_COLUMNS]
    denue_path = OUTPUT / "denue_points.csv"
    denue.to_csv(denue_path, index=False, encoding="utf-8", quoting=csv.QUOTE_MINIMAL)

    metadata = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "region": "Ciudad de México",
        "entity_code": "09",
        "model_id": "avm_cdmx_v1",
        "model_version": "avm_cdmx_v1_experimental",
        "purpose": "Regional AVM inference bundle; experimental only.",
        "source_datasets": {"ageb": str(AGEB_SOURCE), "censo": str(CENSO_SOURCE), "denue": str(DENUE_SOURCE), "model": str(MODEL_SOURCE)},
        "files": copied + [censo_path.name, denue_path.name, "metadata.json"],
        "ageb_crs": "EPSG:6372",
        "ageb_polygons": 2430,
        "censo_rows_mza_000_ageb": int(len(censo)),
        "denue_rows": int(len(denue)),
        "denue_outside_cdmx_excluded": excluded,
        "censo_columns": CENSO_COLUMNS,
        "denue_columns": DENUE_COLUMNS,
    }
    (OUTPUT / "metadata.json").write_text(json.dumps(metadata, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(metadata, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
