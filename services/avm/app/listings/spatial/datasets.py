from __future__ import annotations

import csv
import os
from dataclasses import dataclass
from pathlib import Path


AVM_ROOT = Path(__file__).resolve().parents[3]


@dataclass(frozen=True)
class DatasetPaths:
    avm_data_dir: Path
    inegi_morelos_dir: Path
    censo_ageb_csv: Path
    denue_morelos_dir: Path
    denue_csv: Path
    runtime_data_dir: Path

    @property
    def inegi_data_dir(self) -> Path:
        return self.inegi_morelos_dir / "conjunto_de_datos"

    @property
    def ageb_shp(self) -> Path:
        runtime = self.runtime_data_dir / "17a.shp"
        return runtime if runtime.exists() else self.inegi_data_dir / "17a.shp"

    @property
    def municipality_shp(self) -> Path:
        runtime = self.runtime_data_dir / "17mun.shp"
        return runtime if runtime.exists() else self.inegi_data_dir / "17mun.shp"

    @property
    def locality_shp(self) -> Path:
        runtime = self.runtime_data_dir / "17l.shp"
        return runtime if runtime.exists() else self.inegi_data_dir / "17l.shp"

    @property
    def block_shp(self) -> Path:
        return self.inegi_data_dir / "17m.shp"


def dataset_paths() -> DatasetPaths:
    data_dir = _path_env("AVM_DATA_DIR", AVM_ROOT / "data")
    runtime_data_dir = _path_env("AVM_RUNTIME_DATA_DIR", AVM_ROOT / "runtime_data")
    raw_censo = _path_env("CENSO_AGEB_CSV", data_dir / "RESAGEBURB_17CSV20.csv")
    raw_denue = _path_env("DENUE_CSV", data_dir / "denue" / "denue_17_csv" / "conjunto_de_datos" / "denue_inegi_17_.csv")
    runtime_censo = runtime_data_dir / "censo_ageb_features.csv"
    runtime_denue = runtime_data_dir / "denue_points.csv"
    return DatasetPaths(
        avm_data_dir=data_dir,
        inegi_morelos_dir=_path_env("INEGI_MORELOS_DIR", data_dir / "inegi" / "17_morelos"),
        censo_ageb_csv=runtime_censo if runtime_censo.exists() else raw_censo,
        denue_morelos_dir=_path_env("DENUE_MORELOS_DIR", data_dir / "denue" / "denue_17_csv"),
        denue_csv=runtime_denue if runtime_denue.exists() else raw_denue,
        runtime_data_dir=runtime_data_dir,
    )


def validate_datasets(paths: DatasetPaths | None = None) -> list[str]:
    paths = paths or dataset_paths()
    errors: list[str] = []
    censo_required = {"ENTIDAD", "MUN", "LOC", "AGEB", "MZA", "POBTOT", "TVIVHAB", "VPH_AUTOM", "VPH_INTER", "GRAPROES", "PEA", "POCUPADA"}
    denue_required = {"codigo_act", "latitud", "longitud"}

    errors.extend(_require_file(paths.censo_ageb_csv))
    if paths.censo_ageb_csv.exists():
        errors.extend(_require_csv_columns(paths.censo_ageb_csv, censo_required))

    for shp in [paths.ageb_shp, paths.municipality_shp, paths.locality_shp]:
        errors.extend(_require_file(shp))
    if not paths.block_shp.exists():
        errors.append(f"INEGI opcional no encontrado: {paths.block_shp}")

    errors.extend(_require_file(paths.denue_csv))
    if paths.denue_csv.exists():
        errors.extend(_require_csv_columns(paths.denue_csv, denue_required))
    return errors


def _path_env(name: str, default: Path) -> Path:
    value = os.getenv(name)
    return Path(value).expanduser().resolve() if value else default.resolve()


def _require_file(path: Path) -> list[str]:
    if not path.exists():
        return [f"No existe: {path}"]
    if not path.is_file():
        return [f"No es archivo: {path}"]
    try:
        with path.open("rb"):
            pass
    except OSError as exc:
        return [f"No se puede abrir {path}: {exc}"]
    return []


def _require_csv_columns(path: Path, required: set[str]) -> list[str]:
    try:
        with path.open("r", encoding="utf-8-sig", newline="") as handle:
            reader = csv.reader(handle)
            header = next(reader)
    except UnicodeDecodeError:
        with path.open("r", encoding="latin-1", newline="") as handle:
            reader = csv.reader(handle)
            header = next(reader)
    except Exception as exc:  # noqa: BLE001
        return [f"No se pudo leer columnas de {path}: {exc}"]
    normalized = {column.replace("\ufeff", "").strip() for column in header}
    missing = sorted(required - normalized)
    return [f"Faltan columnas en {path}: {', '.join(missing)}"] if missing else []
