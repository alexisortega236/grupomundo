async function postJSON(url, payload) {
  const r = await fetch(url, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
  const txt = await r.text();
  let data;
  try { data = JSON.parse(txt); } catch { data = { raw: txt }; }
  if (!r.ok) throw new Error(data.error || `HTTP ${r.status}`);
  return data;
}

function moneyMXN(n) {
  try {
    return new Intl.NumberFormat("es-MX", { style: "currency", currency: "MXN", maximumFractionDigits: 0 }).format(n);
  } catch {
    return `${n} MXN`;
  }
}

function val(id) { return document.getElementById(id).value; }

document.getElementById("btnExample").addEventListener("click", () => {
  document.getElementById("tipo").value = "casa";
  document.getElementById("colonia").value = "COL_13";
  document.getElementById("lat").value = "18.8123";
  document.getElementById("lng").value = "-98.9556";
  document.getElementById("m2_terreno").value = "200";
  document.getElementById("m2_construccion").value = "160";
  document.getElementById("recamaras").value = "3";
  document.getElementById("banos").value = "2";
  document.getElementById("estacionamientos").value = "2";
  document.getElementById("antiguedad_anios").value = "8";
});

document.getElementById("btnGeo").addEventListener("click", () => {
  if (!navigator.geolocation) {
    alert("Tu navegador no soporta geolocalización.");
    return;
  }
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      document.getElementById("lat").value = pos.coords.latitude.toFixed(6);
      document.getElementById("lng").value = pos.coords.longitude.toFixed(6);
    },
    (err) => alert("No se pudo obtener ubicación: " + err.message),
    { enableHighAccuracy: true, timeout: 10000 }
  );
});

document.getElementById("btnPredict").addEventListener("click", async () => {
  const payload = {
    tipo: val("tipo"),
    colonia: val("colonia"),
    lat: Number(val("lat")),
    lng: Number(val("lng")),
    m2_terreno: Number(val("m2_terreno")),
    m2_construccion: Number(val("m2_construccion")),
    recamaras: Number(val("recamaras")),
    banos: Number(val("banos")),
    estacionamientos: Number(val("estacionamientos")),
    antiguedad_anios: Number(val("antiguedad_anios")),
  };

  try {
    const data = await postJSON("/predict", payload);

    document.getElementById("resultCard").style.display = "block";
    document.getElementById("precio").textContent = moneyMXN(data.precio_estimado);
    document.getElementById("meta").textContent =
      `Zona: ${data.zona_inferida} | Colonia: ${data.colonia} | cache_hit: ${data.pois?.cache_hit}`;

    document.getElementById("pois").textContent = JSON.stringify(data.pois, null, 2);
    document.getElementById("raw").textContent = JSON.stringify(data, null, 2);
  } catch (e) {
    alert(e.message);
  }
});
