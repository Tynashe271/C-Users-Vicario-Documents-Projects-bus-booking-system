"use client";

import { FormEvent, useCallback, useEffect, useState } from "react";
import { platform } from "@/lib/platform";

type Action = "check_in" | "board" | "absent";
type PendingScan = { id: string; code: string; action: Action; offline_recorded_at: string; device_id: string };
type Passenger = { ticket_number: string; booking_reference: string; passenger_name: string; seat_number: string; payment_status: string; checked_in_at: string | null; boarded_at: string | null; absent_at: string | null };
type Manifest = { counts: { passengers: number; checked_in: number; boarded: number; absent: number }; passengers: Passenger[] };
const queueKey = "mufambi-terminal-scans";

export default function TerminalPage() {
  const [token, setToken] = useState(() => typeof window === "undefined" ? "" : localStorage.getItem("mufambi-token") ?? ""); const [tripId, setTripId] = useState(""); const [code, setCode] = useState(""); const [action, setAction] = useState<Action>("check_in");
  const [manifest, setManifest] = useState<Manifest | null>(null); const [pending, setPending] = useState(() => typeof window === "undefined" ? [] : readQueue()); const [message, setMessage] = useState(""); const [busy, setBusy] = useState(false);
  const headers = useCallback(() => ({ Accept: "application/json", "Content-Type": "application/json", Authorization: `Bearer ${token}` }), [token]);

  const loadManifest = useCallback(async () => {
    if (!tripId || !token) return;
    const response = await fetch(`${platform.apiUrl}/trips/${tripId}/manifest`, { headers: headers() }); const body = await response.json();
    if (!response.ok) throw new Error(errorMessage(body)); setManifest(body);
  }, [headers, token, tripId]);

  const sync = useCallback(async () => {
    if (!token || !navigator.onLine) return;
    const queued = readQueue(); const remaining: PendingScan[] = [];
    for (const scan of queued) {
      try { const response = await fetch(`${platform.apiUrl}/boarding/scans`, { method: "POST", headers: headers(), body: JSON.stringify(scan) }); if (!response.ok && response.status !== 409) remaining.push(scan); }
      catch { remaining.push(scan); }
    }
    writeQueue(remaining); setPending(remaining); if (!remaining.length && queued.length) setMessage(`${queued.length} offline scan${queued.length === 1 ? "" : "s"} synchronized.`);
  }, [headers, token]);

  useEffect(() => { window.addEventListener("online", sync); return () => window.removeEventListener("online", sync); }, [sync]);

  async function submit(event: FormEvent) {
    event.preventDefault(); setBusy(true); setMessage("");
    const scan: PendingScan = { id: crypto.randomUUID(), code: code.trim(), action, offline_recorded_at: new Date().toISOString(), device_id: localStorage.getItem("mufambi-device") ?? "terminal-web" };
    try {
      if (!navigator.onLine) throw new Error("offline");
      const response = await fetch(`${platform.apiUrl}/boarding/scans`, { method: "POST", headers: headers(), body: JSON.stringify(scan) }); const body = await response.json();
      if (!response.ok) throw new Error(errorMessage(body)); setMessage(`${body.passenger_name}, seat ${body.seat_number}: ${label(action)} recorded.`); setCode(""); await loadManifest();
    } catch (error) {
      if (error instanceof Error && error.message !== "offline") setMessage(error.message);
      else { const queued = [...readQueue(), scan]; writeQueue(queued); setPending(queued); setMessage("No connection. Scan saved safely on this device."); setCode(""); }
    } finally { setBusy(false); }
  }

  function saveToken(value: string) { setToken(value); localStorage.setItem("mufambi-token", value); }

  return <main className="min-h-screen bg-slate-950 px-5 py-8 text-white"><div className="mx-auto max-w-6xl">
    <header className="flex flex-wrap items-center justify-between gap-4 border-b border-white/10 pb-6"><div><p className="text-sm font-bold uppercase tracking-[.2em] text-emerald-400">Mufambi Operations</p><h1 className="mt-2 text-3xl font-black">Terminal boarding desk</h1></div><div className={`rounded-full px-4 py-2 text-sm font-bold ${pending.length ? "bg-amber-400 text-amber-950" : "bg-emerald-400/15 text-emerald-300"}`}>{pending.length ? `${pending.length} waiting to sync` : "All scans synced"}</div></header>
    <section className="mt-8 grid gap-6 lg:grid-cols-[380px_1fr]"><div className="flex flex-col gap-5"><div className="rounded-3xl bg-white/5 p-5"><label className="text-xs font-bold uppercase tracking-wider text-slate-400">Staff access token<input type="password" value={token} onChange={(e) => saveToken(e.target.value)} className="mt-2 w-full rounded-xl border border-white/10 bg-slate-900 p-3 text-white outline-none focus:border-emerald-400" /></label><div className="mt-4 flex gap-3"><input inputMode="numeric" placeholder="Trip ID" value={tripId} onChange={(e) => setTripId(e.target.value)} className="min-w-0 flex-1 rounded-xl border border-white/10 bg-slate-900 p-3 outline-none" /><button onClick={() => loadManifest().catch((error) => setMessage(error.message))} className="rounded-xl bg-white px-4 font-bold text-slate-950">Load</button></div></div>
      <form onSubmit={submit} className="rounded-3xl bg-emerald-950 p-6"><p className="text-xs font-bold uppercase tracking-widest text-emerald-300">Scan ticket</p><input autoFocus required placeholder="QR code or ticket number" value={code} onChange={(e) => setCode(e.target.value)} className="mt-4 w-full rounded-xl border border-emerald-700 bg-slate-950 p-4 text-lg outline-none focus:border-emerald-300" /><div className="mt-4 grid grid-cols-3 gap-2">{(["check_in", "board", "absent"] as Action[]).map((value) => <button type="button" key={value} onClick={() => setAction(value)} className={`rounded-xl p-3 text-xs font-bold ${action === value ? "bg-[#ef5b35] text-white" : "bg-white/10 text-emerald-100"}`}>{label(value)}</button>)}</div><button disabled={busy || !token} className="mt-4 w-full rounded-xl bg-emerald-400 p-4 font-black text-emerald-950 disabled:opacity-40">{busy ? "Recording…" : "Record scan"}</button></form>
      {message && <div className="rounded-2xl border border-white/10 bg-white/5 p-4 text-sm">{message}</div>} {pending.length > 0 && <button onClick={sync} className="rounded-xl border border-amber-400 p-3 text-sm font-bold text-amber-300">Sync offline scans now</button>}</div>
      <div className="overflow-hidden rounded-3xl bg-white text-slate-950"><div className="grid grid-cols-4 gap-px bg-slate-200">{(["passengers", "checked_in", "boarded", "absent"] as const).map((key) => <div key={key} className="bg-white p-5"><p className="text-xs uppercase text-slate-500">{key.replace("_", " ")}</p><strong className="text-3xl">{manifest?.counts[key] ?? 0}</strong></div>)}</div><div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="bg-slate-50 text-xs uppercase text-slate-500"><tr><th className="p-4">Passenger</th><th className="p-4">Seat</th><th className="p-4">Ticket</th><th className="p-4">Status</th></tr></thead><tbody>{manifest?.passengers.map((passenger) => <tr key={passenger.ticket_number} className="border-t border-slate-100"><td className="p-4 font-bold">{passenger.passenger_name}<span className="block text-xs font-normal text-slate-500">{passenger.booking_reference}</span></td><td className="p-4 text-xl font-black">{passenger.seat_number}</td><td className="p-4 font-mono text-xs">{passenger.ticket_number}</td><td className="p-4"><Status passenger={passenger} /></td></tr>)}</tbody></table>{!manifest && <p className="p-12 text-center text-slate-500">Enter a trip ID to load its passenger manifest.</p>}</div></div>
    </section></div></main>;
}

function Status({ passenger }: { passenger: Passenger }) { const value = passenger.absent_at ? "Absent" : passenger.boarded_at ? "Boarded" : passenger.checked_in_at ? "Checked in" : "Expected"; return <span className="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-800">{value}</span>; }
function label(action: Action) { return action === "check_in" ? "Check in" : action === "board" ? "Board" : "Mark absent"; }
function readQueue(): PendingScan[] { try { return JSON.parse(localStorage.getItem(queueKey) ?? "[]"); } catch { return []; } }
function writeQueue(scans: PendingScan[]) { localStorage.setItem(queueKey, JSON.stringify(scans)); }
function errorMessage(body: { message?: string; errors?: Record<string, string[]> }) { return Object.values(body.errors ?? {})[0]?.[0] ?? body.message ?? "Request failed."; }
