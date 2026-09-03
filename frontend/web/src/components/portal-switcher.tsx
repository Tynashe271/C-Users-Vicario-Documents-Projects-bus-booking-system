"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { usePathname, useRouter } from "next/navigation";
import { hasGuestSession, readAccessToken } from "@/lib/auth-token";
import { platform } from "@/lib/platform";

const portals = [
  ["/home", "Public home"], ["/compare", "Compare trips"], ["/account", "Passenger account"], ["/parcels", "Parcels"],
  ["/operator", "Company operations"], ["/operator/apply", "Company application"], ["/operator/fleet", "Fleet"], ["/operator/staff", "Staff"], ["/operator/finance", "Finance"],
  ["/agent", "Booking agents"], ["/driver/operations", "Driver & conductor"], ["/driver", "Live GPS"], ["/terminal", "Boarding terminal"],
  ["/admin", "Platform administration"], ["/admin/operators", "Operator approvals"], ["/admin/monitor", "Platform monitoring"],
] as const;

export function PortalSwitcher() {
  const pathname = usePathname();
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [connected, setConnected] = useState<boolean | null>(null);
  const [hasAccess, setHasAccess] = useState(false);

  useEffect(() => {
    queueMicrotask(() => setHasAccess(Boolean(readAccessToken()) || hasGuestSession()));
  }, [pathname]);

  useEffect(() => {
    fetch(`${platform.apiUrl}/terminals`, { headers: { Accept: "application/json" } })
      .then((response) => setConnected(response.ok))
      .catch(() => setConnected(false));
  }, []);

  function togglePortals() {
    if (!hasAccess) {
      router.push(`/login?next=${encodeURIComponent("/home")}`);
      return;
    }

    setOpen((value) => !value);
  }

  return <div className="fixed bottom-5 left-5 z-50">
    {open && <div className="mb-3 max-h-[70vh] w-[min(22rem,calc(100vw-2.5rem))] overflow-y-auto rounded-3xl border border-white/10 bg-slate-950 p-3 text-white shadow-2xl shadow-slate-950/35">
      <div className="flex items-center justify-between gap-4 px-3 py-3"><div><p className="text-xs font-black uppercase tracking-[.18em] text-[#ffce54]">Mufambi system</p><p className="mt-1 text-sm text-slate-300">All website workspaces</p></div><span className={`rounded-full px-3 py-1 text-xs font-bold ${connected ? "bg-emerald-400/15 text-emerald-300" : connected === false ? "bg-red-400/15 text-red-300" : "bg-white/10 text-slate-300"}`}>{connected ? "Backend online" : connected === false ? "Backend offline" : "Checking…"}</span></div>
      <nav className="grid gap-1 sm:grid-cols-2">{portals.map(([href, label]) => <Link key={href} href={href} onClick={() => setOpen(false)} className="rounded-xl px-3 py-3 text-sm font-bold text-slate-200 transition hover:bg-white/10 hover:text-white">{label}</Link>)}</nav>
    </div>}
    <button type="button" onClick={togglePortals} aria-expanded={open} className="rounded-full bg-[#ef5b35] px-5 py-3 text-sm font-black text-white shadow-xl shadow-slate-950/25 transition hover:bg-[#d94824]">{open ? "Close portals" : "All portals"}</button>
  </div>;
}
