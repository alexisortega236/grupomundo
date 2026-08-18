from __future__ import annotations

import hashlib
import json
import sqlite3
from pathlib import Path
from statistics import mean, median
from typing import Any

from app.listings.models import NormalizedListing, utc_now_iso
from app.listings.geocoding.models import GeocodingQuery, GeocodingResult, geocode_usability
from app.listings.training import enriched_quality_flags, price_per_construction_m2, price_per_land_m2, training_readiness


DEFAULT_DB_PATH = Path(__file__).resolve().parents[2] / "data" / "listings.sqlite3"


class ListingStorage:
    def __init__(self, db_path: str | Path = DEFAULT_DB_PATH):
        self.db_path = Path(db_path)
        self.db_path.parent.mkdir(parents=True, exist_ok=True)
        self.connection = sqlite3.connect(self.db_path)
        self.connection.row_factory = sqlite3.Row
        self.initialize()

    def close(self) -> None:
        self.connection.close()

    def initialize(self) -> None:
        self.connection.executescript(
            """
            CREATE TABLE IF NOT EXISTS listing_raw (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source TEXT NOT NULL,
                source_id TEXT NOT NULL,
                url TEXT NOT NULL,
                captured_at TEXT NOT NULL,
                http_status INTEGER,
                content_hash TEXT NOT NULL,
                raw_content TEXT NOT NULL,
                UNIQUE(source, source_id)
            );

            CREATE TABLE IF NOT EXISTS listing_normalized (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source TEXT NOT NULL,
                source_id TEXT NOT NULL,
                url TEXT NOT NULL,
                title TEXT,
                property_type TEXT,
                operation TEXT,
                price REAL,
                currency TEXT,
                latitude REAL,
                longitude REAL,
                state TEXT,
                municipality TEXT,
                locality TEXT,
                neighborhood TEXT,
                postal_code TEXT,
                street TEXT,
                address_text TEXT,
                location_raw TEXT,
                land_area_m2 REAL,
                construction_area_m2 REAL,
                generic_area_m2 REAL,
                land_area_source TEXT,
                construction_area_source TEXT,
                generic_area_source TEXT,
                bedrooms INTEGER,
                bathrooms REAL,
                parking_spaces INTEGER,
                age_years INTEGER,
                description TEXT,
                published_at TEXT,
                captured_at TEXT,
                last_seen_at TEXT,
                raw_data_json TEXT,
                dedupe_fingerprint TEXT,
                quality_flags_json TEXT,
                geocode_latitude REAL,
                geocode_longitude REAL,
                geocode_formatted_address TEXT,
                geocode_precision TEXT,
                geocode_confidence REAL,
                geocode_provider TEXT,
                geocode_place_id TEXT,
                geocode_usability TEXT,
                geocoded_at TEXT,
                geocode_raw_response_json TEXT,
                geocode_query TEXT,
                geocode_query_hash TEXT,
                raw_id INTEGER,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE(source, source_id),
                FOREIGN KEY(raw_id) REFERENCES listing_raw(id)
            );
            """
        )
        self._ensure_column("listing_normalized", "street", "TEXT")
        self._ensure_column("listing_normalized", "address_text", "TEXT")
        self._ensure_column("listing_normalized", "location_raw", "TEXT")
        self._ensure_column("listing_normalized", "generic_area_m2", "REAL")
        self._ensure_column("listing_normalized", "land_area_source", "TEXT")
        self._ensure_column("listing_normalized", "construction_area_source", "TEXT")
        self._ensure_column("listing_normalized", "generic_area_source", "TEXT")
        self.initialize_geocoding()
        self.initialize_spatial_enrichment()
        self.connection.commit()

    def initialize_geocoding(self) -> None:
        self._ensure_column("listing_normalized", "geocode_latitude", "REAL")
        self._ensure_column("listing_normalized", "geocode_longitude", "REAL")
        self._ensure_column("listing_normalized", "geocode_formatted_address", "TEXT")
        self._ensure_column("listing_normalized", "geocode_precision", "TEXT")
        self._ensure_column("listing_normalized", "geocode_confidence", "REAL")
        self._ensure_column("listing_normalized", "geocode_provider", "TEXT")
        self._ensure_column("listing_normalized", "geocode_place_id", "TEXT")
        self._ensure_column("listing_normalized", "geocode_usability", "TEXT")
        self._ensure_column("listing_normalized", "geocoded_at", "TEXT")
        self._ensure_column("listing_normalized", "geocode_raw_response_json", "TEXT")
        self._ensure_column("listing_normalized", "geocode_query", "TEXT")
        self._ensure_column("listing_normalized", "geocode_query_hash", "TEXT")
        self.connection.execute(
            """
            CREATE TABLE IF NOT EXISTS geocoding_cache (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                query_hash TEXT NOT NULL UNIQUE,
                normalized_query TEXT NOT NULL,
                query TEXT NOT NULL,
                provider TEXT NOT NULL,
                latitude REAL,
                longitude REAL,
                formatted_address TEXT,
                precision TEXT,
                confidence REAL,
                provider_place_id TEXT,
                usability TEXT,
                raw_response_json TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );
            """
        )
        self.connection.commit()

    def initialize_spatial_enrichment(self) -> None:
        for column, definition in {
            "coordinate_validation_status": "TEXT",
            "coordinate_validation_notes": "TEXT",
            "coordinate_quality": "TEXT",
            "reverse_geocode_provider": "TEXT",
            "reverse_geocode_raw_json": "TEXT",
            "reverse_geocoded_at": "TEXT",
            "inegi_cve_ent": "TEXT",
            "inegi_cve_mun": "TEXT",
            "inegi_cve_loc": "TEXT",
            "inegi_cve_ageb": "TEXT",
            "inegi_cve_mza": "TEXT",
            "inegi_municipality": "TEXT",
            "inegi_locality": "TEXT",
            "ageb_assignment_quality": "TEXT",
            "population": "REAL",
            "occupied_housing": "REAL",
            "population_density": "REAL",
            "housing_density": "REAL",
            "car_ownership_ratio": "REAL",
            "internet_access_ratio": "REAL",
            "average_schooling": "REAL",
            "employment_ratio": "REAL",
            "establishments_500m": "INTEGER",
            "establishments_1km": "INTEGER",
            "retail_500m": "INTEGER",
            "retail_1km": "INTEGER",
            "restaurants_hotels_500m": "INTEGER",
            "restaurants_hotels_1km": "INTEGER",
            "health_500m": "INTEGER",
            "health_1km": "INTEGER",
            "education_500m": "INTEGER",
            "education_1km": "INTEGER",
            "financial_500m": "INTEGER",
            "financial_1km": "INTEGER",
            "professional_services_500m": "INTEGER",
            "professional_services_1km": "INTEGER",
            "spatial_enriched_at": "TEXT",
            "price_per_construction_m2": "REAL",
            "price_per_land_m2": "REAL",
            "training_readiness": "TEXT",
        }.items():
            self._ensure_column("listing_normalized", column, definition)
        self.connection.execute(
            """
            CREATE TABLE IF NOT EXISTS reverse_geocoding_cache (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                coordinate_hash TEXT NOT NULL UNIQUE,
                latitude REAL NOT NULL,
                longitude REAL NOT NULL,
                provider TEXT NOT NULL,
                raw_response_json TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );
            """
        )
        self.connection.commit()

    def raw_exists(self, source: str, source_id: str) -> bool:
        row = self.connection.execute(
            "SELECT 1 FROM listing_raw WHERE source = ? AND source_id = ? LIMIT 1",
            (source, source_id),
        ).fetchone()
        return row is not None

    def normalized_exists(self, source: str, source_id: str) -> bool:
        row = self.connection.execute(
            "SELECT 1 FROM listing_normalized WHERE source = ? AND source_id = ? LIMIT 1",
            (source, source_id),
        ).fetchone()
        return row is not None

    def refresh_training_metrics(self, source: str | None = None) -> int:
        params: list[Any] = []
        where = ""
        if source:
            where = " WHERE source = ?"
            params.append(source)
        rows = list(self.connection.execute(f"SELECT * FROM listing_normalized{where}", params))
        for row in rows:
            flags = enriched_quality_flags(row)
            row_data = dict(row)
            row_data["quality_flags_json"] = json.dumps(flags, ensure_ascii=False, sort_keys=True)
            self.connection.execute(
                """
                UPDATE listing_normalized
                SET price_per_construction_m2 = ?,
                    price_per_land_m2 = ?,
                    training_readiness = ?,
                    quality_flags_json = ?
                WHERE id = ?
                """,
                (
                    price_per_construction_m2(row_data),
                    price_per_land_m2(row_data),
                    training_readiness(row_data),
                    json.dumps(flags, ensure_ascii=False, sort_keys=True),
                    row["id"],
                ),
            )
        self.connection.commit()
        return len(rows)

    def get_reverse_geocode_cache(self, coordinate_hash: str) -> sqlite3.Row | None:
        return self.connection.execute(
            "SELECT * FROM reverse_geocoding_cache WHERE coordinate_hash = ?",
            (coordinate_hash,),
        ).fetchone()

    def save_reverse_geocode_cache(self, coordinate_hash: str, latitude: float, longitude: float, provider: str, raw_response: dict[str, Any]) -> None:
        now = utc_now_iso()
        self.connection.execute(
            """
            INSERT INTO reverse_geocoding_cache (coordinate_hash, latitude, longitude, provider, raw_response_json, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(coordinate_hash) DO UPDATE SET
                raw_response_json = excluded.raw_response_json,
                provider = excluded.provider,
                updated_at = excluded.updated_at
            """,
            (coordinate_hash, latitude, longitude, provider, json.dumps(raw_response, ensure_ascii=False, sort_keys=True), now, now),
        )
        self.connection.commit()

    def apply_coordinate_audit(self, listing_id: int, status: str, notes: str, quality: str, provider: str | None = None, raw_response: dict[str, Any] | None = None) -> None:
        self.connection.execute(
            """
            UPDATE listing_normalized
            SET coordinate_validation_status = ?,
                coordinate_validation_notes = ?,
                coordinate_quality = ?,
                reverse_geocode_provider = ?,
                reverse_geocode_raw_json = ?,
                reverse_geocoded_at = ?,
                ageb_assignment_quality = CASE
                    WHEN ? = 'high' THEN 'exact_coordinate'
                    WHEN ? = 'medium' THEN 'approximate_coordinate'
                    ELSE 'unusable'
                END
            WHERE id = ?
            """,
            (
                status,
                notes,
                quality,
                provider,
                json.dumps(raw_response or {}, ensure_ascii=False, sort_keys=True),
                utc_now_iso(),
                quality,
                quality,
                listing_id,
            ),
        )
        self.connection.commit()

    def save_raw(self, source: str, source_id: str, url: str, http_status: int | None, raw_content: str, captured_at: str | None = None) -> int:
        captured_at = captured_at or utc_now_iso()
        content_hash = hashlib.sha256(raw_content.encode("utf-8", errors="ignore")).hexdigest()
        self.connection.execute(
            """
            INSERT INTO listing_raw (source, source_id, url, captured_at, http_status, content_hash, raw_content)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(source, source_id) DO UPDATE SET
                url = excluded.url,
                captured_at = excluded.captured_at,
                http_status = excluded.http_status,
                content_hash = excluded.content_hash,
                raw_content = excluded.raw_content
            """,
            (source, source_id, url, captured_at, http_status, content_hash, raw_content),
        )
        self.connection.commit()
        row = self.connection.execute(
            "SELECT id FROM listing_raw WHERE source = ? AND source_id = ?",
            (source, source_id),
        ).fetchone()
        return int(row["id"])

    def save_normalized(self, listing: NormalizedListing, raw_id: int | None) -> int:
        now = utc_now_iso()
        data = listing.to_dict()
        data["raw_data_json"] = json.dumps(data.pop("raw_data"), ensure_ascii=False, sort_keys=True)
        data["quality_flags_json"] = json.dumps(data.pop("quality_flags"), ensure_ascii=False, sort_keys=True)
        data["raw_id"] = raw_id
        columns = [
            "source", "source_id", "url", "title", "property_type", "operation", "price", "currency",
            "latitude", "longitude", "state", "municipality", "locality", "neighborhood", "postal_code",
            "street", "address_text", "location_raw", "land_area_m2", "construction_area_m2", "generic_area_m2",
            "land_area_source", "construction_area_source", "generic_area_source",
            "bedrooms", "bathrooms", "parking_spaces", "age_years",
            "description", "published_at", "captured_at", "last_seen_at", "raw_data_json",
            "dedupe_fingerprint", "quality_flags_json", "raw_id",
        ]
        values = [data.get(column) for column in columns]
        assignments = ", ".join([f"{column} = excluded.{column}" for column in columns if column not in ("source", "source_id")])
        placeholders = ", ".join(["?"] * (len(columns) + 2))
        self.connection.execute(
            f"""
            INSERT INTO listing_normalized ({", ".join(columns)}, created_at, updated_at)
            VALUES ({placeholders})
            ON CONFLICT(source, source_id) DO UPDATE SET
                {assignments},
                updated_at = excluded.updated_at
            """,
            [*values, now, now],
        )
        self.connection.commit()
        row = self.connection.execute(
            "SELECT id FROM listing_normalized WHERE source = ? AND source_id = ?",
            (listing.source, listing.source_id),
        ).fetchone()
        return int(row["id"])

    def rows(self, limit: int | None = None) -> list[sqlite3.Row]:
        sql = "SELECT * FROM listing_normalized ORDER BY updated_at DESC"
        params: tuple[Any, ...] = ()
        if limit:
            sql += " LIMIT ?"
            params = (limit,)
        return list(self.connection.execute(sql, params).fetchall())

    def eligible_geocoding_rows(self, source: str | None = None, municipality: str | None = None, limit: int | None = None) -> list[sqlite3.Row]:
        clauses = ["(address_text IS NOT NULL OR neighborhood IS NOT NULL OR municipality IS NOT NULL OR state IS NOT NULL)"]
        params: list[Any] = []
        if source:
            clauses.append("source = ?")
            params.append(source)
        if municipality:
            clauses.append("municipality = ?")
            params.append(municipality)
        sql = f"SELECT * FROM listing_normalized WHERE {' AND '.join(clauses)} ORDER BY id"
        if limit:
            sql += " LIMIT ?"
            params.append(limit)
        return list(self.connection.execute(sql, params).fetchall())

    def get_geocode_cache(self, query_hash: str) -> sqlite3.Row | None:
        return self.connection.execute(
            "SELECT * FROM geocoding_cache WHERE query_hash = ?",
            (query_hash,),
        ).fetchone()

    def save_geocode_cache(self, query: GeocodingQuery, result: GeocodingResult) -> None:
        now = utc_now_iso()
        self.connection.execute(
            """
            INSERT INTO geocoding_cache (
                query_hash, normalized_query, query, provider, latitude, longitude,
                formatted_address, precision, confidence, provider_place_id, usability,
                raw_response_json, created_at, updated_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(query_hash) DO UPDATE SET
                provider = excluded.provider,
                latitude = excluded.latitude,
                longitude = excluded.longitude,
                formatted_address = excluded.formatted_address,
                precision = excluded.precision,
                confidence = excluded.confidence,
                provider_place_id = excluded.provider_place_id,
                usability = excluded.usability,
                raw_response_json = excluded.raw_response_json,
                updated_at = excluded.updated_at
            """,
            (
                query.query_hash,
                query.normalized_query,
                query.query,
                result.provider or "",
                result.latitude,
                result.longitude,
                result.formatted_address,
                result.precision,
                result.confidence,
                result.provider_place_id,
                geocode_usability(result.precision),
                json.dumps(result.raw_response, ensure_ascii=False, sort_keys=True),
                now,
                now,
            ),
        )
        self.connection.commit()

    def apply_geocode_result(self, listing_id: int, query: GeocodingQuery, result: GeocodingResult) -> None:
        self.connection.execute(
            """
            UPDATE listing_normalized
            SET geocode_latitude = ?,
                geocode_longitude = ?,
                geocode_formatted_address = ?,
                geocode_precision = ?,
                geocode_confidence = ?,
                geocode_provider = ?,
                geocode_place_id = ?,
                geocode_usability = ?,
                geocoded_at = ?,
                geocode_raw_response_json = ?,
                geocode_query = ?,
                geocode_query_hash = ?
            WHERE id = ?
            """,
            (
                result.latitude,
                result.longitude,
                result.formatted_address,
                result.precision,
                result.confidence,
                result.provider,
                result.provider_place_id,
                geocode_usability(result.precision),
                utc_now_iso(),
                json.dumps(result.raw_response, ensure_ascii=False, sort_keys=True),
                query.query,
                query.query_hash,
                listing_id,
            ),
        )
        self.connection.commit()

    def summary(self) -> dict[str, Any]:
        rows = self.rows()
        total = len(rows)
        prices = [float(row["price"]) for row in rows if row["price"] is not None and float(row["price"]) > 0]
        construction = [float(row["construction_area_m2"]) for row in rows if row["construction_area_m2"] is not None and float(row["construction_area_m2"]) > 0]
        price_m2 = [
            float(row["price"]) / float(row["construction_area_m2"])
            for row in rows
            if row["price"] is not None
            and row["construction_area_m2"] is not None
            and float(row["price"]) > 0
            and float(row["construction_area_m2"]) > 0
        ]
        return {
            "total": total,
            "fields": {field: self._presence(rows, field) for field in [
                "price", "latitude", "longitude", "land_area_m2", "construction_area_m2", "generic_area_m2",
                "bedrooms", "bathrooms", "parking_spaces",
            ]},
            "price": self._stats(prices),
            "construction_area_m2": self._stats(construction),
            "price_m2_construction": self._stats(price_m2),
        }

    def _ensure_column(self, table: str, column: str, definition: str) -> None:
        columns = {row["name"] for row in self.connection.execute(f"PRAGMA table_info({table})")}
        if column not in columns:
            self.connection.execute(f"ALTER TABLE {table} ADD COLUMN {column} {definition}")

    def _presence(self, rows: list[sqlite3.Row], field: str) -> dict[str, float | int]:
        total = len(rows)
        present = sum(1 for row in rows if row[field] is not None)
        percent = (present / total * 100) if total else 0
        return {"present": present, "percent": percent}

    def _stats(self, values: list[float]) -> dict[str, float | None]:
        if not values:
            return {"min": None, "median": None, "average": None, "max": None}
        return {
            "min": min(values),
            "median": median(values),
            "average": mean(values),
            "max": max(values),
        }
