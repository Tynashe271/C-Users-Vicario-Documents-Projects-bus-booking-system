"use client";

import { FormEvent, useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { platform } from "@/lib/platform";
import { readAccessToken, storeAccessToken } from "@/lib/auth-token";

type ModuleDefinition = { key: string; label: string; description: string };
type Resource = { id: number; code: string | null; name: string | null; status: string; amount: string | null; currency: string | null; created_at: string };

export function ModuleWorkspace({ eyebrow, title, description, modules }: { eyebrow: string; title: string; description: string; modules: ModuleDefinition[] }) {
  const [token, setToken] = useState(readAccessToken);
  const [active, setActive] = useState(modules[0].key);
  const [records, setRecords] = useState<Resource[]>([]);
  const [message, setMessage] = useState("");
  const [loading, setLoading] = useState(false);
  const activeModule = modules.find((item) => item.key === active) ?? modules[0];
  const headers = useCallback(() => ({ Accept: "application/json", "Content-Type": "application/json", Authorization: `Bearer ${token}` }), [token]);

  const load = useCallback(async () => {
    if (!token) return;
    setLoading(true); setMessage("");
    try {
      const response = await fetch(`${platform.apiUrl}/modules/${active}/records`, { headers: headers() });
      const body = await response.json();
      if (!response.ok) throw new Error(body.message ?? "This module is not available for your role.");
      setRecords(body.data ?? []);
    } catch (error) { setRecords([]); setMessage(error instanceof Error ? error.message : "Unable to load this module."); }
    finally { setLoading(false); }
  }, [active, headers, token]);

  useEffect(() => { queueMicrotask(load); }, [load]);

  async function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const response = await fetch(`${platform.apiUrl}/modules/${active}/records`, { method: "POST", headers: headers(), body: JSON.stringify({ name: form.get("name"), code: form.get("code") || null, status: form.get("status") }) });
    const body = await response.json();
    setMessage(response.ok ? `${activeModule.label} record created.` : body.message ?? "Unable to create record.");
    if (response.ok) { event.currentTarget.reset(); await load(); }
  }

  function saveToken(value: string) { setToken(value); storeAccessToken(value); }

  return <main className="min-h-screen bg-[#edf1ef] text-slate-950">
    <header className="bg-[#0c312d] text-white"><div className="mx-auto flex max-w-7xl flex-wrap items-end justify-between gap-6 px-5 py-9 lg:px-8"><div><p className="text-xs font-black uppercase tracking-[.24em] text-[#ffce54]">{eyebrow}</p><h1 className="mt-2 text-4xl font-black tracking-tight md:text-5xl">{title}</h1><p className="mt-3 max-w-2xl text-sm leading-6 text-emerald-50/70">{description}</p></div><Link href="/home" className="rounded-full border border-white/20 px-5 py-3 text-sm font-bold hover:bg-white hover:text-[#0c312d]">Public home</Link></div></header>
    <div className="mx-auto grid max-w-7xl gap-6 px-5 py-7 lg:grid-cols-[18rem_1fr] lg:px-8">
      <aside className="rounded-3xl bg-white p-4 shadow-sm"><label className="grid gap-2 px-2 pb-4 text-xs font-bold uppercase tracking-wider text-slate-500">Access token<input type="password" value={token} onChange={(event) => saveToken(event.target.value)} className="input" placeholder="Paste secure API token" /></label><nav className="grid gap-1">{modules.map((item) => <button key={item.key} onClick={() => setActive(item.key)} className={`rounded-2xl px-4 py-3 text-left transition ${active === item.key ? "bg-[#0c312d] text-white" : "hover:bg-slate-100"}`}><span className="block text-sm font-black">{item.label}</span><span className={`mt-1 block text-xs ${active === item.key ? "text-emerald-100/70" : "text-slate-500"}`}>{item.description}</span></button>)}</nav></aside>
      <section className="grid content-start gap-6"><div className="rounded-3xl bg-white p-6 shadow-sm"><div className="flex flex-wrap items-start justify-between gap-4"><div><p className="text-xs font-bold uppercase tracking-widest text-emerald-700">Active module</p><h2 className="mt-2 text-3xl font-black">{activeModule.label}</h2><p className="mt-2 text-sm text-slate-500">{activeModule.description}</p></div><button onClick={load} className="rounded-xl bg-slate-950 px-4 py-3 text-sm font-bold text-white">{loading ? "Loading…" : "Refresh"}</button></div>{message && <p role="status" className="mt-5 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900">{message}</p>}</div>
        <form onSubmit={create} className="grid gap-4 rounded-3xl bg-white p-6 shadow-sm md:grid-cols-[1fr_12rem_10rem_auto]"><input required name="name" className="input" placeholder={`${activeModule.label} name`} /><input name="code" className="input" placeholder="Reference code" /><select name="status" className="input"><option value="active">Active</option><option value="pending">Pending</option><option value="draft">Draft</option><option value="suspended">Suspended</option></select><button className="rounded-xl bg-[#ef5b35] px-5 py-3 font-black text-white">Add record</button></form>
        <div className="overflow-hidden rounded-3xl bg-white shadow-sm"><div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="bg-slate-50 text-xs uppercase tracking-wider text-slate-500"><tr><th className="p-4">Name</th><th className="p-4">Code</th><th className="p-4">Status</th><th className="p-4">Created</th></tr></thead><tbody>{records.map((record) => <tr key={record.id} className="border-t border-slate-100"><td className="p-4 font-bold">{record.name ?? `Record #${record.id}`}</td><td className="p-4 text-slate-500">{record.code ?? "—"}</td><td className="p-4"><span className="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-800">{record.status}</span></td><td className="p-4 text-slate-500">{new Date(record.created_at).toLocaleDateString()}</td></tr>)}</tbody></table></div>{!records.length && <p className="p-12 text-center text-sm text-slate-500">{token ? "No records in this workspace yet." : "Add an access token to load authorised records."}</p>}</div>
      </section>
    </div>
  </main>;
}
