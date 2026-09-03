"use client";

import { FormEvent, useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { platform } from "@/lib/platform";

type Terminal = { id: number; name: string; city: string };
type Trip = { id: number; departs_at: string; arrives_at: string; base_fare: string; currency: string; available_seats: number; duration_minutes: number; operator_rating: number; refund_policy: { code: string; label: string }; company: { id: number; name: string }; bus: { model: string; class: string; amenities: string[] | null }; route: { origin: Terminal; destination: Terminal } };

export default function CompareTripsPage() {
  const [terminals, setTerminals] = useState<Terminal[]>([]);
  const [trips, setTrips] = useState<Trip[]>([]);
  const [selected, setSelected] = useState<number[]>([]);
  const [company, setCompany] = useState("");
  const [message, setMessage] = useState("");

  useEffect(() => { fetch(`${platform.apiUrl}/terminals`).then((response) => response.json()).then((body) => setTerminals(body.data ?? [])).catch(() => setMessage("Backend connection unavailable.")); }, []);
  const companies = useMemo(() => [...new Map(trips.map((trip) => [trip.company.id, trip.company])).values()], [trips]);
  const visibleTrips = company ? trips.filter((trip) => String(trip.company.id) === company) : trips;
  const compared = trips.filter((trip) => selected.includes(trip.id));

  async function search(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const params = new URLSearchParams();
    for (const [key, value] of new FormData(event.currentTarget).entries()) if (String(value)) params.append(key, String(value));
    const response = await fetch(`${platform.apiUrl}/trips?${params}`);
    const body = await response.json();
    if (!response.ok) return setMessage(firstError(body));
    setTrips(body.data ?? []); setSelected([]); setCompany(""); setMessage(body.data?.length ? "" : "No matching trips found.");
  }

  function toggle(id: number) { setSelected((items) => items.includes(id) ? items.filter((item) => item !== id) : items.length < 4 ? [...items, id] : items); }

  return <main className="min-h-screen bg-[#edf1ef] text-slate-950">
    <header className="bg-[#0c312d] px-5 py-12 text-white lg:px-8"><div className="mx-auto max-w-7xl"><p className="text-xs font-black uppercase tracking-[.22em] text-[#ffce54]">Passenger tools</p><h1 className="mt-3 text-5xl font-black">Compare trips side by side</h1><p className="mt-4 text-emerald-50/70">Filter the live inventory and compare up to four journeys.</p></div></header>
    <div className="mx-auto max-w-7xl px-5 py-8 lg:px-8">
      <form onSubmit={search} className="grid gap-4 rounded-3xl bg-white p-6 shadow-sm md:grid-cols-3 lg:grid-cols-4">
        <SelectTerminal label="Departure" name="origin_terminal_id" terminals={terminals}/><SelectTerminal label="Destination" name="destination_terminal_id" terminals={terminals}/>
        <Field label="Travel date"><input required type="date" name="date" className="input"/></Field>
        <Field label="Bus class"><select name="bus_class" className="input"><option value="">Any class</option><option value="standard">Standard</option><option value="executive">Executive</option><option value="luxury">Luxury</option><option value="sleeper">Sleeper</option></select></Field>
        <Field label="Minimum price"><input name="min_price" type="number" min="0" step="0.01" className="input"/></Field><Field label="Maximum price"><input name="max_price" type="number" min="0" step="0.01" className="input"/></Field>
        <Field label="Depart after"><input name="departure_from" type="time" className="input"/></Field><Field label="Depart before"><input name="departure_to" type="time" className="input"/></Field>
        <Field label="Arrive after"><input name="arrival_from" type="time" className="input"/></Field><Field label="Arrive before"><input name="arrival_to" type="time" className="input"/></Field>
        <Field label="Maximum duration"><input name="max_duration" type="number" min="1" className="input" placeholder="Minutes"/></Field><Field label="Minimum rating"><input name="min_rating" type="number" min="0" max="5" step="0.1" className="input"/></Field>
        <Field label="Refund policy"><select name="refund_policy" className="input"><option value="">Any policy</option><option value="flexible">Flexible</option><option value="standard">Standard</option><option value="non_refundable">Non-refundable</option></select></Field>
        <Field label="Seats needed"><input name="minimum_seats" type="number" min="1" max="50" defaultValue="1" className="input"/></Field>
        <Field label="Sort"><select name="sort" className="input"><option value="departure_asc">Earliest departure</option><option value="arrival_asc">Earliest arrival</option><option value="price_asc">Lowest price</option><option value="duration_asc">Shortest journey</option><option value="rating_desc">Best rated</option></select></Field>
        <div className="flex flex-wrap items-center gap-4 rounded-xl border border-slate-200 px-4"><Check value="wifi" label="Wi-Fi"/><Check value="air_conditioning" label="Air conditioning"/><Check value="charging_ports" label="Charging"/></div>
        <button className="rounded-xl bg-[#ef5b35] px-5 py-3 font-black text-white">Search trips</button>
      </form>
      {companies.length > 1 && <Field label="Filter results by company"><select value={company} onChange={(event) => setCompany(event.target.value)} className="input mt-5 max-w-xs"><option value="">All bus companies</option>{companies.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></Field>}
      {message && <p className="mt-5 rounded-2xl bg-amber-50 p-4 text-sm text-amber-950">{message}</p>}
      <section className="mt-7 grid gap-4 md:grid-cols-2 xl:grid-cols-3">{visibleTrips.map((trip) => <TripCard key={trip.id} trip={trip} selected={selected.includes(trip.id)} toggle={() => toggle(trip.id)}/>)}</section>
      {compared.length > 0 && <Comparison trips={compared}/>} 
    </div>
  </main>;
}

function TripCard({ trip, selected, toggle }: { trip: Trip; selected: boolean; toggle: () => void }) { return <article className={`rounded-3xl border-2 bg-white p-6 shadow-sm ${selected ? "border-emerald-700" : "border-transparent"}`}><div className="flex items-start justify-between gap-3"><div><p className="text-sm font-black text-emerald-700">{trip.company.name}</p><h2 className="mt-2 text-2xl font-black">{clock(trip.departs_at)} → {clock(trip.arrives_at)}</h2></div><input aria-label={`Compare ${trip.company.name}`} type="checkbox" checked={selected} onChange={toggle} className="size-5 accent-emerald-700"/></div><p className="mt-3 text-sm text-slate-500">{trip.bus.model} · {trip.bus.class} · {trip.duration_minutes} minutes</p><div className="mt-5 flex items-end justify-between"><p className="text-3xl font-black">{trip.currency} {trip.base_fare}</p><p className="font-black text-amber-600">★ {trip.operator_rating.toFixed(1)}</p></div><p className="mt-1 text-sm text-emerald-700">{trip.available_seats} seats · {trip.refund_policy.label}</p><div className="mt-4 flex flex-wrap gap-2">{trip.bus.amenities?.map((amenity) => <span key={amenity} className="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold">{amenity.replaceAll("_", " ")}</span>)}</div><Link href={`/trips/${trip.id}`} className="mt-5 inline-flex font-black text-emerald-800 underline">View full trip details</Link></article>; }
function Comparison({ trips }: { trips: Trip[] }) { return <section className="mt-8 overflow-x-auto rounded-3xl bg-slate-950 p-6 text-white"><h2 className="text-2xl font-black">Selected comparison</h2><div className="mt-5 grid min-w-[48rem] gap-4" style={{ gridTemplateColumns: `repeat(${trips.length}, minmax(0, 1fr))` }}>{trips.map((trip) => <article key={trip.id} className="rounded-2xl bg-white/5 p-5"><p className="font-black text-[#ffce54]">{trip.company.name}</p><p className="mt-3 text-3xl font-black">{trip.currency} {trip.base_fare}</p><dl className="mt-4 grid gap-2 text-sm text-slate-300"><div>Departure: <strong className="text-white">{clock(trip.departs_at)}</strong></div><div>Arrival: <strong className="text-white">{clock(trip.arrives_at)}</strong></div><div>Duration: <strong className="text-white">{trip.duration_minutes} min</strong></div><div>Rating: <strong className="text-white">{trip.operator_rating.toFixed(1)} / 5</strong></div><div>Amenities: <strong className="text-white">{trip.bus.amenities?.join(", ") || "None listed"}</strong></div><div>Refunds: <strong className="text-white">{trip.refund_policy.label}</strong></div></dl><Link href="/book" className="mt-5 block rounded-xl bg-[#ef5b35] px-4 py-3 text-center font-black">Select this trip</Link></article>)}</div></section>; }
function SelectTerminal({ label, name, terminals }: { label: string; name: string; terminals: Terminal[] }) { return <Field label={label}><select required name={name} className="input"><option value="">Choose terminal</option>{terminals.map((terminal) => <option key={terminal.id} value={terminal.id}>{terminal.city} · {terminal.name}</option>)}</select></Field>; }
function Field({ label, children }: { label: string; children: React.ReactNode }) { return <label className="grid gap-2 text-xs font-bold uppercase tracking-wider text-slate-500">{label}{children}</label>; }
function Check({ value, label }: { value: string; label: string }) { return <label className="flex items-center gap-2 text-xs font-bold"><input type="checkbox" name="amenities[]" value={value}/>{label}</label>; }
function clock(value: string) { return new Intl.DateTimeFormat(undefined, { hour: "2-digit", minute: "2-digit" }).format(new Date(value)); }
function firstError(body: { message?: string; errors?: Record<string, string[]> }) { return Object.values(body.errors ?? {})[0]?.[0] ?? body.message ?? "Search failed."; }
