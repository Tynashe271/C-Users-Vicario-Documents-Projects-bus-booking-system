"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { platform } from "@/lib/platform";
import { readAccessToken } from "@/lib/auth-token";

type Dashboard = { companies: { total: number; approved: number; pending: number; suspended: number }; passengers: number; buses: number; trips: { active: number; completed: number; cancelled: number }; bookings: number; ticket_revenue: number; platform_commission: number; pending_refunds: number; pending_settlements: number; open_support_cases: number; recent_activities: Array<{ id: number; name: string; created_at: string }> };

const management = [
  ["/admin/operators", "Operator approvals", "Review documents, approve, suspend and reactivate companies"],
  ["/admin/monitor", "Platform monitoring", "Passengers, staff, buses, trips, bookings and payments"],
  ["/operator/finance", "Settlements", "Review commission, refunds, reconciliation and payouts"],
] as const;

export default function AdminPage() {
  const [dashboard, setDashboard] = useState<Dashboard | null>(null); const [message, setMessage] = useState("");
  useEffect(() => { fetch(`${platform.apiUrl}/admin/dashboard`, { headers: { Accept: "application/json", Authorization: `Bearer ${readAccessToken()}` } }).then(async (response) => { const body = await response.json(); if (!response.ok) throw new Error(body.message ?? "Administrator access is required."); setDashboard(body); }).catch((error) => setMessage(error.message)); }, []);
  const metrics = dashboard ? [
    ["Companies", dashboard.companies.total], ["Approved", dashboard.companies.approved], ["Pending applications", dashboard.companies.pending], ["Suspended", dashboard.companies.suspended],
    ["Passengers", dashboard.passengers], ["Buses", dashboard.buses], ["Active trips", dashboard.trips.active], ["Completed trips", dashboard.trips.completed],
    ["Cancelled trips", dashboard.trips.cancelled], ["Bookings", dashboard.bookings], ["Pending refunds", dashboard.pending_refunds], ["Open support cases", dashboard.open_support_cases],
  ] : [];
  return <main className="min-h-screen bg-[#edf1ef] text-slate-950"><header className="bg-[#0c312d] px-5 py-12 text-white lg:px-8"><div className="mx-auto max-w-7xl"><p className="text-xs font-black uppercase tracking-[.24em] text-[#ffce54]">Platform control centre</p><h1 className="mt-3 text-5xl font-black tracking-tight">Super Admin dashboard</h1><p className="mt-4 max-w-2xl text-emerald-50/70">Platform-wide companies, trips, bookings, finance, support and recent activity from the Laravel backend.</p></div></header><div className="mx-auto max-w-7xl px-5 py-8 lg:px-8">
    {message && <p role="alert" className="rounded-2xl bg-red-50 p-4 text-sm text-red-900">{message}</p>}
    <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">{metrics.map(([label, value]) => <article key={label} className="rounded-3xl bg-white p-6 shadow-sm"><p className="text-xs font-bold uppercase tracking-wider text-slate-500">{label}</p><p className="mt-3 text-4xl font-black">{value}</p></article>)}</section>
    <section className="mt-6 grid gap-4 md:grid-cols-3"><Money label="Ticket revenue" value={dashboard?.ticket_revenue} /><Money label="Platform commission" value={dashboard?.platform_commission} /><article className="rounded-3xl bg-amber-100 p-6"><p className="text-xs font-bold uppercase text-amber-800">Pending settlements</p><p className="mt-3 text-4xl font-black text-amber-950">{dashboard?.pending_settlements ?? 0}</p></article></section>
    <section className="mt-8 grid gap-5 lg:grid-cols-[1fr_.8fr]"><div className="rounded-3xl bg-white p-6 shadow-sm"><h2 className="text-2xl font-black">Management areas</h2><div className="mt-5 grid gap-3">{management.map(([href, label, description]) => <Link key={href} href={href} className="rounded-2xl border border-slate-200 p-5 transition hover:border-emerald-700 hover:bg-emerald-50"><span className="font-black">{label}</span><span className="mt-1 block text-sm text-slate-500">{description}</span></Link>)}</div></div><div className="rounded-3xl bg-slate-950 p-6 text-white"><h2 className="text-2xl font-black">Recent system activity</h2><div className="mt-5 grid gap-4">{dashboard?.recent_activities.map((activity) => <article key={activity.id} className="border-b border-white/10 pb-4"><p className="text-sm font-bold">{activity.name}</p><p className="mt-1 text-xs text-slate-400">{new Date(activity.created_at).toLocaleString()}</p></article>)}{!dashboard?.recent_activities.length && <p className="text-sm text-slate-400">No recent audited activity.</p>}</div></div></section>
  </div></main>;
}

function Money({ label, value = 0 }: { label: string; value?: number }) { return <article className="rounded-3xl bg-[#0c312d] p-6 text-white"><p className="text-xs font-bold uppercase text-emerald-200">{label}</p><p className="mt-3 text-4xl font-black">${Number(value).toFixed(2)}</p></article>; }
