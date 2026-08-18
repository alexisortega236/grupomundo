from __future__ import annotations

import os
import time

import requests


class NominatimReverseGeocoder:
    name = "nominatim_reverse"

    def __init__(self, endpoint: str | None = None, timeout: float | None = None, delay_seconds: float | None = None, user_agent: str | None = None):
        self.endpoint = endpoint or os.getenv("REVERSE_GEOCODING_NOMINATIM_URL", "https://nominatim.openstreetmap.org/reverse")
        self.timeout = timeout if timeout is not None else float(os.getenv("GEOCODING_TIMEOUT", "20"))
        self.delay_seconds = delay_seconds if delay_seconds is not None else float(os.getenv("GEOCODING_REQUEST_DELAY", "1"))
        self.session = requests.Session()
        self.session.headers.update({
            "User-Agent": user_agent or os.getenv("GEOCODING_USER_AGENT", "GrupoMundoAVMResearchBot/0.1")
        })

    def reverse(self, latitude: float, longitude: float) -> dict:
        try:
            response = self.session.get(
                self.endpoint,
                params={
                    "lat": latitude,
                    "lon": longitude,
                    "format": "jsonv2",
                    "addressdetails": 1,
                    "zoom": 18,
                },
                timeout=self.timeout,
            )
            if response.status_code in (403, 429) or response.status_code >= 500:
                return {"error": response.text[:300], "status_code": response.status_code}
            return response.json()
        except Exception as exc:  # noqa: BLE001
            return {"error": str(exc)}
        finally:
            time.sleep(self.delay_seconds)

