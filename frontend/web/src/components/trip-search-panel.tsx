"use client";

import { FormEvent, useEffect, useMemo, useState } from "react";
import { platform } from "@/lib/platform";

type Terminal = { id: number; name: string; city: string; country: string };
type TripType = "one_way" | "return" | "multi_city" | "connecting";
type SearchLeg = { origin: string; destination: string; date: string };
type SavedSearch = { id: string; label: string; tripType: TripType; legs: SearchLeg[]; passengers: PassengerCounts; accessible: boolean; flexible: boolean; crossBorder: boolean };
type PassengerCounts = { adults: number; children: number; infants: number; students: number };

const tomorrow = new Date(Date.now() + 86400000).toISOString().slice(0, 10);
const storageKeys = { recent: "mufambi.recent-searches", saved: "mufambi.saved-searches" };

export function TripSearchPanel({ terminals, sort, onSortChange, onResults, onMessage }: {
  terminals: Terminal[];
  sort: string;
  onSortChange: (sort: string) => void;
  onResults: (trips: never[]) => void;
  onMessage: (message: string) => void;
}) {
  const [tripType, setTripType] = useState<TripType>("one_way");
  const [legs, setLegs] = useState<SearchLeg[]>([{ origin: "", destination: "", date: tomorrow }]);
  const [returnDate, setReturnDate] = useState(tomorrow);
  const [passengers, setPassengers] = useState<PassengerCounts>({ adults: 1, children: 0, infants: 0, students: 0 });
  const [accessible, setAccessible] = useState(false);
  const [flexible, setFlexible] = useState(false);
  const [crossBorder, setCrossBorder] = useState(false);
  const [loading, setLoading] = useState(false);
  const [recent, setRecent] = useState<SavedSearch[]>([]);
  const [saved, setSaved] = useState<SavedSearch[]>([]);

  useEffect(() => {
    const timeout = window.setTimeout(() => {
      setRecent(readSearches(storageKeys.recent));
      setSaved(readSearches(storageKeys.saved));
    }, 0);

    return () => window.clearTimeout(timeout);
  }, []);

  const totalPassengers = Object.values(passengers).reduce((total, count) => total + count, 0);
  const selectedOrigin = terminals.find((terminal) => String(terminal.id) === legs[0].origin);
  const selectedDestination = terminals.find((terminal) => String(terminal.id) === legs.at(-1)?.destination);
  const nearby = useMemo(() => terminals.filter((terminal) => terminal.id !== selectedOrigin?.id && (terminal.city === selectedOrigin?.city || terminal.country === selectedOrigin?.country)).slice(0, 3), [selectedOrigin, terminals]);
  const alternatives = useMemo(() => terminals.filter((terminal) => !legs.some((leg) => [leg.origin, leg.destination].includes(String(terminal.id))) && (terminal.country === selectedOrigin?.country || terminal.country === selectedDestination?.country)).slice(0, 4), [legs, selectedDestination, selectedOrigin, terminals]);

  function updateLeg(index: number, changes: Partial<SearchLeg>) {
    setLegs((current) => current.map((leg, legIndex) => legIndex === index ? { ...leg, ...changes } : leg));
  }

  function selectTripType(value: TripType) {
    setTripType(value);
    if (value !== "multi_city") {
      setLegs((current) => [current[0]]);
    }
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    const requestedLegs = tripType === "return" ? [...legs, { origin: legs[0].destination, destination: legs[0].origin, date: returnDate }] : legs;
    if (requestedLegs.some((leg) => !leg.origin || !leg.destination || !leg.date)) {
      onMessage("Complete every city, terminal, and date before searching.");
      return;
    }
    if (crossBorder && requestedLegs.every((leg) => terminal(terminals, leg.origin)?.country === terminal(terminals, leg.destination)?.country)) {
      onMessage("Cross-border search needs departure and destination terminals in different countries.");
      return;
    }

    setLoading(true);
    onMessage("");
    try {
      const searches = flexible
        ? requestedLegs.flatMap((leg) => dateWindow(leg.date).map((date) => ({ ...leg, date })))
        : requestedLegs;
      const batches = await Promise.all(searches.map((leg) => fetchLeg(leg, sort, totalPassengers)));
      let results = uniqueById(batches.flat());

      if ((tripType === "connecting" || results.length === 0) && requestedLegs.length === 1) {
        const connectionBatches = await Promise.all(alternatives.slice(0, 3).flatMap((stop) => [
          fetchLeg({ origin: requestedLegs[0].origin, destination: String(stop.id), date: requestedLegs[0].date }, sort, totalPassengers),
          fetchLeg({ origin: String(stop.id), destination: requestedLegs[0].destination, date: requestedLegs[0].date }, sort, totalPassengers),
        ]));
        results = uniqueById([...results, ...connectionBatches.flat()]);
      }

      onResults(results as never[]);
      const snapshot = makeSnapshot(tripType, requestedLegs, passengers, accessible, flexible, crossBorder);
      const nextRecent = [snapshot, ...recent.filter((item) => item.label !== snapshot.label)].slice(0, 5);
      setRecent(nextRecent);
      localStorage.setItem(storageKeys.recent, JSON.stringify(nextRecent));
      onMessage(results.length ? `${results.length} trip option${results.length === 1 ? "" : "s"} found${flexible ? " across flexible dates" : ""}.` : "No direct trip matched. Try a nearby terminal or an alternative route below.");
    } catch (error) {
      onMessage(error instanceof Error ? error.message : "Search failed.");
    } finally {
      setLoading(false);
    }
  }

  function saveCurrent() {
    const snapshot = makeSnapshot(tripType, legs, passengers, accessible, flexible, crossBorder);
    const next = [snapshot, ...saved.filter((item) => item.label !== snapshot.label)].slice(0, 10);
    setSaved(next);
    localStorage.setItem(storageKeys.saved, JSON.stringify(next));
    onMessage("Search saved on this device.");
  }

  function applySearch(item: SavedSearch) {
    setTripType(item.tripType);
    setLegs(item.legs);
    setPassengers(item.passengers);
    setAccessible(item.accessible);
    setFlexible(item.flexible);
    setCrossBorder(item.crossBorder);
  }

  return <section id="book-trip" className="mx-auto -mt-12 scroll-mt-6 max-w-7xl px-5 lg:px-8">
    <form onSubmit={submit} className="rounded-3xl bg-white p-5 shadow-xl shadow-slate-900/10 lg:p-7">
      <fieldset><legend className="text-xs font-black uppercase tracking-[.18em] text-slate-500">Journey type</legend><div className="mt-3 flex flex-wrap gap-2">{([['one_way', 'One way'], ['return', 'Return'], ['multi_city', 'Multi-city'], ['connecting', 'Connecting']] as const).map(([value, label]) => <button type="button" aria-pressed={tripType === value} key={value} onClick={() => selectTripType(value)} className={`rounded-full px-4 py-2 text-sm font-bold ${tripType === value ? "bg-[#0c312d] text-white" : "bg-slate-100 text-slate-600"}`}>{label}</button>)}</div></fieldset>

      <div className="mt-6 grid gap-5">{legs.map((leg, index) => <div key={index} className="grid gap-4 rounded-2xl border border-slate-200 p-4 lg:grid-cols-[1fr_1fr_180px_auto]">
        <TerminalPicker label={legs.length > 1 ? `Leg ${index + 1} departure` : "Departure"} value={leg.origin} terminals={terminals} onChange={(origin) => updateLeg(index, { origin })} />
        <TerminalPicker label="Destination" value={leg.destination} terminals={terminals} onChange={(destination) => updateLeg(index, { destination })} />
        <Field label="Departure date"><input required min={tomorrow} type="date" value={leg.date} onChange={(event) => updateLeg(index, { date: event.target.value })} className="input" /></Field>
        {tripType === "multi_city" && legs.length > 1 && <button type="button" onClick={() => setLegs((current) => current.filter((_, legIndex) => legIndex !== index))} className="self-end rounded-xl border border-red-200 px-4 py-3 text-sm font-bold text-red-700">Remove</button>}
      </div>)}</div>

      {tripType === "return" && <div className="mt-4 max-w-xs"><Field label="Return date"><input required min={legs[0].date || tomorrow} type="date" value={returnDate} onChange={(event) => setReturnDate(event.target.value)} className="input" /></Field></div>}
      {tripType === "multi_city" && legs.length < 5 && <button type="button" onClick={() => setLegs((current) => [...current, { origin: current.at(-1)?.destination ?? "", destination: "", date: current.at(-1)?.date ?? tomorrow }])} className="mt-4 rounded-xl border border-[#16796f] px-4 py-3 text-sm font-bold text-[#16796f]">+ Add another city</button>}

      <div className="mt-6 grid gap-4 border-t border-slate-200 pt-6 sm:grid-cols-2 lg:grid-cols-4">
        {([['adults', 'Adults'], ['children', 'Children'], ['infants', 'Infants'], ['students', 'Students']] as const).map(([key, label]) => <Field key={key} label={label}><input aria-label={label} type="number" min={key === "adults" ? 1 : 0} max="10" value={passengers[key]} onChange={(event) => setPassengers({ ...passengers, [key]: Number(event.target.value) })} className="input" /></Field>)}
      </div>
      <div className="mt-5 flex flex-wrap gap-3 text-sm font-semibold"><Check label="Cross-border trip" checked={crossBorder} onChange={setCrossBorder} /><Check label="Flexible dates (±3 days)" checked={flexible} onChange={setFlexible} /><Check label="Accessibility assistance required" checked={accessible} onChange={setAccessible} /></div>
      <div className="mt-6 grid items-end gap-4 md:grid-cols-[220px_1fr_auto_auto]"><Field label="Sort results"><select value={sort} onChange={(event) => onSortChange(event.target.value)} className="input"><option value="departure_asc">Earliest</option><option value="price_asc">Lowest price</option><option value="price_desc">Highest price</option><option value="duration_asc">Shortest</option><option value="availability_desc">Most seats</option></select></Field><p className="text-sm text-slate-500">{totalPassengers} passenger{totalPassengers === 1 ? "" : "s"} · {accessible ? "Assistance requested" : "Standard boarding"}</p><button type="button" onClick={saveCurrent} className="rounded-xl border border-[#0c312d] px-5 py-4 font-bold text-[#0c312d]">Save search</button><button disabled={loading} className="rounded-xl bg-[#ef5b35] px-7 py-4 font-bold text-white disabled:opacity-50">{loading ? "Searching…" : "Find trips"}</button></div>
    </form>

    {(nearby.length > 0 || alternatives.length > 0) && <div className="mt-4 grid gap-4 md:grid-cols-2"><Suggestion title="Nearby departure terminals" items={nearby} apply={(value) => updateLeg(0, { origin: String(value.id) })} /><Suggestion title="Alternative route suggestions" items={alternatives} apply={(value) => updateLeg(legs.length - 1, { destination: String(value.id) })} /></div>}
    {(recent.length > 0 || saved.length > 0) && <div className="mt-4 grid gap-4 md:grid-cols-2"><SearchHistory title="Recent searches" items={recent} apply={applySearch} /><SearchHistory title="Saved searches" items={saved} apply={applySearch} /></div>}
  </section>;
}

function TerminalPicker({ label, value, terminals, onChange }: { label: string; value: string; terminals: Terminal[]; onChange: (value: string) => void }) {
  const [country, setCountry] = useState("");
  const [city, setCity] = useState("");
  const countries = unique(terminals.map((terminal) => terminal.country));
  const cities = unique(terminals.filter((terminal) => !country || terminal.country === country).map((terminal) => terminal.city));
  const options = terminals.filter((terminal) => (!country || terminal.country === country) && (!city || terminal.city === city));
  return <fieldset><legend className="text-xs font-bold uppercase tracking-wider text-slate-500">{label}</legend><div className="mt-2 grid gap-2 sm:grid-cols-3 lg:grid-cols-1 xl:grid-cols-3"><select aria-label={`${label} country`} value={country} onChange={(event) => { setCountry(event.target.value); setCity(""); onChange(""); }} className="input"><option value="">Country</option>{countries.map((item) => <option key={item}>{item}</option>)}</select><select aria-label={`${label} city`} value={city} onChange={(event) => { setCity(event.target.value); onChange(""); }} className="input"><option value="">City</option>{cities.map((item) => <option key={item}>{item}</option>)}</select><select required aria-label={`${label} terminal`} value={value} onChange={(event) => onChange(event.target.value)} className="input"><option value="">Terminal</option>{options.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></div></fieldset>;
}

function Field({ label, children }: { label: string; children: React.ReactNode }) { return <label className="flex flex-col gap-2 text-xs font-bold uppercase tracking-wider text-slate-500">{label}{children}</label>; }
function Check({ label, checked, onChange }: { label: string; checked: boolean; onChange: (checked: boolean) => void }) { return <label className="flex items-center gap-2 rounded-xl bg-slate-100 px-4 py-3"><input type="checkbox" checked={checked} onChange={(event) => onChange(event.target.checked)} className="size-4 accent-[#16796f]" />{label}</label>; }
function Suggestion({ title, items, apply }: { title: string; items: Terminal[]; apply: (item: Terminal) => void }) { return <div className="rounded-2xl bg-[#e5eee8] p-4"><p className="text-xs font-black uppercase tracking-wider text-[#16796f]">{title}</p><div className="mt-3 flex flex-wrap gap-2">{items.map((item) => <button type="button" key={item.id} onClick={() => apply(item)} className="rounded-full bg-white px-3 py-2 text-xs font-bold">{item.city} · {item.name}</button>)}</div></div>; }
function SearchHistory({ title, items, apply }: { title: string; items: SavedSearch[]; apply: (item: SavedSearch) => void }) { return <div className="rounded-2xl border border-slate-200 bg-white p-4"><p className="text-xs font-black uppercase tracking-wider text-slate-500">{title}</p><div className="mt-3 grid gap-2">{items.slice(0, 3).map((item) => <button type="button" key={item.id} onClick={() => apply(item)} className="truncate rounded-xl bg-slate-50 px-3 py-2 text-left text-xs font-semibold">{item.label}</button>)}</div></div>; }

async function fetchLeg(leg: SearchLeg, sort: string, minimumSeats: number): Promise<never[]> { const params = new URLSearchParams({ origin_terminal_id: leg.origin, destination_terminal_id: leg.destination, date: leg.date, sort, minimum_seats: String(minimumSeats) }); const response = await fetch(`${platform.apiUrl}/trips?${params}`); const body = await response.json() as { data?: never[]; message?: string; errors?: Record<string, string[]> }; if (!response.ok) throw new Error(Object.values(body.errors ?? {})[0]?.[0] ?? body.message ?? "Search failed."); return body.data ?? []; }
function terminal(terminals: Terminal[], id: string) { return terminals.find((item) => String(item.id) === id); }
function unique<T>(items: T[]) { return [...new Set(items)]; }
function uniqueById(items: never[]) { return [...new Map(items.map((item) => [(item as { id: number }).id, item])).values()]; }
function dateWindow(date: string) { const base = new Date(`${date}T12:00:00`); return [-3, -2, -1, 0, 1, 2, 3].map((offset) => { const value = new Date(base); value.setDate(value.getDate() + offset); return value.toISOString().slice(0, 10); }).filter((value) => value >= tomorrow); }
function makeSnapshot(tripType: TripType, legs: SearchLeg[], passengers: PassengerCounts, accessible: boolean, flexible: boolean, crossBorder: boolean): SavedSearch { return { id: crypto.randomUUID(), label: `${tripType.replaceAll("_", " ")} · ${legs.map((leg) => `${leg.origin}→${leg.destination}`).join(" · ")} · ${legs[0].date}`, tripType, legs, passengers, accessible, flexible, crossBorder }; }
function readSearches(key: string): SavedSearch[] { try { return JSON.parse(localStorage.getItem(key) ?? "[]"); } catch { return []; } }
