import { MapContainer, Marker, Popup, TileLayer } from 'react-leaflet';
import L from 'leaflet';

// Brand-coloured SVG marker (no external marker images needed).
const icon = L.divIcon({
  className: '',
  html: `<svg width="34" height="44" viewBox="0 0 48 60" xmlns="http://www.w3.org/2000/svg"><path d="M24 2C12 2 3 11 3 22.5 3 38 24 58 24 58s21-20 21-35.5C45 11 36 2 24 2z" fill="#0E6B7B" stroke="#fff" stroke-width="3"/><circle cx="24" cy="22" r="8" fill="#fff"/><circle cx="24" cy="22" r="4" fill="#F2603F"/></svg>`,
  iconSize: [34, 44],
  iconAnchor: [17, 44],
  popupAnchor: [0, -40],
});

export interface MapPoint { lat: number; lng: number; label: string; sub?: string }

/** Free OpenStreetMap tiles via Leaflet — no API key or billing required. */
export default function MapView({ points, zoom = 13, className = 'h-72' }: { points: MapPoint[]; zoom?: number; className?: string }) {
  const valid = points.filter((p) => Number.isFinite(p.lat) && Number.isFinite(p.lng));
  if (!valid.length) return null;
  const center: [number, number] = [valid[0]!.lat, valid[0]!.lng];
  return (
    <div className={className} role="region" aria-label="Map">
      <MapContainer center={center} zoom={zoom} scrollWheelZoom={false} className="h-full w-full">
        <TileLayer attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors' url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png" />
        {valid.map((p, i) => (
          <Marker key={i} position={[p.lat, p.lng]} icon={icon}>
            <Popup><strong>{p.label}</strong>{p.sub && <><br />{p.sub}</>}</Popup>
          </Marker>
        ))}
      </MapContainer>
    </div>
  );
}
