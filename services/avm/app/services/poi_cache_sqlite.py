import os
import sqlite3
import time
import json
from typing import Optional, Dict, Any

DB_PATH = os.getenv("POI_CACHE_DB", "/tmp/poi_cache.sqlite3")

def _conn():
    directory = os.path.dirname(DB_PATH)
    if directory:
        os.makedirs(directory, exist_ok=True)
    return sqlite3.connect(DB_PATH)

def init_db():
    try:
        with _conn() as con:
            con.execute("""
            CREATE TABLE IF NOT EXISTS poi_cache (
                key TEXT PRIMARY KEY,
                payload_json TEXT NOT NULL,
                updated_at INTEGER NOT NULL
            )
            """)
            con.commit()
    except sqlite3.Error:
        return

def get(key: str, ttl_seconds: int) -> Optional[Dict[str, Any]]:
    now = int(time.time())
    try:
        with _conn() as con:
            cur = con.execute("SELECT payload_json, updated_at FROM poi_cache WHERE key = ?", (key,))
            row = cur.fetchone()
            if not row:
                return None
            payload_json, updated_at = row
            if now - int(updated_at) > ttl_seconds:
                return None
            return json.loads(payload_json)
    except (sqlite3.Error, json.JSONDecodeError):
        return None

def get_stale(key: str) -> Optional[Dict[str, Any]]:
    try:
        with _conn() as con:
            cur = con.execute("SELECT payload_json FROM poi_cache WHERE key = ?", (key,))
            row = cur.fetchone()
            if not row:
                return None
            return json.loads(row[0])
    except (sqlite3.Error, json.JSONDecodeError):
            return None

def set(key: str, payload: Dict[str, Any]) -> None:
    now = int(time.time())
    payload_json = json.dumps(payload, ensure_ascii=False)
    try:
        with _conn() as con:
            con.execute(
                "INSERT OR REPLACE INTO poi_cache(key, payload_json, updated_at) VALUES(?, ?, ?)",
                (key, payload_json, now),
            )
            con.commit()
    except sqlite3.Error:
        return
